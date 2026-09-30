<?php

namespace App\Services;

use App\Enums\BenefitType;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Elegibilidade a VT, VR e VD, independente por tipo, determinada exclusivamente pelas
 * vigências de EmployeeBenefit na data de referência (01/M para a competência M).
 *
 * Data de rescisão e admissão não participam da regra: um funcionário desligado com
 * vigência em aberto continua elegível até o administrador encerrar a vigência.
 */
class BenefitEligibility
{
    public function isEligible(Employee $employee, BenefitType $benefitType, CarbonImmutable $referenceDate): bool
    {
        return $employee->employeeBenefits()
            ->where('benefit_type', $benefitType)
            ->activeOn($referenceDate)
            ->exists();
    }

    /**
     * @return list<BenefitType>
     */
    public function eligibleTypes(Employee $employee, CarbonImmutable $referenceDate): array
    {
        return $this->sortedTypes(
            $employee->employeeBenefits()->activeOn($referenceDate)->pluck('benefit_type')
        );
    }

    /**
     * Funcionários com ao menos um benefício vigente na data, com uma única consulta.
     *
     * @return Collection<int, list<BenefitType>> chave = id do funcionário
     */
    public function participants(CarbonImmutable $referenceDate): Collection
    {
        return EmployeeBenefit::query()
            ->activeOn($referenceDate)
            ->orderBy('employee_id')
            ->get(['employee_id', 'benefit_type'])
            ->groupBy('employee_id')
            ->map(fn (Collection $benefits) => $this->sortedTypes($benefits->pluck('benefit_type')));
    }

    /**
     * @param  Collection<int, BenefitType>  $benefitTypes
     * @return list<BenefitType>
     */
    private function sortedTypes(Collection $benefitTypes): array
    {
        return collect(BenefitType::cases())
            ->filter(fn (BenefitType $benefitType) => $benefitTypes->contains($benefitType))
            ->values()
            ->all();
    }
}
