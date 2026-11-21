<?php

namespace App\Services;

use App\Enums\BenefitPeriodStatus;
use App\Enums\JustificationHistoryAction;
use App\Enums\PunchKind;
use App\Enums\TimesheetSnapshotSource;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\Point;
use App\Models\PointJustification;
use App\Models\PointJustificationHistory;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registro de justificativas do controle de ponto, com efeito imediato.
 *
 * Fluxo de `register()`, tudo em uma transação (qualquer falha desfaz justificativa,
 * batida manual e histórico):
 * 1. Gate `manage-point-justifications` (antes de qualquer leitura).
 * 2. Bloqueio compartilhado das competências da data e da seguinte (`lockCompetences`, F6.2d)
 *    e só então do funcionário (`lockForUpdate`): serializa os registros do mesmo
 *    funcionário, inclusive a inclusão de batida pelo ManualPointRegistrar, na mesma ordem
 *    do fechamento (competência → funcionário).
 * 3. Idempotência: `request_uuid` já gravado devolve a justificativa existente quando o
 *    conteúdo é o mesmo e recusa quando é diferente. Corrida entre funcionários distintos
 *    com o mesmo identificador cai no UNIQUE do banco e é tratada da mesma forma.
 * 4. Revalidação completa pelo PointJustificationValidator (motivo ativo, tratamento,
 *    horários, abono, competência fechada).
 * 5. Sobreposição com justificativas ativas, em datas e horários reais, como intervalos
 *    [início, fim): dia inteiro ocupa a data; marcação única ocupa o seu minuto.
 * 6. Competência fechada relida com `sharedLock` imediatamente antes da gravação.
 * 7. Motivo com `creates_manual_point`: classificação pela sequência cronológica do dia
 *    (PunchSequence) e criação da batida pelo ManualPointRegistrar.
 * 8. Justificativa (com `manual_point_id`) e histórico append-only.
 *
 * `update()` e `cancel()` (F4), também transacionais, bloqueiam a justificativa, as
 * competências atingidas e o funcionário (nessa ordem, F6.2d) e conferem a `version` recebida pelo
 * formulário: divergência é recusada sem sobrescrever a alteração concorrente.
 * - Edição: revalidação completa, sobreposição (sem contar a própria justificativa),
 *   competências anterior e nova, sequência cronológica e, havendo marcação vinculada,
 *   movimentação controlada pelo ManualPointRegistrar. Trocar um motivo que criou
 *   marcação por um que não cria é recusado (a remoção só acontece no cancelamento).
 * - Cancelamento: motivo obrigatório; a marcação manual vinculada é removida depois do
 *   retrato no histórico, salvo se protegida pelo RH, caso em que nada é cancelado. A
 *   justificativa é marcada como cancelada antes da remoção: a batida vinculada a uma
 *   justificativa ativa não pode ser excluída (Point::deleting).
 *
 * Snapshots do espelho (F6.2b, TimesheetSnapshotRecorder), na mesma transação: quando a
 * operação atinge dia de competência fechada — a data da justificativa e, com término no
 * dia seguinte, a data seguinte; na edição, as datas anteriores e as novas — o dia é
 * capturado antes da alteração e de novo depois do histórico, e os dois são gravados
 * (`before_change`, `after_change`) com o mesmo `operation_uuid`, ligados ao registro
 * principal do histórico e à competência. Competência aberta não gera snapshot. A
 * permissão de competência fechada continua exigida só pela competência da data da
 * justificativa (regra anterior); a data seguinte em outra competência fechada ganha
 * snapshot sem nova exigência.
 *
 * Ponto de partida tardio (F6.2c): dia de competência fechada sem `closure_baseline` nem
 * `late_baseline` (competência fechada antes da implantação, ou funcionário fora do
 * fechamento) recebe `late_baseline` com o estado capturado antes da alteração, gravado
 * antes do `before_change`, uma única vez por funcionário e dia.
 *
 * Nunca altera batidas importadas nem dados de benefícios.
 */
class PointJustificationService
{
    public const REQUEST_CONFLICT_MESSAGE = 'Este identificador de operação já foi usado para outro lançamento. Abra o formulário novamente.';

    public const VERSION_CONFLICT_MESSAGE = 'A justificativa foi alterada por outro usuário depois que você abriu o formulário. Nada foi gravado: feche e abra novamente para ver a versão atual.';

    public const MAX_CANCELLATION_REASON_LENGTH = 1000;

    /**
     * Rótulos dos campos para a descrição da edição no histórico.
     *
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'reason_id' => 'motivo',
        'occurrence_date' => 'data',
        'start_time' => 'horário inicial',
        'end_time' => 'horário final',
        'ends_next_day' => 'término no dia seguinte',
        'punch_kind' => 'tipo de marcação',
        'treatment' => 'tratamento',
        'allowed_minutes' => 'minutos abonados',
        'observation' => 'observação',
    ];

    /**
     * Campos que identificam o conteúdo de uma justificativa, para comparar reenvios.
     *
     * @var list<string>
     */
    private const IDENTITY_FIELDS = ['employee_id', 'reason_id', 'occurrence_date', 'start_time', 'end_time', 'ends_next_day', 'punch_kind', 'treatment', 'allowed_minutes', 'observation'];

    public function __construct(
        private PointJustificationValidator $validator,
        private ManualPointRegistrar $registrar,
        private PunchSequence $sequence,
        private TimesheetSnapshotRecorder $snapshots,
    ) {}

    /**
     * @param  array<string, mixed>  $input  Campos do formulário e `request_uuid`.
     * @return array{justification: PointJustification, created: bool}
     *
     * @throws ValidationException
     * @throws DomainException
     */
    public function register(Employee $employee, string $date, array $input, User $user): array
    {
        Gate::forUser($user)->authorize('manage-point-justifications');

        try {
            return DB::transaction(fn () => $this->registerLocked($employee, $date, $input, $user));
        } catch (UniqueConstraintViolationException $exception) {
            $existing = PointJustification::query()->where('request_uuid', (string) ($input['request_uuid'] ?? ''))->first();

            if ($existing === null) {
                throw $exception;
            }

            return $this->replay($existing, $employee, $date, $input, $user);
        }
    }

    /**
     * Edita uma justificativa ativa. `$input` traz os campos do formulário e, opcionalmente,
     * `occurrence_date` (padrão: a data atual da justificativa).
     *
     * @param  array<string, mixed>  $input
     * @return array{justification: PointJustification, changed: bool}
     *
     * @throws ValidationException
     * @throws DomainException
     */
    public function update(PointJustification $justification, array $input, int $expectedVersion, User $user): array
    {
        Gate::forUser($user)->authorize('manage-point-justifications');

        return DB::transaction(fn () => $this->updateLocked($justification, $input, $expectedVersion, $user));
    }

    /**
     * Cancela uma justificativa ativa (cancelamento lógico), removendo a marcação manual
     * vinculada quando ela não estiver protegida.
     *
     * @throws ValidationException
     * @throws DomainException
     */
    public function cancel(PointJustification $justification, ?string $reason, int $expectedVersion, User $user): PointJustification
    {
        Gate::forUser($user)->authorize('manage-point-justifications');

        $reason = trim((string) $reason);

        Validator::make(['cancellation_reason' => $reason], [
            'cancellation_reason' => ['required', 'string', 'max:'.self::MAX_CANCELLATION_REASON_LENGTH],
        ], [
            'cancellation_reason.required' => 'Informe o motivo do cancelamento.',
            'cancellation_reason.max' => 'O motivo do cancelamento deve ter no máximo '.self::MAX_CANCELLATION_REASON_LENGTH.' caracteres.',
        ])->validate();

        return DB::transaction(fn () => $this->cancelLocked($justification, $reason, $expectedVersion, $user));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{justification: PointJustification, changed: bool}
     */
    private function updateLocked(PointJustification $justification, array $input, int $expectedVersion, User $user): array
    {
        [$employee, $current] = $this->lockForChange($justification, $expectedVersion, 'Esta justificativa está cancelada e não pode ser alterada.', [(string) ($input['occurrence_date'] ?? '')]);

        $oldDate = $current->occurrence_date->toDateString();
        $date = (string) ($input['occurrence_date'] ?? $oldDate);

        $validated = $this->validator->validate($employee, $date, [...$input, 'request_uuid' => $current->request_uuid], $user, checkRequestUuid: false, keptReasonId: (int) $current->reason_id);
        $attributes = collect($validated['attributes'])->except(['employee_id', 'request_uuid'])->all();
        $reason = $validated['reason'];

        $currentValues = [...$current->getAttributes(), 'occurrence_date' => $oldDate];

        if ($this->identity([...$attributes, 'employee_id' => $current->employee_id]) === $this->identity($currentValues)) {
            return ['justification' => $current->load(['reason', 'manualPoint']), 'changed' => false];
        }

        $this->ensureNoOverlap($employee, $attributes, (int) $current->getKey());

        $oldCompetence = $this->competenceOf($oldDate);
        $newCompetence = $this->competenceOf($attributes['occurrence_date']);
        $oldClosed = $this->lockedClosedPeriod($oldCompetence, $user);
        $newClosed = $oldCompetence->equalTo($newCompetence) ? $oldClosed : $this->lockedClosedPeriod($newCompetence, $user);

        $before = $current->getAttributes();
        $pointBefore = $this->pointSnapshot($current->manual_point_id);
        $linkedPoint = $current->manual_point_id === null ? null : Point::query()->find($current->manual_point_id);
        $snapshotsBefore = $this->captureClosedDays($employee, [
            ...$this->affectedDates($oldDate, (bool) $current->ends_next_day),
            ...$this->affectedDates($attributes['occurrence_date'], (bool) $attributes['ends_next_day']),
        ]);

        if ($linkedPoint !== null && ! $reason->creates_manual_point) {
            throw ValidationException::withMessages([
                'reason_id' => 'Esta justificativa criou a marcação manual das '.substr((string) $pointBefore['time'], 0, 5).' e o novo motivo não cria marcação. Para isso, cancele a justificativa (a marcação é removida) e registre outra.',
            ]);
        }

        $point = $linkedPoint;
        $pointCreated = false;
        $pointMoved = false;
        $classification = null;

        if ($reason->creates_manual_point && $linkedPoint === null) {
            [$point, $classification] = $this->createManualPoint($employee, $attributes);
            $pointCreated = true;
        } elseif ($linkedPoint !== null) {
            [$point, $classification, $pointMoved] = $this->moveLinkedPoint($employee, $linkedPoint, $attributes);
        }

        $current->forceFill([
            ...$attributes,
            'manual_point_id' => $point?->getKey(),
            'updated_by' => $user->getKey(),
            'version' => (int) $current->version + 1,
        ])->save();
        $current->refresh();

        $pointAfter = $this->pointSnapshot($point?->getKey());
        $changedFields = collect($this->identity([...$attributes, 'employee_id' => $current->employee_id]))
            ->diffAssoc($this->identity($currentValues))
            ->keys()
            ->map(fn (string $field) => self::FIELD_LABELS[$field] ?? $field)
            ->implode(', ');
        $history = $this->historyContext($newCompetence, $oldClosed !== null || $newClosed !== null, $user);

        $mainHistory = PointJustificationHistory::query()->create([
            ...$history,
            'point_justification_id' => $current->getKey(),
            'action' => JustificationHistoryAction::Updated,
            'old_values' => [
                'justification' => $before,
                'manual_point' => $pointBefore,
                'competence' => $oldCompetence->toDateString(),
            ],
            'new_values' => [
                'justification' => $current->getAttributes(),
                'reason' => $reason->only(['id', 'code', 'name', 'category', 'time_mode', 'creates_manual_point']),
                'manual_point' => $pointAfter,
                'sequence' => $this->sequenceSnapshot($classification),
                'competence' => $newCompetence->toDateString(),
            ],
            'description' => trim('Alterado: '.$changedFields.'. '.($this->closedPeriodNote([$oldClosed, $newClosed]) ?? '')),
            'manual_point_id' => $point?->getKey(),
        ]);

        if ($pointCreated) {
            PointJustificationHistory::query()->create([
                ...$history,
                'point_justification_id' => $current->getKey(),
                'action' => JustificationHistoryAction::ManualPointCreated,
                'old_values' => null,
                'new_values' => ['manual_point' => $pointAfter],
                'description' => sprintf('Marcação manual das %s criada na edição como %dª do dia (%s).', substr((string) $attributes['start_time'], 0, 5), $classification['position'], mb_strtolower($classification['kind']->label())),
                'manual_point_id' => $point->getKey(),
            ]);
        }

        if ($pointMoved) {
            PointJustificationHistory::query()->create([
                ...$history,
                'point_justification_id' => $current->getKey(),
                'action' => JustificationHistoryAction::ManualPointUpdated,
                'old_values' => ['manual_point' => $pointBefore],
                'new_values' => ['manual_point' => $pointAfter, 'sequence' => $this->sequenceSnapshot($classification)],
                'description' => sprintf('Marcação manual alterada de %s para %s (%dª do dia, %s).', self::formatPoint($pointBefore), self::formatPoint($pointAfter), $classification['position'], mb_strtolower($classification['kind']->label())),
                'manual_point_id' => $point->getKey(),
            ]);
        }

        $this->recordClosedDays($employee, $snapshotsBefore, $mainHistory, $user);

        return ['justification' => $current->load(['reason', 'manualPoint']), 'changed' => true];
    }

    /**
     * Reclassifica a marcação vinculada pela sequência da (nova) data e a move pelo
     * ManualPointRegistrar quando a data ou o horário mudam.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: Point, 1: array{position: int, kind: PunchKind, total: int}, 2: bool}
     */
    private function moveLinkedPoint(Employee $employee, Point $point, array $attributes): array
    {
        if ($attributes['start_time'] === null) {
            throw ValidationException::withMessages(['start_time' => 'O motivo cria marcação manual e exige o horário da marcação.']);
        }

        $moves = $point->date->toDateString() !== $attributes['occurrence_date']
            || substr((string) $point->getRawOriginal('time'), 0, 5) !== substr((string) $attributes['start_time'], 0, 5);

        if ($moves) {
            $protection = $this->registrar->protectionReason($point);

            if ($protection !== null) {
                throw ValidationException::withMessages(['form' => 'A marcação vinculada não pode ser alterada. '.$protection]);
            }
        }

        try {
            $classification = $this->classified($employee, $attributes, (int) $point->getKey());

            if ($moves) {
                $point = $this->registrar->move($employee, $point, $attributes['occurrence_date'], substr((string) $attributes['start_time'], 0, 5));
            }
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['start_time' => $exception->getMessage()]);
        }

        return [$point, $classification, $moves];
    }

    private function cancelLocked(PointJustification $justification, string $reason, int $expectedVersion, User $user): PointJustification
    {
        [$employee, $current] = $this->lockForChange($justification, $expectedVersion, 'Esta justificativa já foi cancelada.');

        $competence = $this->competenceOf($current->occurrence_date->toDateString());
        $closed = $this->lockedClosedPeriod($competence, $user);

        $point = $current->manual_point_id === null ? null : Point::query()->lockForUpdate()->find($current->manual_point_id);

        if ($point !== null) {
            $protection = $this->registrar->protectionReason($point);

            if ($protection !== null) {
                throw ValidationException::withMessages(['form' => 'Cancelamento bloqueado: a marcação manual vinculada está protegida. '.$protection]);
            }
        }

        $before = $current->getAttributes();
        $pointSnapshot = $this->pointSnapshot($point?->getKey());
        $pointId = $point?->getKey();
        $snapshotsBefore = $this->captureClosedDays($employee, $this->affectedDates($current->occurrence_date->toDateString(), (bool) $current->ends_next_day));

        $current->forceFill([
            'canceled_at' => now(),
            'canceled_by' => $user->getKey(),
            'cancellation_reason' => $reason,
            'version' => (int) $current->version + 1,
        ])->save();

        if ($point !== null) {
            $this->registrar->remove($current->employee, $point);
        }

        $current->refresh();

        $history = $this->historyContext($competence, $closed !== null, $user);

        $mainHistory = PointJustificationHistory::query()->create([
            ...$history,
            'point_justification_id' => $current->getKey(),
            'action' => JustificationHistoryAction::Canceled,
            'old_values' => ['justification' => $before, 'manual_point' => $pointSnapshot],
            'new_values' => ['justification' => $current->getAttributes(), 'cancellation_reason' => $reason],
            'description' => trim('Cancelada: '.$reason.' '.($this->closedPeriodNote([$closed]) ?? '')),
            'manual_point_id' => $pointId,
        ]);

        if ($pointSnapshot !== null) {
            PointJustificationHistory::query()->create([
                ...$history,
                'point_justification_id' => $current->getKey(),
                'action' => JustificationHistoryAction::ManualPointRemoved,
                'old_values' => ['manual_point' => $pointSnapshot],
                'new_values' => null,
                'description' => 'Marcação manual de '.self::formatPoint($pointSnapshot).' removida no cancelamento.',
                'manual_point_id' => $pointId,
            ]);
        }

        $this->recordClosedDays($employee, $snapshotsBefore, $mainHistory, $user);

        return $current->load(['reason', 'manualPoint']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{justification: PointJustification, created: bool}
     */
    private function registerLocked(Employee $employee, string $date, array $input, User $user): array
    {
        $this->lockCompetences([$date]);
        $locked = Employee::query()->lockForUpdate()->find($employee->getKey());

        if ($locked === null) {
            throw new DomainException('Funcionário não encontrado.');
        }

        $requestUuid = (string) ($input['request_uuid'] ?? '');
        $existing = $requestUuid === '' ? null : PointJustification::query()->where('request_uuid', $requestUuid)->first();

        if ($existing !== null) {
            return $this->replay($existing, $locked, $date, $input, $user);
        }

        $validated = $this->validator->validate($locked, $date, $input, $user);
        $attributes = $validated['attributes'];

        $this->ensureNoOverlap($locked, $attributes);

        $occurrenceDate = CarbonImmutable::parse($attributes['occurrence_date']);
        $competence = $occurrenceDate->startOfMonth()->addMonthNoOverflow();
        $closedPeriod = $this->lockedClosedPeriod($competence, $user);
        $snapshotsBefore = $this->captureClosedDays($locked, $this->affectedDates($attributes['occurrence_date'], (bool) $attributes['ends_next_day']));

        $point = null;
        $classification = null;

        if ($validated['reason']->creates_manual_point) {
            [$point, $classification] = $this->createManualPoint($locked, $attributes);
        }

        $justification = new PointJustification;
        $justification->forceFill([
            ...$attributes,
            'manual_point_id' => $point?->getKey(),
            'created_by' => $user->getKey(),
        ])->save();
        $justification->refresh();

        $history = [
            'affected_competence' => $competence->toDateString(),
            'closed_period' => $closedPeriod !== null,
            'performed_by' => $user->getKey(),
            'performed_by_name' => $user->name,
        ];

        $pointSnapshot = $point === null ? null : (array) Point::query()->toBase()->find($point->getKey());

        $mainHistory = PointJustificationHistory::query()->create([
            ...$history,
            'point_justification_id' => $justification->getKey(),
            'action' => JustificationHistoryAction::Created,
            'old_values' => null,
            'new_values' => [
                'justification' => $justification->getAttributes(),
                'reason' => $validated['reason']->only(['id', 'code', 'name', 'category', 'time_mode', 'creates_manual_point']),
                'employee' => $locked->only(['id', 'name', 'pis']),
                'manual_point' => $pointSnapshot,
                'sequence' => $classification === null ? null : [...$classification, 'kind' => $classification['kind']->value],
            ],
            'description' => $closedPeriod === null ? null : 'Registro em competência fechada ('.$closedPeriod->competence->format('m/Y').').',
            'manual_point_id' => $point?->getKey(),
        ]);

        if ($point !== null) {
            PointJustificationHistory::query()->create([
                ...$history,
                'point_justification_id' => $justification->getKey(),
                'action' => JustificationHistoryAction::ManualPointCreated,
                'old_values' => null,
                'new_values' => ['manual_point' => $pointSnapshot],
                'description' => sprintf('Marcação manual das %s criada como %dª do dia (%s).', substr((string) $attributes['start_time'], 0, 5), $classification['position'], mb_strtolower($classification['kind']->label())),
                'manual_point_id' => $point->getKey(),
            ]);
        }

        $this->recordClosedDays($locked, $snapshotsBefore, $mainHistory, $user);

        return ['justification' => $justification->load(['reason', 'manualPoint']), 'created' => true];
    }

    /**
     * Reenvio de um identificador já gravado: mesma operação devolve o registro existente;
     * conteúdo diferente (ou de outro funcionário) é recusado sem revelar o registro.
     *
     * @param  array<string, mixed>  $input
     * @return array{justification: PointJustification, created: bool}
     */
    private function replay(PointJustification $existing, Employee $employee, string $date, array $input, User $user): array
    {
        if ((int) $existing->employee_id !== (int) $employee->getKey()) {
            throw ValidationException::withMessages(['request_uuid' => self::REQUEST_CONFLICT_MESSAGE]);
        }

        try {
            $attributes = $this->validator->validate($employee, $date, $input, $user, checkRequestUuid: false)['attributes'];
        } catch (ValidationException) {
            throw ValidationException::withMessages(['request_uuid' => self::REQUEST_CONFLICT_MESSAGE]);
        }

        if ($this->identity($attributes) !== $this->identity([...$existing->getAttributes(), 'occurrence_date' => $existing->occurrence_date->toDateString()])) {
            throw ValidationException::withMessages(['request_uuid' => self::REQUEST_CONFLICT_MESSAGE]);
        }

        return ['justification' => $existing->load(['reason', 'manualPoint']), 'created' => false];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function identity(array $values): array
    {
        $identity = [];

        foreach (self::IDENTITY_FIELDS as $field) {
            $value = $values[$field] ?? null;
            $value = $value instanceof BackedEnum ? $value->value : $value;

            $identity[$field] = match ($field) {
                'employee_id', 'reason_id', 'allowed_minutes' => $value === null ? null : (int) $value,
                'ends_next_day' => (bool) $value,
                'start_time', 'end_time' => $value === null ? null : substr((string) $value, 0, 5),
                default => $value === null ? null : (string) $value,
            };
        }

        return $identity;
    }

    /**
     * Recusa a sobreposição com justificativas ativas do funcionário. Considera a data
     * anterior e a seguinte, por causa das ocorrências que atravessam a meia-noite.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function ensureNoOverlap(Employee $employee, array $attributes, ?int $exceptId = null): void
    {
        $date = CarbonImmutable::parse($attributes['occurrence_date']);
        [$start, $end] = $this->occupiedRange($attributes['occurrence_date'], $attributes['start_time'], $attributes['end_time'], (bool) $attributes['ends_next_day']);

        $others = PointJustification::query()
            ->active()
            ->with('reason')
            ->where('employee_id', $employee->getKey())
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->whereBetween('occurrence_date', [$date->subDay()->toDateString(), $date->addDay()->toDateString()])
            ->get();

        foreach ($others as $other) {
            [$otherStart, $otherEnd] = $this->occupiedRange($other->occurrence_date->toDateString(), $other->start_time, $other->end_time, $other->ends_next_day);

            if ($otherStart < $end && $start < $otherEnd) {
                throw ValidationException::withMessages([
                    'period' => 'Já existe uma justificativa ativa incompatível para o período: '.$this->describe($other).'.',
                ]);
            }
        }
    }

    /**
     * Período ocupado [início, fim) em timestamps: dia inteiro (sem horário), marcação
     * única (sem fim, ocupa o minuto) ou intervalo (fim no dia seguinte com a virada).
     *
     * @return array{0: int, 1: int}
     */
    private function occupiedRange(string $date, ?string $startTime, ?string $endTime, bool $endsNextDay): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        if ($startTime === null) {
            return [$day->getTimestamp(), $day->addDay()->getTimestamp()];
        }

        $start = CarbonImmutable::parse($date.' '.substr($startTime, 0, 5));

        if ($endTime === null) {
            return [$start->getTimestamp(), $start->addMinute()->getTimestamp()];
        }

        $end = CarbonImmutable::parse($date.' '.substr($endTime, 0, 5));

        return [$start->getTimestamp(), ($endsNextDay ? $end->addDay() : $end)->getTimestamp()];
    }

    private function describe(PointJustification $justification): string
    {
        $period = match (true) {
            $justification->start_time === null => 'dia inteiro',
            $justification->end_time === null => 'marcação às '.substr($justification->start_time, 0, 5),
            default => substr($justification->start_time, 0, 5).'–'.substr($justification->end_time, 0, 5).($justification->ends_next_day ? ' (dia seguinte)' : ''),
        };

        return $justification->reason->name.', '.$period.' em '.$justification->occurrence_date->format('d/m/Y');
    }

    /**
     * Competência de benefícios cuja janela contém a data, relida com bloqueio
     * compartilhado: o fechamento concorrente espera esta transação terminar.
     */
    private function lockedClosedPeriod(CarbonImmutable $competence, User $user): ?BenefitPeriod
    {
        $period = BenefitPeriod::query()->where('competence', $competence->toDateString())->sharedLock()->first();

        if ($period === null || $period->status !== BenefitPeriodStatus::Closed) {
            return null;
        }

        if (! Gate::forUser($user)->allows('change-closed-period-justifications')) {
            throw ValidationException::withMessages([
                'occurrence_date' => 'A data pertence à competência fechada '.$period->competence->format('m/Y').'. É necessária a permissão de alteração em competência fechada.',
            ]);
        }

        return $period;
    }

    /**
     * Classifica o horário pela sequência do dia e cria a batida manual.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: Point, 1: array{position: int, kind: PunchKind, total: int}}
     */
    private function createManualPoint(Employee $employee, array $attributes): array
    {
        $time = $attributes['start_time'];

        if ($time === null) {
            throw ValidationException::withMessages(['start_time' => 'O motivo cria marcação manual e exige o horário da marcação.']);
        }

        try {
            $classification = $this->classified($employee, $attributes);
            $point = $this->registrar->register($employee, $attributes['occurrence_date'], substr((string) $time, 0, 5));
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['start_time' => $exception->getMessage()]);
        }

        return [$point, $classification];
    }

    /**
     * Posição do horário da justificativa entre as batidas da data (sem contar a batida
     * informada, que é a própria marcação vinculada numa edição), conferida com o tipo.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{position: int, kind: PunchKind, total: int}
     *
     * @throws DomainException
     */
    private function classified(Employee $employee, array $attributes, ?int $exceptPointId = null): array
    {
        $time = (string) $attributes['start_time'];

        $existingTimes = Point::query()->toBase()
            ->where('pis', $employee->pis)
            ->whereDate('date', $attributes['occurrence_date'])
            ->when($exceptPointId !== null, fn ($query) => $query->where('id', '<>', $exceptPointId))
            ->pluck('time')
            ->map(fn ($value) => $value === null ? null : (string) $value)
            ->all();

        $classification = $this->sequence->classify($existingTimes, $time);

        if ($attributes['punch_kind'] instanceof PunchKind) {
            $this->sequence->ensureCompatible($attributes['punch_kind'], $classification, $time);
        }

        return $classification;
    }

    /**
     * Linha integral da batida, como está no banco, para os retratos do histórico.
     *
     * @return array<string, mixed>|null
     */
    private function pointSnapshot(?int $pointId): ?array
    {
        $row = $pointId === null ? null : Point::query()->toBase()->find($pointId);

        return $row === null ? null : (array) $row;
    }

    /**
     * @return array{position: int, kind: string, total: int}|null
     */
    private function sequenceSnapshot(?array $classification): ?array
    {
        return $classification === null ? null : [...$classification, 'kind' => $classification['kind']->value];
    }

    /**
     * Dias que a justificativa atinge no espelho: a data e, com término no dia seguinte, a
     * data seguinte.
     *
     * @return list<string>
     */
    private function affectedDates(string $date, bool $endsNextDay): array
    {
        $date = CarbonImmutable::parse($date);

        return $endsNextDay ? [$date->toDateString(), $date->addDay()->toDateString()] : [$date->toDateString()];
    }

    /**
     * Captura, antes da alteração, os dias de competência fechada (competência relida com
     * bloqueio compartilhado). Sem dia fechado, nada é capturado.
     *
     * @param  list<string>  $dates
     * @return array{periods: array<string, BenefitPeriod>, captured: array<string, array<string, mixed>>}
     */
    private function captureClosedDays(Employee $employee, array $dates): array
    {
        $dates = array_values(array_unique($dates));
        $competences = collect($dates)->mapWithKeys(fn (string $date) => [$date => $this->competenceOf($date)->toDateString()]);

        $periods = BenefitPeriod::query()
            ->whereIn('competence', $competences->unique()->values()->all())
            ->where('status', BenefitPeriodStatus::Closed)
            ->sharedLock()
            ->get()
            ->keyBy(fn (BenefitPeriod $period) => $period->competence->toDateString());

        $closed = $competences->filter(fn (string $competence) => $periods->has($competence))
            ->map(fn (string $competence) => $periods[$competence])
            ->all();

        return [
            'periods' => $closed,
            'captured' => $closed === [] ? [] : $this->snapshots->capture($employee, array_keys($closed)),
        ];
    }

    /**
     * Grava os dias capturados antes da alteração e os mesmos dias depois dela, com o
     * mesmo identificador de operação, ligados ao histórico e à competência de cada dia.
     *
     * @param  array{periods: array<string, BenefitPeriod>, captured: array<string, array<string, mixed>>}  $before
     */
    private function recordClosedDays(Employee $employee, array $before, PointJustificationHistory $history, User $user): void
    {
        if ($before['captured'] === []) {
            return;
        }

        $after = $this->snapshots->capture($employee, array_keys($before['captured']));
        $operation = (string) Str::uuid();

        foreach (collect($before['periods'])->groupBy(fn (BenefitPeriod $period) => $period->getKey(), preserveKeys: true) as $periodId => $dates) {
            $context = ['operation_uuid' => $operation, 'point_justification_history_id' => $history->getKey(), 'benefit_period_id' => (int) $periodId];
            $days = $dates->keys()->all();

            $captured = array_intersect_key($before['captured'], array_flip($days));

            $this->snapshots->persist($employee, $captured, TimesheetSnapshotSource::LateBaseline, $user, [
                ...$context,
                'reason' => 'Ponto de partida tardio: competência fechada sem ponto de partida para este dia; retrata o dia no momento desta operação, não o fechamento original.',
            ]);
            $this->snapshots->persist($employee, $captured, TimesheetSnapshotSource::BeforeChange, $user, $context);
            $this->snapshots->persist($employee, array_intersect_key($after, array_flip($days)), TimesheetSnapshotSource::AfterChange, $user, $context);
        }
    }

    /**
     * Bloqueio compartilhado, em ordem crescente, das competências que a operação pode
     * atingir (cada data e a seguinte, pelo término no dia seguinte), antes do bloqueio do
     * funcionário e de qualquer leitura comum da transação.
     *
     * Ordem: competência → funcionário, a mesma do fechamento e do cálculo, que bloqueiam a
     * competência e depois, ao gravar linhas com chave estrangeira para o funcionário
     * (apuração e snapshots), recebem o bloqueio compartilhado do funcionário. Na ordem
     * inversa (anterior à F6.2d) havia deadlock. Como nenhuma leitura comum acontece antes
     * da espera, a visão da transação (REPEATABLE READ) já enxerga um fechamento concorrente
     * concluído. Datas inválidas são ignoradas aqui e recusadas pela validação. A leitura
     * traz a linha inteira: só o id seria lido do índice de competência, sem bloquear a linha
     * (chave primária) que o fechamento bloqueia.
     *
     * @param  list<string>  $dates
     */
    private function lockCompetences(array $dates): void
    {
        $competences = collect($dates)
            ->filter(fn (string $date) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)))
            ->flatMap(fn (string $date) => [$this->competenceOf($date), $this->competenceOf(CarbonImmutable::parse($date)->addDay()->toDateString())])
            ->map(fn (CarbonImmutable $competence) => $competence->toDateString())
            ->unique()->sort()->values();

        if ($competences->isEmpty()) {
            return;
        }

        BenefitPeriod::query()->whereIn('competence', $competences->all())->orderBy('competence')->sharedLock()->get();
    }

    private function competenceOf(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date)->startOfMonth()->addMonthNoOverflow();
    }

    /**
     * Bloqueia o funcionário e a justificativa (nessa ordem) e confere a situação e a versão.
     *
     * @return array{0: Employee, 1: PointJustification}
     */
    private function lockForChange(PointJustification $justification, int $expectedVersion, string $canceledMessage, array $newDates = []): array
    {
        $current = PointJustification::query()->lockForUpdate()->find($justification->getKey());

        if ($current === null) {
            throw new DomainException('Justificativa não encontrada.');
        }

        $this->lockCompetences([$current->occurrence_date->toDateString(), ...$newDates]);
        $employee = Employee::query()->lockForUpdate()->find($current->employee_id);

        if ($employee === null) {
            throw new DomainException('Justificativa não encontrada.');
        }

        if ($current->isCanceled()) {
            throw ValidationException::withMessages(['form' => $canceledMessage]);
        }

        if ((int) $current->version !== $expectedVersion) {
            throw ValidationException::withMessages(['version' => self::VERSION_CONFLICT_MESSAGE]);
        }

        return [$employee, $current];
    }

    /**
     * @return array{affected_competence: string, closed_period: bool, performed_by: mixed, performed_by_name: string}
     */
    private function historyContext(CarbonImmutable $competence, bool $closedPeriod, User $user): array
    {
        return [
            'affected_competence' => $competence->toDateString(),
            'closed_period' => $closedPeriod,
            'performed_by' => $user->getKey(),
            'performed_by_name' => $user->name,
        ];
    }

    /**
     * Descrição de competências fechadas atingidas, para o histórico.
     *
     * @param  list<?BenefitPeriod>  $periods
     */
    private function closedPeriodNote(array $periods): ?string
    {
        $competences = collect($periods)->filter()->map(fn (BenefitPeriod $period) => $period->competence->format('m/Y'))->unique()->values();

        return $competences->isEmpty() ? null : 'Competência fechada ('.$competences->implode(', ').').';
    }

    private static function formatPoint(?array $snapshot): string
    {
        if ($snapshot === null) {
            return '—';
        }

        return CarbonImmutable::parse((string) $snapshot['date'])->format('d/m/Y').' '.substr((string) $snapshot['time'], 0, 5);
    }
}
