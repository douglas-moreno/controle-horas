<?php

namespace Database\Factories;

use App\Models\AfdCorrectionBatch;
use App\Models\AfdHistoricalNsr;
use App\Models\AfdHistoricalNsrDecision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AfdHistoricalNsrDecision>
 */
class AfdHistoricalNsrDecisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $consequence = ['note' => 'teste'];

        return [
            'afd_historical_nsr_id' => AfdHistoricalNsr::factory(),
            'afd_correction_batch_id' => AfdCorrectionBatch::factory()->state(['kind' => AfdCorrectionBatch::KIND_DECISION]),
            'decision' => AfdHistoricalNsrDecision::DECISION_DEFER,
            'status_before' => AfdHistoricalNsr::STATUS_PENDING,
            'status_after' => AfdHistoricalNsr::STATUS_PENDING,
            'decided_by' => fake()->name(),
            'decided_at' => now()->subDay(),
            'justification' => 'Análise do RH.',
            'expected_consequence' => $consequence,
            'expected_consequence_sha256' => hash('sha256', (string) json_encode($consequence)),
            'status' => AfdHistoricalNsrDecision::STATUS_ACTIVE,
        ];
    }
}
