<?php

namespace Database\Factories;

use App\Enums\BenefitType;
use App\Models\BenefitRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenefitRate>
 */
class BenefitRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A vigência padrão é o dia 01 de um mês distinto a cada registro, evitando colisão
     * com o UNIQUE(benefit_type, valid_from).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'benefit_type' => BenefitType::Vr,
            'amount' => '27.50',
            'valid_from' => now()->startOfMonth()->subMonths(fake()->unique()->numberBetween(0, 600))->toDateString(),
        ];
    }

    public function vr(string $amount = '27.50'): static
    {
        return $this->state(fn (array $attributes) => [
            'benefit_type' => BenefitType::Vr,
            'amount' => $amount,
        ]);
    }

    public function vd(string $amount = '7.50'): static
    {
        return $this->state(fn (array $attributes) => [
            'benefit_type' => BenefitType::Vd,
            'amount' => $amount,
        ]);
    }

    public function validFrom(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_from' => $date,
        ]);
    }
}
