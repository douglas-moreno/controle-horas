<?php

namespace Database\Factories;

use App\Models\TransportFare;
use App\Models\TransportFarePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransportFarePrice>
 */
class TransportFarePriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transport_fare_id' => TransportFare::factory(),
            'amount' => fake()->randomElement(['4.50', '5.40', '5.90', '8.40', '10.32']),
            'valid_from' => '2026-01-01',
        ];
    }

    public function amount(string $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $amount,
        ]);
    }

    public function validFrom(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_from' => $date,
        ]);
    }
}
