<?php

namespace App\Livewire\Benefits;

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\AdjustmentStatus;
use App\Enums\AdjustmentTiming;
use App\Enums\BenefitType;
use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Services\BenefitAdjustmentRegistrar;
use App\Services\BusinessCalendar;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;
use WireUi\Traits\WireUiActions;

/**
 * Ajustes de uma competência: lançamento, alteração, revisão e substituição.
 * Toda regra de negócio fica no BenefitAdjustmentRegistrar.
 */
class BenefitPeriodAdjustments extends Component
{
    use WireUiActions;

    public BenefitPeriod $benefitPeriod;

    public string $filterEmployeeId = '';

    public string $filterReason = '';

    public string $filterSource = '';

    public string $filterStatus = '';

    /**
     * @var list<int|string>
     */
    public array $selected = [];

    public bool $showFormModal = false;

    /**
     * create | update | replace
     */
    public string $formMode = 'create';

    public ?int $formAdjustmentId = null;

    public ?int $formEmployeeId = null;

    public string $formReason = 'unjustified_absence';

    public ?string $formStartsOn = null;

    public ?string $formEndsOn = null;

    public string $formNotes = '';

    public string $formImpactVt = '';

    public string $formImpactVr = '';

    public string $formImpactVd = '';

    public bool $showRejectModal = false;

    /**
     * @var list<int>
     */
    public array $rejectingIds = [];

    public string $rejectNotes = '';

    public function mount(BenefitPeriod $benefitPeriod): void
    {
        $this->benefitPeriod = $benefitPeriod;
    }

    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->formMode = 'create';
        $this->showFormModal = true;
    }

    public function openUpdateModal(int $adjustmentId): void
    {
        $this->fillForm($this->findAdjustment($adjustmentId), 'update');
    }

    public function openReplaceModal(int $adjustmentId): void
    {
        $this->fillForm($this->findAdjustment($adjustmentId), 'replace');
    }

    public function save(BenefitAdjustmentRegistrar $registrar): void
    {
        $this->formStartsOn = $this->normalizedDate($this->formStartsOn);
        $this->formEndsOn = $this->normalizedDate($this->formEndsOn) ?? $this->formStartsOn;

        $this->validate([
            'formEmployeeId' => ['required', 'integer', Rule::exists('employees', 'id')],
            'formReason' => ['required', Rule::enum(AdjustmentReason::class)],
            'formStartsOn' => ['required', 'date_format:Y-m-d'],
            'formEndsOn' => ['required', 'date_format:Y-m-d', 'after_or_equal:formStartsOn'],
            'formNotes' => ['nullable', 'string', 'max:1000'],
            'formImpactVt' => ['nullable', 'integer', 'between:-999,999'],
            'formImpactVr' => ['nullable', 'integer', 'between:-999,999'],
            'formImpactVd' => ['nullable', 'integer', 'between:-999,999'],
        ], [
            'formEmployeeId.required' => 'Informe o funcionário.',
            'formEmployeeId.exists' => 'Funcionário inválido.',
            'formReason.required' => 'Informe o motivo.',
            'formReason.enum' => 'Motivo inválido.',
            'formStartsOn.required' => 'Informe a data inicial.',
            'formStartsOn.date_format' => 'Informe uma data inicial válida.',
            'formEndsOn.date_format' => 'Informe uma data final válida.',
            'formEndsOn.after_or_equal' => 'A data final não pode ser anterior à inicial.',
            'formNotes.max' => 'A observação deve ter no máximo 1000 caracteres.',
            'formImpactVt.integer' => 'O impacto de VT deve ser um número inteiro.',
            'formImpactVr.integer' => 'O impacto de VR deve ser um número inteiro.',
            'formImpactVd.integer' => 'O impacto de VD deve ser um número inteiro.',
            'formImpactVt.between' => 'O impacto de VT deve estar entre -999 e 999.',
            'formImpactVr.between' => 'O impacto de VR deve estar entre -999 e 999.',
            'formImpactVd.between' => 'O impacto de VD deve estar entre -999 e 999.',
        ]);

        $reason = AdjustmentReason::from($this->formReason);
        $startsOn = CarbonImmutable::parse($this->formStartsOn);
        $endsOn = CarbonImmutable::parse($this->formEndsOn);
        $impacts = $reason === AdjustmentReason::Manual ? $this->formImpacts() : [];

        try {
            $result = match ($this->formMode) {
                'update' => $registrar->update($this->findAdjustment((int) $this->formAdjustmentId), $reason, $startsOn, $endsOn, $this->formNotes, $impacts),
                'replace' => $registrar->replace($this->findAdjustment((int) $this->formAdjustmentId), $reason, $startsOn, $endsOn, $this->formNotes, $impacts),
                default => $registrar->register($this->benefitPeriod, Employee::findOrFail($this->formEmployeeId), $reason, AdjustmentSource::Manual, $startsOn, $endsOn, $this->formNotes, $impacts),
            };
        } catch (DomainException $exception) {
            $this->addError('form', $exception->getMessage());

            return;
        }

        $this->showFormModal = false;
        $this->benefitPeriod->refresh();

        $this->notification()->success(
            $title = match ($this->formMode) {
                'update' => 'Ajuste Alterado',
                'replace' => 'Ajuste Substituído',
                default => 'Ajuste Lançado',
            },
            $description = 'Ajuste #'.$result['adjustment']->id.' — '.$result['adjustment']->reason->label().'.'
        );

        if ($result['alerts'] !== []) {
            $this->notification()->warning(
                $title = 'Atenção',
                $description = collect($result['alerts'])->pluck('message')->join(' ')
            );
        }
    }

    public function confirm(int $adjustmentId, BenefitAdjustmentRegistrar $registrar): void
    {
        $this->runAction(fn () => $registrar->confirm($this->findAdjustment($adjustmentId)), 'Ajuste Confirmado', 'O ajuste #'.$adjustmentId.' foi confirmado.');
    }

    public function confirmSelected(BenefitAdjustmentRegistrar $registrar): void
    {
        $ids = $this->selectedIds();

        $this->runAction(fn () => $registrar->confirmMany($this->benefitPeriod, $ids), 'Ajustes Confirmados', count($ids).' ajuste(s) confirmado(s).');
    }

    public function openRejectModal(int $adjustmentId): void
    {
        $this->resetValidation();
        $this->rejectingIds = [$this->findAdjustment($adjustmentId)->id];
        $this->rejectNotes = '';
        $this->showRejectModal = true;
    }

    public function openRejectSelectedModal(): void
    {
        $this->resetValidation();
        $this->rejectingIds = $this->selectedIds();
        $this->rejectNotes = '';
        $this->showRejectModal = $this->rejectingIds !== [];
    }

    public function reject(BenefitAdjustmentRegistrar $registrar): void
    {
        $this->validate([
            'rejectNotes' => ['required', 'string', 'max:1000'],
        ], [
            'rejectNotes.required' => 'Informe o motivo da rejeição.',
            'rejectNotes.max' => 'O motivo deve ter no máximo 1000 caracteres.',
        ]);

        try {
            count($this->rejectingIds) === 1
                ? $registrar->reject($this->findAdjustment($this->rejectingIds[0]), $this->rejectNotes)
                : $registrar->rejectMany($this->benefitPeriod, $this->rejectingIds, $this->rejectNotes);
        } catch (DomainException $exception) {
            $this->addError('rejectNotes', $exception->getMessage());

            return;
        }

        $this->notification()->success(
            $title = 'Ajuste Rejeitado',
            $description = count($this->rejectingIds).' ajuste(s) rejeitado(s). O histórico foi preservado.'
        );

        $this->showRejectModal = false;
        $this->selected = [];
        $this->rejectingIds = [];
        $this->benefitPeriod->refresh();
    }

    public function reconsider(int $adjustmentId, BenefitAdjustmentRegistrar $registrar): void
    {
        $this->runAction(fn () => $registrar->reconsider($this->findAdjustment($adjustmentId)), 'Ajuste Reconsiderado', 'O ajuste #'.$adjustmentId.' voltou para pendente.');
    }

    public function render(BenefitAdjustmentRegistrar $registrar, BusinessCalendar $calendar): View
    {
        $period = $this->benefitPeriod;

        $adjustments = $period->adjustments()
            ->with(['employee:id,name', 'impacts', 'reviewedBy:id,name'])
            ->when($this->filterEmployeeId !== '', fn ($query) => $query->where('employee_id', (int) $this->filterEmployeeId))
            ->when(AdjustmentReason::tryFrom($this->filterReason), fn ($query, AdjustmentReason $reason) => $query->where('reason', $reason))
            ->when(AdjustmentSource::tryFrom($this->filterSource), fn ($query, AdjustmentSource $source) => $query->where('source', $source))
            ->when(AdjustmentStatus::tryFrom($this->filterStatus), fn ($query, AdjustmentStatus $status) => $query->where('status', $status))
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        $sections = collect([AdjustmentTiming::Forecast, AdjustmentTiming::Realized, AdjustmentTiming::Retroactive])
            ->mapWithKeys(fn (AdjustmentTiming $timing) => [$timing->value => [
                'timing' => $timing,
                'adjustments' => $adjustments->filter(fn (BenefitAdjustment $adjustment) => $period->timingOf($adjustment->starts_on, $adjustment->ends_on) === $timing)->values(),
            ]]);

        return view('livewire.benefits.benefit-period-adjustments', [
            'period' => $period,
            'isEditable' => $period->status->isEditable(),
            'businessDays' => $period->business_days,
            'calendarBusinessDays' => $calendar->businessDaysInMonth($period->referenceDate()),
            'sections' => $sections,
            'alerts' => $registrar->alertsFor($period, $adjustments),
            'benefitTypes' => BenefitType::cases(),
            'formPreview' => $this->showFormModal ? $this->formPreview($registrar) : null,
            'employeeOptions' => Employee::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Employee $employee) => ['id' => $employee->id, 'name' => $employee->name])
                ->all(),
            'reasonOptions' => collect(AdjustmentReason::cases())
                ->map(fn (AdjustmentReason $reason) => ['id' => $reason->value, 'name' => $reason->label()])
                ->all(),
            'sourceOptions' => collect(AdjustmentSource::cases())
                ->map(fn (AdjustmentSource $source) => ['id' => $source->value, 'name' => $source->label()])
                ->all(),
            'statusOptions' => collect(AdjustmentStatus::cases())
                ->map(fn (AdjustmentStatus $status) => ['id' => $status->value, 'name' => $status->label()])
                ->all(),
        ]);
    }

    /**
     * Explicação do impacto automático e alertas do formulário, antes de salvar.
     *
     * @return array{is_manual: bool, days_count: ?int, sign: ?int, timing: ?AdjustmentTiming, alerts: list<array{level: string, message: string}>}|null
     */
    private function formPreview(BenefitAdjustmentRegistrar $registrar): ?array
    {
        $reason = AdjustmentReason::tryFrom($this->formReason);
        $startsOn = $this->parsedDate($this->formStartsOn);
        $endsOn = $this->parsedDate($this->formEndsOn) ?? $startsOn;

        if ($reason === null) {
            return null;
        }

        if ($startsOn === null || $endsOn === null || $startsOn->greaterThan($endsOn)) {
            return ['is_manual' => $reason === AdjustmentReason::Manual, 'days_count' => null, 'sign' => $reason->defaultSign(), 'timing' => null, 'alerts' => []];
        }

        return [
            'is_manual' => $reason === AdjustmentReason::Manual,
            'days_count' => $registrar->standardDaysCount($reason, $startsOn, $endsOn),
            'sign' => $reason->defaultSign(),
            'timing' => $this->benefitPeriod->timingOf($startsOn, $endsOn),
            'alerts' => $this->formEmployeeId
                ? $registrar->previewAlerts($this->benefitPeriod, $this->formEmployeeId, $reason, $startsOn, $endsOn, $this->formMode === 'create' ? null : $this->formAdjustmentId)
                : [],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function formImpacts(): array
    {
        return collect([
            BenefitType::Vt->value => $this->formImpactVt,
            BenefitType::Vr->value => $this->formImpactVr,
            BenefitType::Vd->value => $this->formImpactVd,
        ])
            ->map(fn (string $quantity) => trim($quantity))
            ->reject(fn (string $quantity) => $quantity === '')
            ->map(fn (string $quantity) => (int) $quantity)
            ->all();
    }

    private function fillForm(BenefitAdjustment $adjustment, string $mode): void
    {
        $this->resetForm();
        $impacts = $adjustment->impacts->mapWithKeys(fn ($impact) => [$impact->benefit_type->value => (string) $impact->quantity]);

        $this->formMode = $mode;
        $this->formAdjustmentId = $adjustment->id;
        $this->formEmployeeId = $adjustment->employee_id;
        $this->formReason = $adjustment->reason->value;
        $this->formStartsOn = $adjustment->starts_on->toDateString();
        $this->formEndsOn = $adjustment->ends_on->toDateString();
        $this->formNotes = (string) $adjustment->notes;

        if ($adjustment->reason === AdjustmentReason::Manual) {
            $this->formImpactVt = $impacts->get(BenefitType::Vt->value, '');
            $this->formImpactVr = $impacts->get(BenefitType::Vr->value, '');
            $this->formImpactVd = $impacts->get(BenefitType::Vd->value, '');
        }

        $this->showFormModal = true;
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['formAdjustmentId', 'formEmployeeId', 'formReason', 'formStartsOn', 'formEndsOn', 'formNotes', 'formImpactVt', 'formImpactVr', 'formImpactVd']);
    }

    /**
     * Executa uma ação de revisão pelo serviço e notifica o resultado.
     */
    private function runAction(callable $action, string $successTitle, string $successDescription): void
    {
        try {
            $action();
        } catch (DomainException $exception) {
            $this->notification()->error(
                $title = 'Operação Não Realizada',
                $description = $exception->getMessage()
            );

            return;
        }

        $this->selected = [];
        $this->benefitPeriod->refresh();

        $this->notification()->success($title = $successTitle, $description = $successDescription);
    }

    /**
     * @return list<int>
     */
    private function selectedIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->selected)));
    }

    private function findAdjustment(int $adjustmentId): BenefitAdjustment
    {
        return $this->benefitPeriod->adjustments()->with('impacts')->findOrFail($adjustmentId);
    }

    /**
     * O seletor de datas envia a data com horário e fuso; guarda-se somente Y-m-d.
     */
    private function normalizedDate(?string $date): ?string
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($date)->toDateString();
        } catch (\Throwable) {
            return $date;
        }
    }

    private function parsedDate(?string $date): ?CarbonImmutable
    {
        $normalized = $this->normalizedDate($date);

        if ($normalized === null || CarbonImmutable::hasFormat($normalized, 'Y-m-d') === false) {
            return null;
        }

        return CarbonImmutable::parse($normalized);
    }
}
