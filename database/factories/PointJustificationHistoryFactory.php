<?php

namespace Database\Factories;

use App\Enums\JustificationHistoryAction;
use App\Models\PointJustification;
use App\Models\PointJustificationHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PointJustificationHistory>
 */
class PointJustificationHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'point_justification_id' => PointJustification::factory(),
            'action' => JustificationHistoryAction::Created,
            'old_values' => null,
            'new_values' => ['treatment' => 'full_allowance'],
            'description' => null,
            'manual_point_id' => null,
            'affected_competence' => null,
            'closed_period' => false,
            'performed_by' => User::factory(),
            'performed_by_name' => fn (array $attributes) => User::query()->find($attributes['performed_by'])?->name ?? fake()->name(),
        ];
    }
}
