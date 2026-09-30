<?php

namespace App\Livewire\Benefits;

use App\Models\BenefitPeriod;
use App\Services\BenefitPeriodWorkflow;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use WireUi\Traits\WireUiActions;

class BenefitPeriodIndex extends Component
{
    use WireUiActions;

    /**
     * Mês da primeira competência (Y-m). Usado somente quando ainda não existe
     * nenhuma competência; depois disso a sequência é automática.
     */
    public string $firstCompetenceMonth = '';

    public function createNext(BenefitPeriodWorkflow $workflow): void
    {
        $firstCompetence = null;

        if (! BenefitPeriod::query()->exists()) {
            $this->validate([
                'firstCompetenceMonth' => ['required', 'date_format:Y-m'],
            ], [
                'firstCompetenceMonth.required' => 'Informe o mês da primeira competência.',
                'firstCompetenceMonth.date_format' => 'Informe o mês no formato mês/ano.',
            ]);

            $firstCompetence = CarbonImmutable::createFromFormat('!Y-m-d', $this->firstCompetenceMonth.'-01');
        }

        try {
            $period = $workflow->createNext($firstCompetence);
        } catch (DomainException $exception) {
            $this->notification()->error(
                $title = 'Competência Não Criada',
                $description = $exception->getMessage()
            );

            return;
        }

        $this->reset('firstCompetenceMonth');

        $this->notification()->success(
            $title = 'Competência Criada',
            $description = 'A competência '.$period->competence->format('m/Y').' foi criada.'
        );
    }

    public function destroy(int $benefitPeriodId, BenefitPeriodWorkflow $workflow): void
    {
        $period = BenefitPeriod::findOrFail($benefitPeriodId);

        try {
            $workflow->delete($period);
        } catch (DomainException $exception) {
            $this->notification()->error(
                $title = 'Exclusão Não Permitida',
                $description = $exception->getMessage()
            );

            return;
        }

        $this->notification()->success(
            $title = 'Competência Excluída',
            $description = 'A competência '.$period->competence->format('m/Y').' foi excluída.'
        );
    }

    public function render(): View
    {
        $workflow = app(BenefitPeriodWorkflow::class);
        $periods = BenefitPeriod::query()->orderByDesc('competence')->get();

        return view('livewire.benefits.benefit-period-index', [
            'periods' => $periods,
            'deletablePeriodIds' => $periods
                ->filter(fn (BenefitPeriod $period) => $workflow->deletionBlocker($period) === null)
                ->modelKeys(),
            'nextCompetence' => $periods->first()?->competence->addMonthNoOverflow(),
        ]);
    }
}
