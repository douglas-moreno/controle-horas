<?php

namespace App\Livewire;

use App\Enums\JustificationTimeMode;
use App\Enums\JustificationTreatment;
use App\Enums\PunchKind;
use App\Models\AfdHistoricalNsr;
use App\Models\AfdPointDecision;
use App\Models\Employee;
use App\Models\Point;
use App\Models\PointJustification;
use App\Models\PointJustificationReason;
use App\Services\ManualPointRegistrar;
use App\Services\PointJustificationService;
use App\Services\PointJustificationValidator;
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
     * referenciadas por anotação do RH (original de uma duplicada) são recusadas mesmo quando
     * o método é chamado diretamente.
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
        $this->showJustificationModal = true;
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

        return $reasonId === null || $reasonId === '' ? null : PointJustificationReason::query()->active()->find($reasonId);
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

        return [
            'canManageJustifications' => true,
            'justifications' => PointJustification::query()
                ->with(['reason', 'creator'])
                ->where('employee_id', $this->employee->id)
                ->whereDate('occurrence_date', $this->date)
                ->orderBy('start_time')
                ->orderBy('id')
                ->get(),
            'reasonOptions' => PointJustificationReason::query()->active()
                ->orderBy('category')->orderBy('sort_order')->orderBy('name')
                ->get()
                ->map(fn (PointJustificationReason $option) => ['id' => $option->id, 'name' => $option->category->label().' — '.$option->name])
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
        ];
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
            'retainedPointIds' => AfdHistoricalNsr::query()
                ->whereIn('related_manual_point_id', collect($this->points)->pluck('id'))
                ->where('status', AfdHistoricalNsr::STATUS_MANUAL_RETAINED)
                ->pluck('related_manual_point_id')
                ->map(fn ($id) => (int) $id)
                ->all(),
            ...$this->justificationViewData(),
        ]);
    }
}
