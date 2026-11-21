<?php

namespace App\Livewire;

use App\Enums\JustificationHistoryAction;
use App\Enums\JustificationTimeMode;
use App\Enums\JustificationTreatment;
use App\Enums\PunchKind;
use App\Models\AfdHistoricalNsr;
use App\Models\AfdPointDecision;
use App\Models\Employee;
use App\Models\Point;
use App\Models\PointJustification;
use App\Models\PointJustificationHistory;
use App\Models\PointJustificationReason;
use App\Models\TimesheetSnapshot;
use App\Services\ManualPointRegistrar;
use App\Services\PointJustificationHistoryPresenter;
use App\Services\PointJustificationService;
use App\Services\PointJustificationValidator;
use App\Services\TimesheetSnapshotHistory;
use App\Services\TimesheetSnapshotRecorder;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use WireUi\Traits\WireUiActions;

class EmployeePointsEdit extends Component
{
    use WireUiActions;

    #[Locked]
    public $employee;

    #[Locked]
    public $date;

    public $points;

    public $showAddPointModal = false;

    public $newPointTime;

    public bool $showJustificationModal = false;

    /**
     * Identificador da tentativa de cadastro, renovado a cada novo formulário. Protege
     * contra a gravação duplicada do mesmo envio (UNIQUE em point_justifications).
     */
    #[Locked]
    public ?string $justificationRequestUuid = null;

    /**
     * Campos do formulário de justificativa.
     *
     * @var array<string, mixed>
     */
    public array $justification = [];

    /**
     * Justificativa em edição (null num novo cadastro) e a versão lida ao abrir o
     * formulário, conferida pelo serviço contra alterações concorrentes.
     */
    #[Locked]
    public ?int $editingJustificationId = null;

    #[Locked]
    public ?int $editingVersion = null;

    public bool $showCancelJustificationModal = false;

    #[Locked]
    public ?int $cancelingJustificationId = null;

    #[Locked]
    public ?int $cancelingVersion = null;

    public string $cancellationReason = '';

    public bool $showHistoryModal = false;

    /**
     * Justificativa cujo histórico está aberto (somente leitura, F6.1).
     */
    #[Locked]
    public ?int $historyJustificationId = null;

    public bool $showSnapshotsModal = false;

    /**
     * Versão do espelho aberta em detalhe (somente leitura, F6.2d).
     */
    #[Locked]
    public ?int $snapshotId = null;

    public function addPoint()
    {
        $this->showAddPointModal = true;
    }

    public function saveNewPoint()
    {
        $this->validate([
            'newPointTime' => 'required|date_format:H:i',
        ]);

        try {
            $newPoint = app(ManualPointRegistrar::class)->register($this->employee, (string) $this->date, (string) $this->newPointTime);
        } catch (DomainException $exception) {
            $this->addError('newPointTime', $exception->getMessage());

            return;
        }

        $this->points->push($newPoint);
        $this->showAddPointModal = false;
        $this->newPointTime = null;

        $this->notification()->success(
            $title = 'Ponto Adicionado',
            $description = 'O ponto foi adicionado com sucesso.'
        );
    }

    /**
     * Exclui uma batida manual do funcionário e da data da tela. A verificação é feita
     * no servidor: batidas de outro funcionário ou data, batidas importadas do relógio e
     * batidas manuais mantidas por decisão do RH (pendência histórica do AFD) e batidas
     * referenciadas por anotação do RH (original de uma duplicada) e batidas vinculadas a uma
     * justificativa ativa (tratadas pela edição ou cancelamento da justificativa) são
     * recusadas mesmo quando o método é chamado diretamente.
     */
    public function removePoint($pointId)
    {
        $point = Point::query()
            ->where('pis', $this->employee->pis)
            ->whereDate('date', $this->date)
            ->find($pointId);

        if ($point === null) {
            $this->notification()->error(
                $title = 'Exclusão Não Permitida',
                $description = 'O ponto não pertence a este funcionário nesta data.'
            );

            return;
        }

        if ($point->type === 'importado') {
            $this->notification()->error(
                $title = 'Exclusão Não Permitida',
                $description = 'Batidas importadas do relógio não podem ser excluídas.'
            );

            return;
        }

        if ($point->isRetainedByHrDecision()) {
            $this->notification()->error(
                $title = 'Exclusão Não Permitida',
                $description = Point::RETAINED_BY_HR_MESSAGE
            );

            return;
        }

        if ($point->isAnnotatedByHr()) {
            $this->notification()->error(
                $title = 'Exclusão Não Permitida',
                $description = Point::ANNOTATED_BY_HR_MESSAGE
            );

            return;
        }

        if ($point->isLinkedToActiveJustification()) {
            $this->notification()->error(
                $title = 'Exclusão Não Permitida',
                $description = Point::LINKED_TO_JUSTIFICATION_MESSAGE
            );

            return;
        }

        $point->delete();
        $this->points = $this->points->filter(function ($p) use ($point) {
            return $p->id !== $point->id;
        });

        $this->notification()->success(
            $title = 'Ponto Removido',
            $description = 'O ponto foi removido com sucesso.'
        );
    }

    /**
     * Abre um novo cadastro de justificativa, com novo identificador de operação.
     */
    public function openJustificationForm(): void
    {
        $this->authorize('manage-point-justifications');

        $this->resetValidation();
        $this->justification = self::emptyJustification();
        $this->justificationRequestUuid = (string) Str::uuid();
        $this->editingJustificationId = null;
        $this->editingVersion = null;
        $this->showJustificationModal = true;
    }

    /**
     * Abre a edição de uma justificativa ativa do funcionário nesta data, guardando a
     * versão lida para a verificação de concorrência no salvamento.
     */
    public function editJustification(int $justificationId): void
    {
        $this->authorize('manage-point-justifications');

        $justification = $this->screenJustification($justificationId);

        if ($justification === null) {
            return;
        }

        $this->resetValidation();
        $this->justification = [
            ...self::emptyJustification(),
            'occurrence_date' => $justification->occurrence_date->toDateString(),
            'reason_id' => $justification->reason_id,
            'treatment' => $justification->treatment->value,
            'full_day' => $justification->start_time === null,
            'start_time' => $justification->start_time === null ? null : substr($justification->start_time, 0, 5),
            'end_time' => $justification->end_time === null ? null : substr($justification->end_time, 0, 5),
            'ends_next_day' => $justification->ends_next_day,
            'punch_kind' => $justification->punch_kind?->value,
            'allowed_minutes' => $justification->allowed_minutes,
            'observation' => (string) $justification->observation,
        ];
        $this->editingJustificationId = $justification->id;
        $this->editingVersion = $justification->version;
        $this->showJustificationModal = true;
    }

    /**
     * Abre a confirmação do cancelamento, que exige o motivo.
     */
    public function confirmCancelJustification(int $justificationId): void
    {
        $this->authorize('manage-point-justifications');

        $justification = $this->screenJustification($justificationId);

        if ($justification === null) {
            return;
        }

        $this->resetValidation();
        $this->cancellationReason = '';
        $this->cancelingJustificationId = $justification->id;
        $this->cancelingVersion = $justification->version;
        $this->showCancelJustificationModal = true;
    }

    /**
     * Cancela a justificativa pelo PointJustificationService (marcação vinculada removida
     * quando não protegida). Só confirma depois da gravação.
     */
    public function cancelJustification(PointJustificationService $service): void
    {
        $this->authorize('manage-point-justifications');
        $this->resetValidation();

        $justification = $this->cancelingJustificationId === null ? null : $this->screenJustification($this->cancelingJustificationId, activeOnly: false);

        if ($justification === null) {
            $this->showCancelJustificationModal = false;

            return;
        }

        try {
            $canceled = $service->cancel($justification, $this->cancellationReason, (int) $this->cancelingVersion, auth()->user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key) => [($key === 'cancellation_reason' ? 'cancellationReason' : 'cancellation.'.$key) => $messages])
                ->all());
        } catch (DomainException $exception) {
            $this->addError('cancellation.form', $exception->getMessage());

            return;
        }

        $this->showCancelJustificationModal = false;
        $this->cancelingJustificationId = null;
        $this->cancelingVersion = null;
        $this->cancellationReason = '';
        $this->loadPoints();

        $this->notification()->success(
            $title = 'Justificativa Cancelada',
            $canceled->reason->name.($justification->manual_point_id !== null ? ' — marcação manual vinculada removida.' : '.')
        );
    }

    /**
     * Abre o histórico (somente leitura) de uma justificativa do funcionário da tela,
     * ativa ou cancelada, em qualquer data: a justificativa movida também é consultada a
     * partir da data antiga. Nada é gravado.
     */
    public function showJustificationHistory(int $justificationId): void
    {
        $this->authorize('manage-point-justifications');

        $exists = PointJustification::query()->where('employee_id', $this->employee->id)->whereKey($justificationId)->exists();

        if (! $exists) {
            $this->notification()->error($title = 'Operação Não Permitida', 'A justificativa não pertence a este funcionário.');

            return;
        }

        $this->historyJustificationId = $justificationId;
        $this->showHistoryModal = true;
    }

    public function closeJustificationHistory(): void
    {
        $this->showHistoryModal = false;
        $this->historyJustificationId = null;
    }

    /**
     * Abre as versões históricas do espelho do funcionário neste dia (somente leitura).
     */
    public function showSnapshotVersions(): void
    {
        $this->authorize('manage-point-justifications');

        $this->snapshotId = null;
        $this->showSnapshotsModal = true;
    }

    /**
     * Detalhe de uma versão, restrito ao funcionário e ao dia da tela.
     */
    public function showSnapshot(int $snapshotId): void
    {
        $this->authorize('manage-point-justifications');

        if ($this->screenSnapshot($snapshotId) === null) {
            $this->notification()->error($title = 'Operação Não Permitida', 'A versão do espelho não pertence a este funcionário e dia.');

            return;
        }

        $this->snapshotId = $snapshotId;
        $this->showSnapshotsModal = true;
    }

    public function closeSnapshotVersions(): void
    {
        $this->showSnapshotsModal = false;
        $this->snapshotId = null;
    }

    /**
     * Ao trocar o motivo, aplica o tratamento padrão (se permitido) e limpa os horários.
     */
    public function updatedJustification(mixed $value, string $key): void
    {
        if ($key !== 'reason_id') {
            return;
        }

        $this->authorize('manage-point-justifications');

        $reason = $this->selectedReason();
        $treatments = $reason?->treatments() ?? [];
        $default = $reason?->default_treatment;

        $this->justification = [
            ...self::emptyJustification(),
            'occurrence_date' => $this->justification['occurrence_date'] ?? null,
            'reason_id' => $this->justification['reason_id'] ?? null,
            'treatment' => in_array($default, $treatments, true) ? $default->value : ($treatments[0]->value ?? null),
            'observation' => $this->justification['observation'] ?? '',
        ];
        $this->resetValidation();
    }

    /**
     * Registra a justificativa pelo PointJustificationService (validação, efeitos e
     * histórico em uma transação). Só confirma depois da gravação; em seguida fecha o
     * formulário e prepara um novo identificador para o próximo cadastro. O reenvio do
     * mesmo identificador devolve o registro existente sem gravar de novo.
     */
    public function submitJustification(PointJustificationService $service): void
    {
        $this->authorize('manage-point-justifications');
        $this->resetValidation();

        if ($this->editingJustificationId !== null) {
            $this->saveJustificationChanges($service);

            return;
        }

        try {
            $result = $service->register($this->employee, (string) $this->date, [
                ...$this->justification,
                'request_uuid' => $this->justificationRequestUuid,
            ], auth()->user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key) => ['justification.'.$key => $messages])
                ->all());
        } catch (DomainException $exception) {
            $this->addError('justification.form', $exception->getMessage());

            return;
        }

        $this->showJustificationModal = false;
        $this->justification = self::emptyJustification();
        $this->justificationRequestUuid = (string) Str::uuid();
        $this->loadPoints();

        $justification = $result['justification'];
        $description = $justification->reason->name.($justification->manual_point_id !== null ? ' — marcação manual das '.substr((string) $justification->start_time, 0, 5).' criada.' : '.');

        if ($result['created']) {
            $this->notification()->success($title = 'Justificativa Registrada', $description);
        } else {
            $this->notification()->info($title = 'Justificativa Já Registrada', 'Este envio já havia sido gravado; nada foi duplicado.');
        }
    }

    /**
     * Salva a edição pelo PointJustificationService, com a versão lida ao abrir o formulário.
     */
    private function saveJustificationChanges(PointJustificationService $service): void
    {
        $justification = $this->screenJustification($this->editingJustificationId, activeOnly: false);

        if ($justification === null) {
            $this->showJustificationModal = false;

            return;
        }

        try {
            $result = $service->update($justification, $this->justification, (int) $this->editingVersion, auth()->user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key) => ['justification.'.$key => $messages])
                ->all());
        } catch (DomainException $exception) {
            $this->addError('justification.form', $exception->getMessage());

            return;
        }

        $this->showJustificationModal = false;
        $this->justification = self::emptyJustification();
        $this->editingJustificationId = null;
        $this->editingVersion = null;
        $this->loadPoints();

        if ($result['changed']) {
            $this->notification()->success($title = 'Justificativa Alterada', $result['justification']->reason->name.'.');
        } else {
            $this->notification()->info($title = 'Nenhuma Alteração', 'Os dados informados são iguais aos gravados.');
        }
    }

    /**
     * Justificativa do funcionário da tela, nesta data. Avisa e devolve null quando não
     * pertence à tela ou, com `$activeOnly`, quando já está cancelada.
     */
    private function screenJustification(?int $justificationId, bool $activeOnly = true): ?PointJustification
    {
        $justification = PointJustification::query()
            ->where('employee_id', $this->employee->id)
            ->whereDate('occurrence_date', $this->date)
            ->find($justificationId);

        if ($justification === null) {
            $this->notification()->error($title = 'Operação Não Permitida', 'A justificativa não pertence a este funcionário nesta data.');

            return null;
        }

        if ($activeOnly && $justification->isCanceled()) {
            $this->notification()->error($title = 'Operação Não Permitida', 'Esta justificativa está cancelada.');

            return null;
        }

        return $justification;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyJustification(): array
    {
        return [
            'reason_id' => null,
            'treatment' => null,
            'full_day' => true,
            'start_time' => null,
            'end_time' => null,
            'ends_next_day' => false,
            'punch_kind' => null,
            'allowed_minutes' => null,
            'observation' => '',
        ];
    }

    private function selectedReason(): ?PointJustificationReason
    {
        $reasonId = $this->justification['reason_id'] ?? null;

        return $reasonId === null || $reasonId === '' ? null : app(PointJustificationValidator::class)->selectableReason($reasonId, $this->keptReasonId());
    }

    /**
     * Motivo atual da justificativa em edição, aceito mesmo desativado (lido do banco,
     * nunca do formulário).
     */
    private function keptReasonId(): ?int
    {
        if ($this->editingJustificationId === null) {
            return null;
        }

        $reasonId = PointJustification::query()->where('employee_id', $this->employee->id)->whereKey($this->editingJustificationId)->value('reason_id');

        return $reasonId === null ? null : (int) $reasonId;
    }

    /**
     * Dados da seção de justificativas; vazios para quem não tem a permissão do RH.
     *
     * @return array<string, mixed>
     */
    private function justificationViewData(): array
    {
        if (! Gate::allows('manage-point-justifications')) {
            return ['canManageJustifications' => false];
        }

        $reason = $this->selectedReason();
        $keptReasonId = $this->keptReasonId();

        return [
            'canManageJustifications' => true,
            'justifications' => PointJustification::query()
                ->with(['reason', 'creator', 'updater', 'canceler'])
                ->where('employee_id', $this->employee->id)
                ->whereDate('occurrence_date', $this->date)
                ->orderBy('start_time')
                ->orderBy('id')
                ->get(),
            'reasonOptions' => PointJustificationReason::query()
                ->where(fn ($query) => $query->where('active', true)->when($keptReasonId !== null, fn ($query) => $query->orWhere('id', $keptReasonId)))
                ->orderBy('category')->orderBy('sort_order')->orderBy('name')
                ->get()
                ->map(fn (PointJustificationReason $option) => ['id' => $option->id, 'name' => $option->category->label().' — '.$option->name.($option->active ? '' : ' (desativado)')])
                ->all(),
            'selectedReason' => $reason,
            'treatmentOptions' => collect($reason?->treatments() ?? [])
                ->map(fn (JustificationTreatment $treatment) => ['id' => $treatment->value, 'name' => $treatment->label()])
                ->all(),
            'punchKindOptions' => collect(PunchKind::cases())
                ->map(fn (PunchKind $kind) => ['id' => $kind->value, 'name' => $kind->label()])
                ->all(),
            'showsFullDayChoice' => $reason?->time_mode === JustificationTimeMode::FullDayOrInterval,
            'showsInterval' => $reason !== null && match ($reason->time_mode) {
                JustificationTimeMode::Interval => true,
                JustificationTimeMode::FullDayOrInterval => ! filter_var($this->justification['full_day'] ?? true, FILTER_VALIDATE_BOOLEAN),
                default => false,
            },
            'showsSinglePunch' => $reason?->time_mode === JustificationTimeMode::SinglePunch,
            'showsAllowedMinutes' => ($this->justification['treatment'] ?? null) === JustificationTreatment::PartialAllowance->value,
            'closedPeriod' => app(PointJustificationValidator::class)->closedPeriodFor(Carbon::parse($this->date)->toImmutable()),
            'isEditingJustification' => $this->editingJustificationId !== null,
            'movedJustifications' => $this->movedJustifications(),
            'justificationHistory' => $this->justificationHistory(),
            'snapshotVersions' => $this->snapshotVersions(),
            'snapshotDetail' => $this->snapshotDetail(),
            'cancelingJustification' => $this->cancelingJustificationId === null ? null : PointJustification::query()
                ->with(['reason', 'manualPoint'])
                ->where('employee_id', $this->employee->id)
                ->find($this->cancelingJustificationId),
        ];
    }

    private function screenSnapshot(int $snapshotId): ?TimesheetSnapshot
    {
        return TimesheetSnapshot::query()
            ->where('employee_id', $this->employee->id)
            ->where('date', Carbon::parse($this->date)->toDateString())
            ->find($snapshotId);
    }

    /**
     * Versões do dia com a referência usada no aviso de divergência.
     *
     * @return array{versions: list<array<string, mixed>>, reference_state: string, reference_stale: bool, competence: string, competence_status: ?string}|null
     */
    private function snapshotVersions(): ?array
    {
        if (! $this->showSnapshotsModal) {
            return null;
        }

        $history = app(TimesheetSnapshotHistory::class);
        $reference = $history->reference($this->employee, $this->date);
        $referenceId = $reference['snapshot']?->getKey();

        return [
            'versions' => $history->versions($this->employee, $this->date)
                ->map(fn (TimesheetSnapshot $snapshot) => [
                    ...TimesheetSnapshotHistory::describe($snapshot),
                    'is_reference' => $snapshot->getKey() === $referenceId,
                    'print_url' => route('reports.timesheet.snapshot-print', $snapshot),
                ])->all(),
            'reference_state' => $reference['state'],
            'reference_stale' => $reference['stale'],
            'competence' => Carbon::parse($this->date)->startOfMonth()->addMonthNoOverflow()->format('m/Y'),
            'competence_status' => $reference['period']?->status->label(),
        ];
    }

    /**
     * Conteúdo gravado da versão aberta (sem recálculo) e, se íntegra, a comparação com o
     * espelho atual. Nada é gravado.
     *
     * @return array<string, mixed>|null
     */
    private function snapshotDetail(): ?array
    {
        if (! $this->showSnapshotsModal || $this->snapshotId === null) {
            return null;
        }

        $snapshot = $this->screenSnapshot($this->snapshotId);

        if ($snapshot === null) {
            return null;
        }

        $described = TimesheetSnapshotHistory::describe($snapshot);
        $result = is_array($snapshot->result) ? $snapshot->result : [];
        $calculation = $snapshot->inputs['calculation'] ?? [];
        $comparison = $described['integrity_problems'] === [] ? app(TimesheetSnapshotRecorder::class)->divergence($snapshot) : null;
        $schedule = $calculation['schedule'] ?? null;

        return [
            ...$described,
            'stored' => [
                'Entrada' => $result['entrada'] ?? '',
                'Saída para intervalo' => $result['almoco_inicio'] ?? '',
                'Retorno do intervalo' => $result['almoco_fim'] ?? '',
                'Saída' => $result['saida'] ?? '',
                'Hora extra' => $result['overtime'] ?? '',
                'Atraso bruto' => $result['delay_before_allowance'] ?? '',
                'Abonado' => $result['allowed_delay'] ?? '',
                'Atraso' => $result['delay'] ?? '',
                'Ocorrência' => collect([filled($result['holiday'] ?? null) ? 'Feriado: '.$result['holiday'] : null, $result['status_label'] ?? null, ...($result['hr_annotations'] ?? [])])->filter()->implode(' · '),
                'Justificativas' => implode(' · ', $result['justification_notes'] ?? []),
            ],
            'inputs' => [
                'PIS' => $calculation['employee_pis'] ?? '',
                'Tipo do dia' => $calculation['day_type'] ?? '',
                'Jornada' => is_array($schedule) ? ($schedule['entry'] ?? '').' · '.($schedule['break_start'] ?? '').'–'.($schedule['break_end'] ?? '').' · '.($schedule['exit'] ?? '') : 'sem jornada',
                'Tolerância' => isset($calculation['entry_tolerance_minutes']) ? $calculation['entry_tolerance_minutes'].' min' : '',
                'Marcações' => collect($calculation['points'] ?? [])->map(fn (array $point) => substr((string) ($point['time'] ?? ''), 0, 5).' ('.($point['type'] ?? '').')'.(($point['hr_annotation'] ?? null) ? ' '.$point['hr_annotation'] : ''))->implode(', '),
                'Justificativas' => collect($calculation['justifications'] ?? [])->map(fn (array $item) => ($item['reason_name'] ?? '').' '.trim(($item['start_time'] ?? '').'–'.($item['end_time'] ?? ''), '–').' ('.($item['treatment'] ?? '').')')->implode(', '),
                'Regra alternativa' => ($calculation['alternative_rule']['enabled'] ?? false) ? 'ligada' : 'desligada',
            ],
            'comparison' => $comparison === null ? null : [
                'result_changed' => $comparison['result_changed'],
                'inputs_changed' => $comparison['inputs_changed'],
                'rules_changed' => $comparison['rules_changed'],
                'fields' => TimesheetSnapshotHistory::fieldLabels($comparison['changed_result_fields']),
            ],
            'print_url' => route('reports.timesheet.snapshot-print', $snapshot),
        ];
    }

    /**
     * Histórico aberto, filtrado pelo funcionário da tela.
     *
     * @return array<string, mixed>|null
     */
    private function justificationHistory(): ?array
    {
        if (! $this->showHistoryModal || $this->historyJustificationId === null) {
            return null;
        }

        $justification = PointJustification::query()
            ->where('employee_id', $this->employee->id)
            ->find($this->historyJustificationId);

        return $justification === null ? null : app(PointJustificationHistoryPresenter::class)->present($justification);
    }

    /**
     * Justificativas do funcionário que estavam nesta data e foram movidas pela edição para
     * outra data (aviso na data antiga). O motivo é o do retrato gravado na edição.
     *
     * @return list<array{id: int, reason: string, new_date: string, new_date_url: string, canceled: bool}>
     */
    private function movedJustifications(): array
    {
        $date = Carbon::parse($this->date)->toDateString();

        return PointJustificationHistory::query()
            ->with('justification')
            ->where('action', JustificationHistoryAction::Updated)
            ->where('old_values->justification->occurrence_date', $date)
            ->whereHas('justification', fn ($query) => $query->where('employee_id', $this->employee->id)->whereDate('occurrence_date', '!=', $date))
            ->orderByDesc('id')
            ->get()
            ->unique('point_justification_id')
            ->sortBy('point_justification_id')
            ->map(fn (PointJustificationHistory $history) => [
                'id' => (int) $history->point_justification_id,
                'reason' => (string) ($history->new_values['reason']['name'] ?? 'Justificativa #'.$history->point_justification_id),
                'new_date' => $history->justification->occurrence_date->format('d/m/Y'),
                'new_date_url' => route('points-edit', ['employee' => $this->employee->id, 'date' => $history->justification->occurrence_date->toDateString()]),
                'canceled' => $history->justification->isCanceled(),
            ])
            ->values()
            ->all();
    }

    public function mount($employee, $date)
    {
        $this->employee = Employee::find($employee);
        $this->date = $date;

        $this->loadPoints();
    }

    private function loadPoints(): void
    {
        $this->points = Point::where('pis', $this->employee->pis)
            ->whereDate('date', $this->date)
            ->orderBy('time', 'asc')
            ->get();
    }

    public function render()
    {
        return view('livewire.employee-points-edit', [
            'employee' => $this->employee,
            'date' => $this->date,
            'points' => $this->points,
            'annotationLabels' => AfdPointDecision::activeLabels(collect($this->points)->pluck('id')->map(fn ($id) => (int) $id)->all()),
            'justifiedPointIds' => PointJustification::query()->active()
                ->whereIn('manual_point_id', collect($this->points)->pluck('id'))
                ->pluck('manual_point_id')
                ->map(fn ($id) => (int) $id)
                ->all(),
            'retainedPointIds' => AfdHistoricalNsr::query()
                ->whereIn('related_manual_point_id', collect($this->points)->pluck('id'))
                ->where('status', AfdHistoricalNsr::STATUS_MANUAL_RETAINED)
                ->pluck('related_manual_point_id')
                ->map(fn ($id) => (int) $id)
                ->all(),
            'snapshotNotice' => app(TimesheetSnapshotHistory::class)->notice($this->employee, $this->date, withAuthor: Gate::allows('manage-point-justifications')),
            ...$this->justificationViewData(),
        ]);
    }
}
