<?php

namespace Database\Factories;

use App\Enums\BenefitType;
use App\Models\BenefitCalculation;
use App\Models\BenefitPeriodEmployee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenefitCalculation>
 */
class BenefitCalculationFactory extends Factory
{
    /**
     * Define the model's default state: VR de 21 dias a R$ 27,50, sem ajustes nem saldo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'benefit_period_employee_id' => BenefitPeriodEmployee::factory(),
            'benefit_type' => BenefitType::Vr,
            'base_days' => 21,
            'positive_days' => 0,
            'negative_days' => 0,
            'carried_in_days' => 0,
            'carried_from_calculation_id' => null,
            'raw_days' => 21,
            'final_days' => 21,
            'carried_out_days' => 0,
            'unit_amount' => '27.50',
            'total_amount' => '577.50',
            'benefit_rate_id' => null,
        ];
    }

    public function ofType(BenefitType $benefitType): static
    {
        return $this->state(fn (array $attributes) => [
            'benefit_type' => $benefitType,
        ]);
    }

    /**
     * Resultado com saldo negativo gerado: quantidade final zero e total zero.
     */
    public function withCarriedOut(int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'negative_days' => $attributes['base_days'] + $days,
            'raw_days' => -$days,
            'final_days' => 0,
            'carried_out_days' => $days,
            'total_amount' => '0.00',
        ]);
    }

    /**
     * Resultado que aplicou o saldo gerado pelo cálculo informado.
     */
    public function carriedFrom(BenefitCalculation $origin): static
    {
        return $this->state(fn (array $attributes) => [
            'benefit_type' => $origin->benefit_type,
            'carried_in_days' => $origin->carried_out_days,
            'carried_from_calculation_id' => $origin->id,
        ]);
    }
}
