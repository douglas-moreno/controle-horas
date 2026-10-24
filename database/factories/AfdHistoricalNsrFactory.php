<?php

namespace Database\Factories;

use App\Models\AfdCorrectionBatch;
use App\Models\AfdHistoricalNsr;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AfdHistoricalNsr>
 */
class AfdHistoricalNsrFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nsr = fake()->unique()->numberBetween(1, 999999);

        return [
            'afd_correction_batch_id' => AfdCorrectionBatch::factory(),
            'rep_serial' => fake()->numerify('#################'),
            'nsr' => $nsr,
            'afd_line' => $nsr + 1,
            'afd_line_sha256' => hash('sha256', fake()->uuid()),
            'classification' => 'C1',
            'evidence' => 'teste',
            'status' => AfdHistoricalNsr::STATUS_PENDING,
        ];
    }

    /**
     * Decisão documentada do RH de manter o ponto manual informado.
     */
    public function manualRetained(int $manualPointId): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AfdHistoricalNsr::STATUS_MANUAL_RETAINED,
            'related_manual_point_id' => $manualPointId,
            'decided_by' => fake()->name(),
            'decision_justification' => 'Ponto manual confirmado pelo RH.',
            'decided_at' => now(),
        ]);
    }

    /**
     * Decisão documentada do RH de não recuperar a marcação.
     */
    public function discarded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AfdHistoricalNsr::STATUS_DISCARDED,
            'decided_by' => fake()->name(),
            'decision_justification' => 'Marcação descartada pelo RH.',
            'decided_at' => now(),
        ]);
    }

    /**
     * Marcação retida por decisão documentada do RH (classificação R).
     */
    public function withheld(): static
    {
        return $this->state(fn (array $attributes) => [
            'classification' => AfdHistoricalNsr::CLASSIFICATION_WITHHELD,
            'evidence' => 'Comunicação formal do RH.',
            'status' => AfdHistoricalNsr::STATUS_WITHHELD,
            'decided_by' => fake()->name(),
            'decision_justification' => 'Legitimidade não confirmada pelo RH.',
            'decided_at' => now(),
        ]);
    }
}
