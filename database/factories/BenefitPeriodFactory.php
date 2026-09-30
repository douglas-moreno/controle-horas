<?php

namespace Database\Factories;

use App\Enums\BenefitPeriodStatus;
use App\Models\BenefitPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenefitPeriod>
 */
class BenefitPeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A competência padrão é um mês distinto a cada registro (UNIQUE em competence).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competence' => now()->startOfMonth()->subMonths(fake()->unique()->numberBetween(0, 600))->toDateString(),
            'status' => BenefitPeriodStatus::Open,
            'business_days' => null,
            'notes' => null,
        ];
    }

    /**
     * Competência de um mês específico, informado como Y-m (ex.: 2026-10).
     */
    public function forCompetence(string $yearMonth): static
    {
        return $this->state(fn (array $attributes) => [
            'competence' => $yearMonth.'-01',
        ]);
    }

    public function calculated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BenefitPeriodStatus::Calculated,
            'calculated_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BenefitPeriodStatus::Closed,
            'calculated_at' => now(),
            'closed_at' => now(),
        ]);
    }
}
