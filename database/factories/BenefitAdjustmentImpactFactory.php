<?php

namespace Database\Factories;

use App\Enums\BenefitType;
use App\Models\BenefitAdjustment;
use App\Models\BenefitAdjustmentImpact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenefitAdjustmentImpact>
 */
class BenefitAdjustmentImpactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'benefit_adjustment_id' => BenefitAdjustment::factory(),
            'benefit_type' => BenefitType::Vt,
            'quantity' => -1,
        ];
    }

    public function ofType(BenefitType $benefitType, int $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'benefit_type' => $benefitType,
            'quantity' => $quantity,
        ]);
    }
}
