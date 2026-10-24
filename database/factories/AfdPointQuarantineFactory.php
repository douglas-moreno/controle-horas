<?php

namespace Database\Factories;

use App\Models\AfdCorrectionBatch;
use App\Models\AfdPointQuarantine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AfdPointQuarantine>
 */
class AfdPointQuarantineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'afd_correction_batch_id' => AfdCorrectionBatch::factory()->state(['kind' => AfdCorrectionBatch::KIND_QUARANTINE]),
            'original_id' => fake()->unique()->numberBetween(1, 999999),
            'pis' => (int) fake()->numerify('###########'),
            'date' => fake()->date(),
            'time' => fake()->time('H:i:00'),
            'type' => 'importado',
            'row_hash' => hash('sha256', fake()->uuid()),
            'afd_line' => fake()->numberBetween(2, 999999),
            'afd_record_type' => '5',
            'criterion' => 'teste',
            'status' => AfdPointQuarantine::STATUS_QUARANTINED,
        ];
    }
}
