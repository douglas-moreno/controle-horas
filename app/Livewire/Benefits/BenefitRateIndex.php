<?php

namespace App\Livewire\Benefits;

use App\Enums\BenefitType;
use App\Models\BenefitPeriod;
use App\Models\BenefitRate;
use App\Services\BenefitRateRegistrar;
use App\Services\BenefitRateResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Component;
use WireUi\Traits\WireUiActions;

class BenefitRateIndex extends Component
{
    use WireUiActions;

    public bool $showCreateModal = false;

    public string $benefitType = 'vr';

    public string $amount = '';

    /**
     * Mês de início da vigência no formato Y-m; a vigência é gravada no dia 01.
     */
    public string $validFromMonth = '';

    public function openCreateModal(): void
    {
        $this->resetValidation();
        $this->reset(['benefitType', 'amount', 'validFromMonth']);
        $this->showCreateModal = true;
    }

    public function createRate(BenefitRateRegistrar $registrar): void
    {
        $this->amount = str_replace(',', '.', trim($this->amount));

        $this->validate([
            'benefitType' => ['required', 'in:vr,vd'],
            'amount' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'validFromMonth' => ['required', 'date_format:Y-m'],
        ], [
            'benefitType.required' => 'Informe o tipo de benefício.',
            'benefitType.in' => 'Somente VR e VD possuem valor diário global.',
            'amount.required' => 'Informe o valor diário.',
            'amount.regex' => 'Informe um valor válido com até duas casas decimais (ex.: 27,50).',
            'validFromMonth.required' => 'Informe o mês de início da vigência.',
            'validFromMonth.date_format' => 'Informe o mês de início no formato mês/ano.',
        ]);

        $benefitType = BenefitType::from($this->benefitType);
        $validFrom = CarbonImmutable::createFromFormat('!Y-m-d', $this->validFromMonth.'-01');

        if (BenefitRate::query()->where('benefit_type', $benefitType)->where('valid_from', $validFrom->toDateString())->exists()) {
            $this->addError('validFromMonth', 'Já existe um valor de '.$benefitType->label().' com vigência neste mês.');

            return;
        }

        try {
            $registrar->register($benefitType, $this->amount, $validFrom, auth()->id());
        } catch (UniqueConstraintViolationException) {
            $this->addError('validFromMonth', 'Já existe um valor de '.$benefitType->label().' com vigência neste mês.');

            return;
        }

        $this->showCreateModal = false;

        $this->notification()->success(
            $title = 'Vigência Cadastrada',
            $description = 'O valor de '.$benefitType->label().' foi cadastrado a partir de '.$validFrom->format('m/Y').'.'
        );
    }

    public function destroy(int $benefitRateId, BenefitRateRegistrar $registrar): void
    {
        $benefitRate = BenefitRate::findOrFail($benefitRateId);

        if (! $registrar->canDelete($benefitRate)) {
            $this->notification()->error(
                $title = 'Exclusão Não Permitida',
                $description = 'Esta vigência já foi utilizada em um cálculo ou faz parte de uma competência fechada. Cadastre uma nova vigência.'
            );

            return;
        }

        $benefitRate->delete();

        $this->notification()->success(
            $title = 'Vigência Excluída',
            $description = 'A vigência foi excluída com sucesso.'
        );
    }

    public function render(): View
    {
        $resolver = app(BenefitRateResolver::class);
        $today = CarbonImmutable::today();

        $currentRates = collect([BenefitType::Vr, BenefitType::Vd])
            ->mapWithKeys(fn (BenefitType $benefitType) => [$benefitType->value => $resolver->forDate($benefitType, $today)]);

        $rates = BenefitRate::query()
            ->withExists('calculations')
            ->orderByDesc('valid_from')
            ->orderBy('benefit_type')
            ->get();

        return view('livewire.benefits.benefit-rate-index', [
            'rates' => $rates,
            'currentRates' => $currentRates,
            'today' => $today,
            'lastClosedCompetence' => BenefitPeriod::lastClosedCompetence(),
            'benefitTypeOptions' => [
                ['id' => BenefitType::Vr->value, 'name' => BenefitType::Vr->label()],
                ['id' => BenefitType::Vd->value, 'name' => BenefitType::Vd->label()],
            ],
        ]);
    }
}
