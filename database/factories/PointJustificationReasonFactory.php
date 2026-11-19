<?php

namespace Database\Factories;

use App\Enums\JustificationCategory;
use App\Enums\JustificationTimeMode;
use App\Enums\JustificationTreatment;
use App\Models\PointJustificationReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PointJustificationReason>
 */
class PointJustificationReasonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(3),
            'name' => fake()->unique()->sentence(3),
            'category' => JustificationCategory::Absence,
            'description' => null,
            'time_mode' => JustificationTimeMode::FullDayOrInterval,
            'requires_document' => false,
            'default_treatment' => JustificationTreatment::FullAllowance,
            'allowed_treatments' => null,
            'creates_manual_point' => false,
            'active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * Motivo de esquecimento de marcação: um horário e cria a batida manual.
     */
    public function forgottenPunch(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => JustificationCategory::Punch,
            'time_mode' => JustificationTimeMode::SinglePunch,
            'default_treatment' => JustificationTreatment::NoAllowance,
            'creates_manual_point' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }

    public function requiringDocument(): static
    {
        return $this->state(fn (array $attributes) => ['requires_document' => true]);
    }
}
