<?php

namespace Database\Factories;

use App\Models\AfdRep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AfdRep>
 */
class AfdRepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rep_serial' => fake()->unique()->numerify('#################'),
            'processed_to_nsr' => null,
        ];
    }

    /**
     * REP com importações concluídas até o NSR informado.
     */
    public function processedTo(int $nsr): static
    {
        return $this->state(fn (array $attributes) => [
            'processed_to_nsr' => $nsr,
        ]);
    }
}
