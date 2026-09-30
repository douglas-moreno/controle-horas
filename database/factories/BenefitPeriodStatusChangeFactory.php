<?php

namespace Database\Factories;

use App\Enums\BenefitPeriodStatus;
use App\Models\BenefitPeriod;
use App\Models\BenefitPeriodStatusChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenefitPeriodStatusChange>
 */
class BenefitPeriodStatusChangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'benefit_period_id' => BenefitPeriod::factory(),
            'from_status' => null,
            'to_status' => BenefitPeriodStatus::Open,
            'reason' => null,
            'user_id' => null,
        ];
    }
}
