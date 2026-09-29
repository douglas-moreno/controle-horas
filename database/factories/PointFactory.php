<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Point>
 */
class PointFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pis' => fake()->numerify('1##########'),
            'date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'time' => fake()->time('H:i:s'),
            'type' => 'importado',
        ];
    }

    /**
     * Batida lançada manualmente pela tela de edição de pontos.
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'manual',
        ]);
    }

    /**
     * Batida em uma data específica (Y-m-d).
     */
    public function on(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
        ]);
    }

    /**
     * Batida do funcionário informado. O ponto se relaciona ao funcionário pelo PIS.
     */
    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (array $attributes) => [
            'pis' => $employee->pis,
        ]);
    }
}
