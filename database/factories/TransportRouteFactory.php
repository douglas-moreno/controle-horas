<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\TransportFare;
use App\Models\TransportRoute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransportRoute>
 */
class TransportRouteFactory extends Factory
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
            'transport_fare_id' => TransportFare::factory(),
            'trips_per_day' => 2,
            'starts_on' => '2026-01-01',
            'ends_on' => null,
            'notes' => null,
        ];
    }

    public function tripsPerDay(int $tripsPerDay): static
    {
        return $this->state(fn (array $attributes) => [
            'trips_per_day' => $tripsPerDay,
        ]);
    }

    public function between(string $startsOn, ?string $endsOn = null): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]);
    }
}
