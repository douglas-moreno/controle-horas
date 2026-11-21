<?php

namespace App\Livewire\Benefits;

use App\Enums\BenefitPeriodStatus;
use App\Enums\BenefitType;
use App\Enums\DatabaseFailure;
use App\Models\BenefitPeriod;
use App\Services\BenefitPeriodCalculator;
use App\Services\BenefitPeriodWorkflow;
use App\Services\Money;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Component;
use WireUi\Traits\WireUiActions;

/**
 * Apuração da competência: prévia calculada, detalhe por funcionário e pendências.
 * O cálculo é sempre disparado pelo BenefitPeriodWorkflow.
 */
class BenefitPeriodCalculation extends Component
{
    use WireUiActions;

    public BenefitPeriod $benefitPeriod;

    /**
     * Impedimentos encontrados na última tentativa de fechar.
     *
     * @var list<string>
     */
    public array $closeBlockers = [];

    public bool $showReopenModal = false;

    public string $reopenReason = '';

    public function mount(BenefitPeriod $benefitPeriod): void
    {
        $this->benefitPeriod = $benefitPeriod;
    }

    public function calculate(BenefitPeriodWorkflow $workflow): void
    {
        try {
            $result = $workflow->calculate($this->benefitPeriod);
        } catch (DomainException $exception) {
            $this->notification()->error(
                $title = 'Cálculo Não Realizado',
                $description = $exception->getMessage()
            );

            return;
        }

        $this->benefitPeriod->refresh();
        $this->closeBlockers = [];

        $blocking = collect($result['issues'])->where('severity', 'blocking')->count();

        $this->notification()->success(
            $title = 'Prévia Calculada',
            $description = $blocking > 0
                ? 'Prévia calculada com '.$blocking.' pendência(s) de configuração que impedem o fechamento.'
                : 'A competência '.$this->benefitPeriod->competence->format('m/Y').' foi calculada.'
        );
    }

    /**
     * Fecha pela BenefitPeriodWorkflow. Falha de banco previsível (espera de bloqueio,
     * deadlock ou integridade por gravação concorrente) desfaz a transação inteira e vira
     * impedimento na tela, registrada no log; qualquer outra falha segue como erro.
     */
    public function close(BenefitPeriodWorkflow $workflow): void
    {
        $this->closeBlockers = $workflow->closeBlockers($this->benefitPeriod);

        if ($this->closeBlockers === []) {
            try {
                $workflow->close($this->benefitPeriod);
            } catch (DomainException $exception) {
                $this->closeBlockers = [$exception->getMessage()];
            } catch (QueryException $exception) {
                $this->closeBlockers = [$this->databaseFailureMessage($exception)];
            }
        }

        $this->benefitPeriod->refresh();

        if ($this->closeBlockers !== []) {
            $this->notification()->error(
                $title = 'Competência Não Fechada',
                $description = 'Não é possível fechar a competência. Veja os impedimentos na tela.'
            );

            return;
        }

        $this->notification()->success(
            $title = 'Competência Fechada',
            $description = 'A competência '.$this->benefitPeriod->competence->format('m/Y').' foi recalculada e fechada.'
        );
    }

    /**
     * @throws QueryException quando a falha não é uma das previsíveis
     */
    private function databaseFailureMessage(QueryException $exception): string
    {
        $failure = DatabaseFailure::fromException($exception) ?? throw $exception;

        report($exception);

        return match ($failure) {
            DatabaseFailure::Concurrency => 'Outra operação estava usando os dados desta competência (espera de bloqueio esgotada ou conflito). Nada foi gravado; tente fechar novamente.',
            DatabaseFailure::Integrity => 'Outra operação gravou ao mesmo tempo um registro relacionado e o fechamento foi desfeito. Nada foi gravado; confira a competência e tente novamente.',
        };
    }

    public function openReopenModal(): void
    {
        $this->resetValidation();
        $this->reopenReason = '';
        $this->showReopenModal = true;
    }

    public function reopen(BenefitPeriodWorkflow $workflow): void
    {
        $this->validate([
            'reopenReason' => ['required', 'string', 'max:1000'],
        ], [
            'reopenReason.required' => 'Informe o motivo da reabertura.',
            'reopenReason.max' => 'O motivo deve ter no máximo 1000 caracteres.',
        ]);

        try {
            $workflow->reopen($this->benefitPeriod, $this->reopenReason);
        } catch (DomainException $exception) {
            $this->addError('reopenReason', $exception->getMessage());

            return;
        }

        $this->showReopenModal = false;
        $this->closeBlockers = [];
        $this->benefitPeriod->refresh();

        $this->notification()->success(
            $title = 'Competência Reaberta',
            $description = 'A competência '.$this->benefitPeriod->competence->format('m/Y').' voltou para Aberta e a apuração foi descartada.'
        );
    }

    public function render(BenefitPeriodCalculator $calculator): View
    {
        $period = $this->benefitPeriod;

        $periodEmployees = $period->periodEmployees()
            ->with(['calculations.transportItems', 'calculations.carriedFromCalculation.benefitPeriodEmployee.benefitPeriod:id,competence'])
            ->orderBy('employee_name')
            ->get();

        $totalDays = ['vt' => 0, 'vr' => 0, 'vd' => 0];
        $totalCents = ['vt' => 0, 'vr' => 0, 'vd' => 0];
        $employeeTotals = [];

        foreach ($periodEmployees as $periodEmployee) {
            $employeeTotals[$periodEmployee->id] = 0;

            foreach ($periodEmployee->calculations as $calculation) {
                $cents = Money::toCents($calculation->total_amount);
                $totalDays[$calculation->benefit_type->value] += $calculation->final_days;
                $totalCents[$calculation->benefit_type->value] += $cents;
                $employeeTotals[$periodEmployee->id] += $cents;
            }
        }

        $issues = $period->status->isEditable() ? $calculator->issues($period) : [];

        $discardedStatusChange = $period->status === BenefitPeriodStatus::Open
            ? $period->statusChanges()->whereNotNull('from_status')->latest('id')->first()
            : null;

        return view('livewire.benefits.benefit-period-calculation', [
            'period' => $period,
            'isEditable' => $period->status->isEditable(),
            'periodEmployees' => $periodEmployees,
            'benefitTypes' => BenefitType::cases(),
            'totalDays' => $totalDays,
            'totalCents' => $totalCents,
            'employeeTotals' => $employeeTotals,
            'grandTotalCents' => array_sum($totalCents),
            'issues' => $issues,
            'discardedStatusChange' => $discardedStatusChange,
            'issueKeys' => collect($issues)
                ->filter(fn (array $issue) => $issue['severity'] === 'blocking' && $issue['employee_id'] !== null)
                ->map(fn (array $issue) => $issue['employee_id'].':'.$issue['benefit_type'])
                ->unique()
                ->values()
                ->all(),
        ]);
    }
}
