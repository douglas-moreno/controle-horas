<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * O PIS começa com dígito diferente de zero porque `points.pis` é BIGINT:
     * um zero à esquerda seria perdido e a batida deixaria de casar com o funcionário.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pis' => fake()->unique()->numerify('1##########'),
            'name' => fake()->name(),
            'position' => fake()->randomElement([
                'Soldador',
                'Caldeireiro',
                'Almoxarife',
                'Op. de Corte',
                'Inspetor de Qualidade',
                'Ajustador Mecânico',
            ]),
            'recision_date' => null,
        ];
    }

    /**
     * Funcionário desligado, com data de rescisão preenchida.
     */
    public function terminated(?string $recisionDate = null): static
    {
        return $this->state(fn (array $attributes) => [
            'recision_date' => $recisionDate ?? fake()->dateTimeBetween('-2 years', '-1 day')->format('Y-m-d'),
        ]);
    }
}
