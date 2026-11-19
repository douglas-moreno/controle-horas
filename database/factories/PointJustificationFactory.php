<?php

namespace Database\Factories;

use App\Enums\JustificationTreatment;
use App\Models\Employee;
use App\Models\PointJustification;
use App\Models\PointJustificationReason;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PointJustification>
 */
class PointJustificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'reason_id' => PointJustificationReason::factory(),
            'occurrence_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'start_time' => null,
            'end_time' => null,
            'ends_next_day' => false,
            'duration_minutes' => null,
            'punch_kind' => null,
            'treatment' => JustificationTreatment::FullAllowance,
            'allowed_minutes' => null,
            'observation' => null,
            'request_uuid' => (string) Str::uuid(),
            'created_by' => User::factory(),
        ];
    }

    /**
     * Justificativa de um intervalo de horário (H:i) na data informada.
     */
    public function between(string $date, string $start, string $end): static
    {
        return $this->state(fn (array $attributes) => [
            'occurrence_date' => $date,
            'start_time' => $start.':00',
            'end_time' => $end.':00',
            'duration_minutes' => (strtotime($end) - strtotime($start)) / 60,
        ]);
    }

    public function canceled(?User $user = null, string $reason = 'Lançamento indevido.'): static
    {
        return $this->state(fn (array $attributes) => [
            'canceled_at' => now(),
            'canceled_by' => $user ?? User::factory(),
            'cancellation_reason' => $reason,
        ]);
    }
}
