<?php

namespace App\Services;

use App\Enums\BenefitPeriodStatus;
use App\Enums\JustificationHistoryAction;
use App\Enums\PunchKind;
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
use Illuminate\Validation\ValidationException;

/**
 * Registro de justificativas do controle de ponto, com efeito imediato.
 *
 * Fluxo de `register()`, tudo em uma transação (qualquer falha desfaz justificativa,
 * batida manual e histórico):
 * 1. Gate `manage-point-justifications` (antes de qualquer leitura).
 * 2. Bloqueio do funcionário (`lockForUpdate`): serializa os registros do mesmo
 *    funcionário, inclusive a inclusão de batida pelo ManualPointRegistrar.
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
 * Nunca altera batidas existentes nem dados de benefícios.
 */
class PointJustificationService
{
    public const REQUEST_CONFLICT_MESSAGE = 'Este identificador de operação já foi usado para outro lançamento. Abra o formulário novamente.';

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
     * @param  array<string, mixed>  $input
     * @return array{justification: PointJustification, created: bool}
     */
    private function registerLocked(Employee $employee, string $date, array $input, User $user): array
    {
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

        PointJustificationHistory::query()->create([
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
    private function ensureNoOverlap(Employee $employee, array $attributes): void
    {
        $date = CarbonImmutable::parse($attributes['occurrence_date']);
        [$start, $end] = $this->occupiedRange($attributes['occurrence_date'], $attributes['start_time'], $attributes['end_time'], (bool) $attributes['ends_next_day']);

        $others = PointJustification::query()
            ->active()
            ->with('reason')
            ->where('employee_id', $employee->getKey())
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

        $existingTimes = Point::query()->toBase()
            ->where('pis', $employee->pis)
            ->whereDate('date', $attributes['occurrence_date'])
            ->pluck('time')
            ->map(fn ($value) => $value === null ? null : (string) $value)
            ->all();

        try {
            $classification = $this->sequence->classify($existingTimes, (string) $time);

            if ($attributes['punch_kind'] instanceof PunchKind) {
                $this->sequence->ensureCompatible($attributes['punch_kind'], $classification, (string) $time);
            }

            $point = $this->registrar->register($employee, $attributes['occurrence_date'], substr((string) $time, 0, 5));
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['start_time' => $exception->getMessage()]);
        }

        return [$point, $classification];
    }
}
