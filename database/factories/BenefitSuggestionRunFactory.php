<?php

namespace Database\Factories;

use App\Models\BenefitPeriod;
use App\Models\BenefitSuggestionRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenefitSuggestionRun>
 */
class BenefitSuggestionRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'benefit_period_id' => BenefitPeriod::factory(),
            'user_id' => null,
            'last_imported_on' => '2026-09-29',
            'horizon_on' => '2026-09-28',
            'processed_from' => '2026-09-01',
            'processed_to' => '2026-09-28',
            'created' => 0,
            'skipped_existing' => 0,
            'skipped_covered' => 0,
            'skipped_unobserved_days' => 0,
        ];
    }

    /**
     * Execução que processou a janela até a data informada (Y-m-d).
     */
    public function processedUntil(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'processed_to' => $date,
            'horizon_on' => $date,
            'last_imported_on' => CarbonImmutable::parse($date)->addDay()->toDateString(),
        ]);
    }
}
