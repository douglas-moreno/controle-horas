<?php

use App\Livewire\EmployeeHorasExtras;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Point;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    Holiday::factory()->create(['date' => '2026-07-09', 'description' => 'Feriado municipal']);

    $this->employee = Employee::factory()->create(['recision_date' => null]);

    foreach ([
        '2026-07-06' => ['07:40', '12:00', '13:00', '18:00'],
        '2026-07-07' => ['09:10', '12:00', '13:00', '17:50'],
        '2026-07-08' => ['07:30', '16:30'],
        '2026-07-09' => ['08:00', '12:00'],
        '2026-07-10' => ['07:30'],
        '2026-07-11' => ['07:00', '12:00'],
    ] as $date => $times) {
        foreach ($times as $time) {
            Point::factory()->forEmployee($this->employee)->on($date)->create(['time' => $time.':00']);
        }
    }
});

test('the overtime screen lists every day with overtime, delay and occurrences', function () {
    $component = Livewire::withQueryParams(['startDate' => '2026-07-06', 'endDate' => '2026-07-13'])
        ->test(EmployeeHorasExtras::class, ['employee' => $this->employee->id]);

    $days = collect($component->get('days'))->keyBy('date');

    expect($days->keys()->all())->toHaveCount(8)
        ->and($days['2026-07-06']['delay'])->toBe('00:05')
        ->and($days['2026-07-07']['delay'])->toBe('01:35')
        ->and($days['2026-07-08']['delay'])->toBe('01:00')
        ->and($days['2026-07-09']['delay'])->toBe('')
        ->and($days['2026-07-10']['delay'])->toBe('')
        ->and($days['2026-07-13']['status_label'])->toBe('Sem marcação');

    $component
        ->assertSet('totalExtraMinutes', 560)
        ->assertSet('totalWeekdayMinutes', 20)
        ->assertSet('totalHolidayMinutes', 240)
        ->assertSet('totalSaturdayMinutes', 300)
        ->assertSet('totalDelayMinutes', 160)
        ->assertSet('totalDelayHours', '02:40')
        ->assertSet('reviewDays', 4)
        ->assertSeeInOrder(['Atraso', '02:40', '4 dias para conferência'])
        ->assertSeeInOrder(['07/07/2026 Ter', '09:10', '12:00', '13:00', '17:50', '00:00', '01:35'])
        ->assertSeeInOrder(['09/07/2026 Qui', '08:00', '12:00', '04:00', 'Trabalho fora da jornada'])
        ->assertSeeInOrder(['10/07/2026 Sex', '07:30', 'Marcações incompletas'])
        ->assertSeeInOrder(['13/07/2026 Seg', 'Sem marcação']);
});

test('the overtime screen and the print page show the same values', function () {
    $days = Livewire::test(EmployeeHorasExtras::class, ['employee' => $this->employee->id])
        ->set('startDate', '2026-07-06')
        ->set('endDate', '2026-07-12')
        ->get('days');

    $response = $this->get(route('reports.timesheet.print', [
        'start' => '2026-07-06',
        'end' => '2026-07-12',
        'employees' => [$this->employee->id],
    ]))->assertOk();

    foreach ($days as $day) {
        $expected = array_values(array_filter([
            $day['display_date'],
            $day['entrada'],
            $day['saida'],
            $day['overtime_minutes'] > 0 ? $day['overtime'] : null,
            $day['delay_minutes'] ? $day['delay'] : null,
            $day['status_label'] ?: null,
        ]));

        $response->assertSeeInOrder($expected);
    }

    $response->assertSeeInOrder(['Totais do período:', '09:20', '02:40', '3 dias para conferência']);
});

test('an invalid period clears the screen', function () {
    Livewire::withQueryParams(['startDate' => '2026-07-12', 'endDate' => '2026-07-06'])
        ->test(EmployeeHorasExtras::class, ['employee' => $this->employee->id])
        ->assertSet('days', [])
        ->assertSet('totalExtraMinutes', 0)
        ->assertSee('Nenhum dia no período.');
});
