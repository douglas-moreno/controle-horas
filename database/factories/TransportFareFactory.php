<?php

namespace Database\Factories;

use App\Models\TransportFare;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransportFare>
 */
class TransportFareFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('Linha ####'),
            'operator' => fake()->company(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
