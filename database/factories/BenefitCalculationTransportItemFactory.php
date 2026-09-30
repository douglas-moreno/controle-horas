<?php

namespace Database\Factories;

use App\Enums\BenefitType;
use App\Models\BenefitCalculation;
use App\Models\BenefitCalculationTransportItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenefitCalculationTransportItem>
 */
class BenefitCalculationTransportItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'benefit_calculation_id' => BenefitCalculation::factory()->ofType(BenefitType::Vt),
            'transport_route_id' => null,
            'transport_fare_id' => null,
            'fare_name' => 'CPTM',
            'fare_amount' => '5.40',
            'trips_per_day' => 2,
            'daily_amount' => '10.80',
        ];
    }
}
