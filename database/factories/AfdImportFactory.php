<?php

namespace Database\Factories;

use App\Models\AfdImport;
use App\Models\AfdRep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AfdImport>
 */
class AfdImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'afd_rep_id' => AfdRep::factory(),
            'file_hash' => hash('sha256', fake()->uuid()),
            'file_size' => 1000,
            'file_starts_on' => '2026-09-01',
            'file_ends_on' => '2026-09-30',
            'file_generated_at' => '2026-09-30 18:00:00',
            'transition' => AfdImport::TRANSITION_FRESH,
            'cursor_before' => 0,
            'cursor_after' => 0,
            'completed_at' => now(),
        ];
    }
}
