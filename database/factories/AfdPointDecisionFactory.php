<?php

namespace Database\Factories;

use App\Models\AfdCorrectionBatch;
use App\Models\AfdPointDecision;
use App\Models\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AfdPointDecision>
 */
class AfdPointDecisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'afd_correction_batch_id' => AfdCorrectionBatch::factory()->state(['kind' => AfdCorrectionBatch::KIND_POINT_ANNOTATION]),
            'point_id' => Point::factory(),
            'rep_serial' => '00004000000012345',
            'nsr' => fake()->unique()->numberBetween(1, 999999),
            'afd_line' => fake()->numberBetween(2, 999999),
            'afd_line_sha256' => hash('sha256', fake()->uuid()),
            'point_hash' => hash('sha256', fake()->uuid()),
            'decision' => AfdPointDecision::DECISION_DISREGARD,
            'decided_by' => fake()->name(),
            'decided_at' => now()->subDay(),
            'justification' => 'Análise do RH.',
            'status' => AfdPointDecision::STATUS_ACTIVE,
        ];
    }

    /**
     * Batida duplicada de outro ponto.
     */
    public function duplicateOf(int $pointId): static
    {
        return $this->state(fn (array $attributes) => [
            'decision' => AfdPointDecision::DECISION_DUPLICATE,
            'duplicate_of_point_id' => $pointId,
            'duplicate_of_point_hash' => hash('sha256', 'duplicate-of-'.$pointId),
        ]);
    }
}
