<?php

namespace Database\Factories;

use App\Enums\AfdRejectionReason;
use App\Models\AfdImport;
use App\Models\AfdImportRejection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AfdImportRejection>
 */
class AfdImportRejectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'afd_import_id' => AfdImport::factory(),
            'afd_rep_id' => fn (array $attributes) => AfdImport::find($attributes['afd_import_id'])->afd_rep_id,
            'nsr' => fake()->unique()->numberBetween(1, 999999),
            'reason' => AfdRejectionReason::InvalidDate,
        ];
    }
}
