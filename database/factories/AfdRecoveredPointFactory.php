<?php

namespace Database\Factories;

use App\Models\AfdCorrectionBatch;
use App\Models\AfdRecoveredPoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AfdRecoveredPoint>
 */
class AfdRecoveredPointFactory extends Factory
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
            'afd_correction_batch_id' => AfdCorrectionBatch::factory()->state(['kind' => AfdCorrectionBatch::KIND_RECOVERY]),
            'point_id' => fake()->unique()->numberBetween(1, 999999),
            'rep_serial' => fake()->numerify('#################'),
            'nsr' => $nsr,
            'afd_line' => $nsr + 1,
            'afd_line_sha256' => hash('sha256', fake()->uuid()),
            'point_hash' => hash('sha256', fake()->uuid()),
            'status' => AfdRecoveredPoint::STATUS_RECOVERED,
        ];
    }
}
