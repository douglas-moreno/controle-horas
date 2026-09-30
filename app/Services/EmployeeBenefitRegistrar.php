<?php

namespace App\Services;

use App\Enums\BenefitType;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cria e encerra vigências de elegibilidade. O banco não impede sobreposição de
 * intervalos, então a regra "uma vigência por funcionário e tipo em cada dia" vive aqui.
 */
class EmployeeBenefitRegistrar
{
    public function overlaps(Employee $employee, BenefitType $benefitType, CarbonImmutable $startsOn, ?CarbonImmutable $endsOn, ?int $ignoreId = null): bool
    {
        return $employee->employeeBenefits()
            ->where('benefit_type', $benefitType)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->when($endsOn !== null, fn ($query) => $query->where('starts_on', '<=', $endsOn->toDateString()))
            ->where(fn ($query) => $query
                ->whereNull('ends_on')
                ->orWhere('ends_on', '>=', $startsOn->toDateString()))
            ->exists();
    }

    /**
     * @throws InvalidArgumentException quando o fim é anterior ao início ou há sobreposição
     */
    public function register(Employee $employee, BenefitType $benefitType, CarbonImmutable $startsOn, ?CarbonImmutable $endsOn, ?string $notes = null, ?int $userId = null): EmployeeBenefit
    {
        $this->ensureValidRange($startsOn, $endsOn);

        return DB::transaction(function () use ($employee, $benefitType, $startsOn, $endsOn, $notes, $userId) {
            if ($this->overlaps($employee, $benefitType, $startsOn, $endsOn)) {
                throw new InvalidArgumentException('Já existe uma vigência de '.$benefitType->label().' que se sobrepõe a este período.');
            }

            $employeeBenefit = new EmployeeBenefit([
                'benefit_type' => $benefitType,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'notes' => $notes,
            ]);
            $employeeBenefit->employee()->associate($employee);
            $employeeBenefit->created_by = $userId;
            $employeeBenefit->updated_by = $userId;
            $employeeBenefit->save();

            return $employeeBenefit;
        });
    }

    /**
     * Encerra a vigência na data informada, sem criar uma nova linha.
     *
     * @throws InvalidArgumentException quando o fim é anterior ao início ou há sobreposição
     */
    public function end(EmployeeBenefit $employeeBenefit, CarbonImmutable $endsOn, ?int $userId = null): EmployeeBenefit
    {
        $this->ensureValidRange($employeeBenefit->starts_on, $endsOn);

        if ($this->overlaps($employeeBenefit->employee, $employeeBenefit->benefit_type, $employeeBenefit->starts_on, $endsOn, $employeeBenefit->id)) {
            throw new InvalidArgumentException('O encerramento faria esta vigência se sobrepor a outra de '.$employeeBenefit->benefit_type->label().'.');
        }

        $employeeBenefit->ends_on = $endsOn;
        $employeeBenefit->updated_by = $userId;
        $employeeBenefit->save();

        return $employeeBenefit;
    }

    private function ensureValidRange(CarbonImmutable $startsOn, ?CarbonImmutable $endsOn): void
    {
        if ($endsOn !== null && $endsOn->lessThan($startsOn)) {
            throw new InvalidArgumentException('O fim da vigência não pode ser anterior ao início.');
        }
    }
}
