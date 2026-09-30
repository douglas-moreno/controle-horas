<?php

namespace Database\Factories;

use App\Models\BenefitPeriod;
use App\Models\BenefitPeriodEmployee;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenefitPeriodEmployee>
 */
class BenefitPeriodEmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Os campos de snapshot copiam o funcionário referenciado.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'benefit_period_id' => BenefitPeriod::factory(),
            'employee_id' => Employee::factory(),
            'employee_name' => fn (array $attributes) => Employee::find($attributes['employee_id'])->name,
            'pis' => fn (array $attributes) => Employee::find($attributes['employee_id'])->pis,
            'position' => fn (array $attributes) => Employee::find($attributes['employee_id'])->position,
        ];
    }
}
