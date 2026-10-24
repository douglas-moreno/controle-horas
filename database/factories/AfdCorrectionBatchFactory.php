<?php

namespace Database\Factories;

use App\Models\AfdCorrectionBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AfdCorrectionBatch>
 */
class AfdCorrectionBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => AfdCorrectionBatch::KIND_REGISTRATION,
            'status' => AfdCorrectionBatch::STATUS_APPLIED,
            'manifest_sha256' => hash('sha256', fake()->uuid()),
            'afd_sha256' => hash('sha256', fake()->uuid()),
            'rep_serial' => fake()->numerify('#################'),
            'expected_count' => 1,
            'applied_count' => 1,
            'target' => ['hostname' => 'test', 'server_uuid' => fake()->uuid(), 'database' => 'testing'],
            'applied_by' => fake()->name(),
            'applied_at' => now(),
        ];
    }
}
