<?php

namespace Database\Factories;

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\AdjustmentStatus;
use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenefitAdjustment>
 */
class BenefitAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Os impactos não são criados aqui: a geração de impactos padrão é regra do
     * registro de ajustes (fase F6). Testes criam os impactos explicitamente.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'benefit_period_id' => BenefitPeriod::factory(),
            'employee_id' => Employee::factory(),
            'reason' => AdjustmentReason::UnjustifiedAbsence,
            'source' => AdjustmentSource::Manual,
            'status' => AdjustmentStatus::Confirmed,
            'starts_on' => '2026-09-15',
            'ends_on' => '2026-09-15',
            'days_count' => 1,
            'notes' => null,
            'dedupe_key' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AdjustmentStatus::Pending,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AdjustmentStatus::Confirmed,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AdjustmentStatus::Rejected,
            'reviewed_at' => now(),
            'review_notes' => 'Rejeitado no teste.',
        ]);
    }

    public function reason(AdjustmentReason $reason): static
    {
        return $this->state(fn (array $attributes) => [
            'reason' => $reason,
        ]);
    }

    public function source(AdjustmentSource $source): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => $source,
        ]);
    }

    /**
     * Intervalo do evento com a quantidade de dias informada pelo teste.
     */
    public function between(string $startsOn, string $endsOn, int $daysCount): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'days_count' => $daysCount,
        ]);
    }

    public function vacation(string $startsOn, string $endsOn, int $daysCount): static
    {
        return $this->reason(AdjustmentReason::Vacation)->between($startsOn, $endsOn, $daysCount);
    }

    public function absence(string $date): static
    {
        return $this->reason(AdjustmentReason::UnjustifiedAbsence)->between($date, $date, 1);
    }

    public function saturdayWorked(string $date): static
    {
        return $this->reason(AdjustmentReason::SaturdayWorked)->between($date, $date, 1);
    }
}
