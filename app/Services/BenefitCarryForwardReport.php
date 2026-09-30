<?php

namespace App\Services;

use App\Enums\BenefitPeriodStatus;
use App\Models\BenefitCalculation;
use App\Models\BenefitPeriod;
use Carbon\CarbonImmutable;

/**
 * Situação dos saldos negativos gerados por competências fechadas (Spec §6.2).
 * Somente leitura: não aplica saldo, não altera cálculos e não cria ajustes.
 *
 * - Aplicado: um cálculo de M+1 aponta para a origem (carried_from_calculation_id).
 * - Não aplicado: M+1 está fechada e nenhum cálculo aponta para a origem (sem
 *   elegibilidade ao mesmo benefício em 01/M+1) — pendência administrativa.
 * - Aguardando: M+1 não existe ou ainda não está fechada, e nada aponta para a origem.
 *
 * Saldos de prévias (competências não fechadas) não entram: ainda podem mudar.
 */
class BenefitCarryForwardReport
{
    public const AWAITING = 'Aguardando';

    public const APPLIED = 'Aplicado';

    public const NOT_APPLIED = 'Não aplicado';

    /**
     * @return list<array{employee_id: int, employee_name: string, benefit_type: string, origin_competence: string, days: int, status: string, reason: string}>
     */
    public function rows(?BenefitPeriod $originPeriod = null): array
    {
        $origins = BenefitCalculation::query()
            ->select('benefit_calculations.*', 'benefit_period_employees.employee_id', 'benefit_period_employees.employee_name', 'benefit_periods.competence')
            ->join('benefit_period_employees', 'benefit_period_employees.id', '=', 'benefit_calculations.benefit_period_employee_id')
            ->join('benefit_periods', 'benefit_periods.id', '=', 'benefit_period_employees.benefit_period_id')
            ->where('benefit_periods.status', BenefitPeriodStatus::Closed)
            ->where('benefit_calculations.carried_out_days', '>', 0)
            ->when($originPeriod !== null, fn ($query) => $query->where('benefit_periods.id', $originPeriod->id))
            ->with('carriedToCalculation.benefitPeriodEmployee.benefitPeriod:id,competence')
            ->orderByDesc('benefit_periods.competence')
            ->orderBy('benefit_period_employees.employee_name')
            ->orderBy('benefit_calculations.benefit_type')
            ->get();

        $nextPeriods = BenefitPeriod::query()
            ->whereIn('competence', $origins
                ->map(fn (BenefitCalculation $origin) => $this->competenceOf($origin)->addMonthNoOverflow()->toDateString())
                ->unique()
                ->values())
            ->get(['id', 'competence', 'status'])
            ->keyBy(fn (BenefitPeriod $period) => $period->competence->toDateString());

        return $origins->map(function (BenefitCalculation $origin) use ($nextPeriods) {
            $nextCompetence = $this->competenceOf($origin)->addMonthNoOverflow();
            $next = $nextPeriods->get($nextCompetence->toDateString());
            $appliedIn = $origin->carriedToCalculation?->benefitPeriodEmployee->benefitPeriod;

            [$status, $reason] = match (true) {
                $appliedIn !== null => [self::APPLIED, 'Aplicado na competência '.$appliedIn->competence->format('m/Y').'.'],
                $next?->status === BenefitPeriodStatus::Closed => [self::NOT_APPLIED, 'Sem elegibilidade ao benefício em '.$nextCompetence->format('d/m/Y').'. Pendência administrativa.'],
                $next === null => [self::AWAITING, 'A competência '.$nextCompetence->format('m/Y').' ainda não foi criada.'],
                default => [self::AWAITING, 'A competência '.$nextCompetence->format('m/Y').' ainda não foi fechada.'],
            };

            return [
                'employee_id' => (int) $origin->employee_id,
                'employee_name' => $origin->employee_name,
                'benefit_type' => $origin->benefit_type->value,
                'origin_competence' => $this->competenceOf($origin)->format('m/Y'),
                'days' => $origin->carried_out_days,
                'status' => $status,
                'reason' => $reason,
            ];
        })->values()->all();
    }

    private function competenceOf(BenefitCalculation $origin): CarbonImmutable
    {
        return CarbonImmutable::parse(substr((string) $origin->getAttribute('competence'), 0, 10));
    }
}
