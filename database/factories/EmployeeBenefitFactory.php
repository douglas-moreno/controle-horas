<?php

namespace Database\Factories;

use App\Enums\BenefitType;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeBenefit>
 */
class EmployeeBenefitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'benefit_type' => BenefitType::Vr,
            'starts_on' => '2026-01-01',
            'ends_on' => null,
            'notes' => null,
        ];
    }

    public function ofType(BenefitType $benefitType): static
    {
        return $this->state(fn (array $attributes) => [
            'benefit_type' => $benefitType,
        ]);
    }

    public function between(string $startsOn, ?string $endsOn = null): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]);
    }
}
