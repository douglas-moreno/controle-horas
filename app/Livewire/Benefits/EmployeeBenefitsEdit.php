<?php

namespace App\Livewire\Benefits;

use App\Enums\BenefitType;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\TransportFare;
use App\Models\TransportRoute;
use App\Services\BenefitEligibility;
use App\Services\EmployeeBenefitRegistrar;
use App\Services\TransportDailyAmount;
use App\Services\TransportFarePriceResolver;
use App\Services\TransportRouteRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;
use WireUi\Traits\WireUiActions;

/**
 * Extensão do cadastro de funcionários: vigências de VT/VR/VD e itinerário de VT.
 */
class EmployeeBenefitsEdit extends Component
{
    use WireUiActions;

    public Employee $employee;

    /**
     * Data usada apenas para exibir elegibilidade e valor diário na tela. O cálculo de
     * uma competência sempre usa o dia 01 do mês, informado explicitamente aos serviços.
     */
    public string $referenceDate = '';

    public bool $showBenefitModal = false;

    public string $benefitType = 'vt';

    public ?string $benefitStartsOn = null;

    public ?string $benefitEndsOn = null;

    public string $benefitNotes = '';

    public bool $showEndBenefitModal = false;

    public ?int $endingBenefitId = null;

    public ?string $benefitEndDate = null;

    public bool $showRouteModal = false;

    public ?int $routeFareId = null;

    public string $routeTripsPerDay = '2';

    public ?string $routeStartsOn = null;

    public ?string $routeEndsOn = null;

    public string $routeNotes = '';

    public bool $showEndRouteModal = false;

    public ?int $endingRouteId = null;

    public ?string $routeEndDate = null;

    public function mount(Employee $employee): void
    {
        $this->employee = $employee;
        $this->referenceDate = CarbonImmutable::today()->toDateString();
    }

    public function openBenefitModal(): void
    {
        $this->resetValidation();
        $this->reset(['benefitType', 'benefitStartsOn', 'benefitEndsOn', 'benefitNotes']);
        $this->showBenefitModal = true;
    }

    public function createBenefit(EmployeeBenefitRegistrar $registrar): void
    {
        $this->benefitStartsOn = $this->normalizedDate($this->benefitStartsOn);
        $this->benefitEndsOn = $this->normalizedDate($this->benefitEndsOn);

        $this->validate([
            'benefitType' => ['required', Rule::enum(BenefitType::class)],
            'benefitStartsOn' => ['required', 'date_format:Y-m-d'],
            'benefitEndsOn' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:benefitStartsOn'],
            'benefitNotes' => ['nullable', 'string', 'max:1000'],
        ], [
            'benefitType.required' => 'Informe o benefício.',
            'benefitType.enum' => 'Benefício inválido.',
            'benefitStartsOn.required' => 'Informe o início da vigência.',
            'benefitStartsOn.date_format' => 'Informe uma data de início válida.',
            'benefitEndsOn.date_format' => 'Informe uma data de fim válida.',
            'benefitEndsOn.after_or_equal' => 'O fim da vigência não pode ser anterior ao início.',
            'benefitNotes.max' => 'A observação deve ter no máximo 1000 caracteres.',
        ]);

        $benefitType = BenefitType::from($this->benefitType);
        $startsOn = CarbonImmutable::parse($this->benefitStartsOn);
        $endsOn = $this->benefitEndsOn ? CarbonImmutable::parse($this->benefitEndsOn) : null;

        if ($registrar->overlaps($this->employee, $benefitType, $startsOn, $endsOn)) {
            $this->addError('benefitStartsOn', 'Já existe uma vigência de '.$benefitType->label().' que se sobrepõe a este período.');

            return;
        }

        $registrar->register($this->employee, $benefitType, $startsOn, $endsOn, $this->nullableText($this->benefitNotes), auth()->id());

        $this->showBenefitModal = false;

        $this->notification()->success(
            $title = 'Vigência Cadastrada',
            $description = 'A vigência de '.$benefitType->label().' foi cadastrada.'
        );
    }

    public function openEndBenefitModal(int $employeeBenefitId): void
    {
        $this->resetValidation();
        $this->endingBenefitId = $this->findBenefit($employeeBenefitId)->id;
        $this->benefitEndDate = null;
        $this->showEndBenefitModal = true;
    }

    public function endBenefit(EmployeeBenefitRegistrar $registrar): void
    {
        $employeeBenefit = $this->findBenefit((int) $this->endingBenefitId);
        $this->benefitEndDate = $this->normalizedDate($this->benefitEndDate);

        $this->validate([
            'benefitEndDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$employeeBenefit->starts_on->toDateString()],
        ], [
            'benefitEndDate.required' => 'Informe a data de encerramento.',
            'benefitEndDate.date_format' => 'Informe uma data válida.',
            'benefitEndDate.after_or_equal' => 'O encerramento não pode ser anterior ao início da vigência.',
        ]);

        $endsOn = CarbonImmutable::parse($this->benefitEndDate);

        if ($registrar->overlaps($this->employee, $employeeBenefit->benefit_type, $employeeBenefit->starts_on, $endsOn, $employeeBenefit->id)) {
            $this->addError('benefitEndDate', 'O encerramento faria esta vigência se sobrepor a outra de '.$employeeBenefit->benefit_type->label().'.');

            return;
        }

        $registrar->end($employeeBenefit, $endsOn, auth()->id());

        $this->showEndBenefitModal = false;

        $this->notification()->success(
            $title = 'Vigência Encerrada',
            $description = 'A vigência de '.$employeeBenefit->benefit_type->label().' foi encerrada em '.$endsOn->format('d/m/Y').'.'
        );
    }

    public function openRouteModal(): void
    {
        $this->resetValidation();
        $this->reset(['routeFareId', 'routeTripsPerDay', 'routeStartsOn', 'routeEndsOn', 'routeNotes']);
        $this->showRouteModal = true;
    }

    public function createRoute(TransportRouteRegistrar $registrar): void
    {
        $this->routeStartsOn = $this->normalizedDate($this->routeStartsOn);
        $this->routeEndsOn = $this->normalizedDate($this->routeEndsOn);
        $this->routeTripsPerDay = trim($this->routeTripsPerDay);

        $this->validate([
            'routeFareId' => ['required', Rule::exists('transport_fares', 'id')->where('is_active', true)],
            'routeTripsPerDay' => ['required', 'integer', 'min:1', 'max:255'],
            'routeStartsOn' => ['required', 'date_format:Y-m-d'],
            'routeEndsOn' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:routeStartsOn'],
            'routeNotes' => ['nullable', 'string', 'max:1000'],
        ], [
            'routeFareId.required' => 'Informe a tarifa.',
            'routeFareId.exists' => 'Selecione uma tarifa ativa.',
            'routeTripsPerDay.required' => 'Informe as viagens por dia.',
            'routeTripsPerDay.integer' => 'As viagens por dia devem ser um número inteiro.',
            'routeTripsPerDay.min' => 'Informe ao menos 1 viagem por dia.',
            'routeTripsPerDay.max' => 'Informe no máximo 255 viagens por dia.',
            'routeStartsOn.required' => 'Informe o início da vigência.',
            'routeStartsOn.date_format' => 'Informe uma data de início válida.',
            'routeEndsOn.date_format' => 'Informe uma data de fim válida.',
            'routeEndsOn.after_or_equal' => 'O fim da vigência não pode ser anterior ao início.',
            'routeNotes.max' => 'A observação deve ter no máximo 1000 caracteres.',
        ]);

        $transportFare = TransportFare::findOrFail($this->routeFareId);
        $startsOn = CarbonImmutable::parse($this->routeStartsOn);
        $endsOn = $this->routeEndsOn ? CarbonImmutable::parse($this->routeEndsOn) : null;

        if ($registrar->overlaps($this->employee, $transportFare, $startsOn, $endsOn)) {
            $this->addError('routeStartsOn', 'Já existe um trecho '.$transportFare->name.' que se sobrepõe a este período.');

            return;
        }

        $registrar->register($this->employee, $transportFare, (int) $this->routeTripsPerDay, $startsOn, $endsOn, $this->nullableText($this->routeNotes), auth()->id());

        $this->showRouteModal = false;

        $this->notification()->success(
            $title = 'Trecho Cadastrado',
            $description = 'O trecho '.$transportFare->name.' foi adicionado ao itinerário.'
        );
    }

    public function openEndRouteModal(int $transportRouteId): void
    {
        $this->resetValidation();
        $this->endingRouteId = $this->findRoute($transportRouteId)->id;
        $this->routeEndDate = null;
        $this->showEndRouteModal = true;
    }

    public function endRoute(TransportRouteRegistrar $registrar): void
    {
        $transportRoute = $this->findRoute((int) $this->endingRouteId);
        $this->routeEndDate = $this->normalizedDate($this->routeEndDate);

        $this->validate([
            'routeEndDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$transportRoute->starts_on->toDateString()],
        ], [
            'routeEndDate.required' => 'Informe a data de encerramento.',
            'routeEndDate.date_format' => 'Informe uma data válida.',
            'routeEndDate.after_or_equal' => 'O encerramento não pode ser anterior ao início do trecho.',
        ]);

        $endsOn = CarbonImmutable::parse($this->routeEndDate);

        if ($registrar->overlaps($this->employee, $transportRoute->transportFare, $transportRoute->starts_on, $endsOn, $transportRoute->id)) {
            $this->addError('routeEndDate', 'O encerramento faria este trecho se sobrepor a outro trecho da mesma tarifa.');

            return;
        }

        $registrar->end($transportRoute, $endsOn, auth()->id());

        $this->showEndRouteModal = false;

        $this->notification()->success(
            $title = 'Trecho Encerrado',
            $description = 'O trecho '.$transportRoute->transportFare->name.' foi encerrado em '.$endsOn->format('d/m/Y').'.'
        );
    }

    public function render(): View
    {
        $reference = $this->referenceDay();

        $benefits = $this->employee->employeeBenefits()
            ->orderByDesc('starts_on')
            ->get()
            ->groupBy(fn (EmployeeBenefit $employeeBenefit) => $employeeBenefit->benefit_type->value);

        $routes = $this->employee->transportRoutes()
            ->with('transportFare')
            ->orderByDesc('starts_on')
            ->orderBy('id')
            ->get();

        $routePrices = app(TransportFarePriceResolver::class)
            ->forDateMany($routes->pluck('transportFare')->unique('id')->values(), $reference);

        return view('livewire.benefits.employee-benefits-edit', [
            'benefitTypes' => BenefitType::cases(),
            'benefits' => $benefits,
            'eligibleTypes' => app(BenefitEligibility::class)->eligibleTypes($this->employee, $reference),
            'routes' => $routes,
            'routePrices' => $routePrices,
            'dailyAmount' => app(TransportDailyAmount::class)->forEmployee($this->employee, $reference),
            'reference' => $reference,
            'benefitTypeOptions' => collect(BenefitType::cases())
                ->map(fn (BenefitType $benefitType) => ['id' => $benefitType->value, 'name' => $benefitType->label()])
                ->all(),
            'fareOptions' => TransportFare::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'operator'])
                ->map(fn (TransportFare $transportFare) => [
                    'id' => $transportFare->id,
                    'name' => $transportFare->operator ? $transportFare->name.' — '.$transportFare->operator : $transportFare->name,
                ])
                ->all(),
        ]);
    }

    private function referenceDay(): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($this->normalizedDate($this->referenceDate) ?? 'today')->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::today();
        }
    }

    private function findBenefit(int $employeeBenefitId): EmployeeBenefit
    {
        return $this->employee->employeeBenefits()->findOrFail($employeeBenefitId);
    }

    private function findRoute(int $transportRouteId): TransportRoute
    {
        return $this->employee->transportRoutes()->with(['employee', 'transportFare'])->findOrFail($transportRouteId);
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

    private function nullableText(string $text): ?string
    {
        $text = trim($text);

        return $text === '' ? null : $text;
    }
}
