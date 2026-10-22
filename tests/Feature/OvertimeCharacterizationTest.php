<?php

use App\Livewire\EmployeeHorasExtras;
use App\Livewire\EmployeeResumeReport;
use App\Livewire\EmployeesExtraReport;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Point;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Caracterização da regra vigente de horas extras, que é calculada hoje em três
 * componentes. Os valores esperados registram o comportamento existente e não uma
 * regra de negócio nova: servem para garantir que a extração para um serviço
 * compartilhado não altere nenhum resultado.
 *
 * Datas usadas: 2026-07-08 quarta, 2026-07-09 quinta (feriado nos casos marcados),
 * 2026-07-10 sexta, 2026-07-11 sábado e 2026-07-12 domingo.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * @param  array<int, string>  $times
 */
function characterizationEmployee(string $date, array $times): Employee
{
    $employee = Employee::factory()->create();

    foreach ($times as $time) {
        Point::factory()->forEmployee($employee)->on($date)->create(['time' => $time.':00']);
    }

    return $employee;
}

dataset('current overtime rule', [
    'quarta jornada exata' => ['2026-07-08', ['07:30', '12:00', '13:00', '17:30'], false, 0, 'weekday'],
    'quarta com 1h30 a mais' => ['2026-07-08', ['07:00', '12:00', '13:00', '18:30'], false, 90, 'weekday'],
    'quarta almoço de 30 min' => ['2026-07-08', ['07:30', '12:00', '12:30', '17:30'], false, 30, 'weekday'],
    'quarta almoço longo não gera saldo negativo' => ['2026-07-08', ['07:30', '12:00', '14:00', '17:30'], false, 0, 'weekday'],
    'quarta atraso compensado pela saída' => ['2026-07-08', ['09:10', '12:00', '13:00', '17:50'], false, 0, 'weekday'],
    'quarta 2 marcações desconta 1h acima de 6h' => ['2026-07-08', ['07:30', '17:30'], false, 0, 'weekday'],
    'quarta 2 marcações com 1h a mais' => ['2026-07-08', ['07:00', '18:00'], false, 60, 'weekday'],
    'quarta 2 marcações exatamente 6h' => ['2026-07-08', ['08:00', '14:00'], false, 0, 'weekday'],
    'quarta 1 marcação' => ['2026-07-08', ['07:30'], false, 0, 'weekday'],
    'quarta 3 marcações trata a terceira como saída' => ['2026-07-08', ['07:00', '12:00', '18:00'], false, 60, 'weekday'],
    'quarta 5 marcações usa só as 4 primeiras' => ['2026-07-08', ['07:00', '12:00', '13:00', '18:00', '19:00'], false, 60, 'weekday'],
    'sexta jornada exata' => ['2026-07-10', ['07:30', '12:00', '13:00', '16:30'], false, 0, 'weekday'],
    'sexta até 17:30' => ['2026-07-10', ['07:30', '12:00', '13:00', '17:30'], false, 60, 'weekday'],
    'sexta 2 marcações' => ['2026-07-10', ['07:30', '17:00'], false, 30, 'weekday'],
    'sábado 5h sem almoço' => ['2026-07-11', ['07:00', '12:00'], false, 300, 'saturday'],
    'sábado exatamente 6h não desconta' => ['2026-07-11', ['08:00', '14:00'], false, 360, 'saturday'],
    'sábado acima de 6h desconta 1h' => ['2026-07-11', ['08:00', '14:01'], false, 301, 'saturday'],
    'sábado 1 marcação' => ['2026-07-11', ['07:00'], false, 0, 'saturday'],
    'domingo com almoço' => ['2026-07-12', ['08:00', '12:00', '13:00', '17:00'], false, 480, 'sunday'],
    'feriado com almoço' => ['2026-07-09', ['07:30', '12:00', '13:00', '17:30'], true, 540, 'holiday'],
    'feriado 3 marcações' => ['2026-07-09', ['07:00', '12:00', '15:00'], true, 420, 'holiday'],
]);

test('the overtime screen keeps the current overtime result', function (string $date, array $times, bool $holiday, int $minutes, string $bucket) {
    if ($holiday) {
        Holiday::factory()->create(['date' => $date, 'description' => 'Feriado']);
    }

    $employee = characterizationEmployee($date, $times);

    $component = Livewire::test(EmployeeHorasExtras::class, ['employee' => $employee->id])
        ->set('startDate', $date)
        ->set('endDate', $date);

    expect($component->get('totalExtraMinutes'))->toBe($minutes)
        ->and($component->get('total'.ucfirst($bucket).'Minutes'))->toBe($minutes);
})->with('current overtime rule');

test('the resume report keeps the current overtime result', function (string $date, array $times, bool $holiday, int $minutes, string $bucket) {
    if ($holiday) {
        Holiday::factory()->create(['date' => $date, 'description' => 'Feriado']);
    }

    characterizationEmployee($date, $times);

    $row = Livewire::test(EmployeeResumeReport::class)
        ->set('startDate', $date)
        ->set('endDate', $date)
        ->get('results')[0];

    expect($row['total_minutes'])->toBe($minutes)
        ->and($row[$bucket.'_minutes'])->toBe($minutes);
})->with('current overtime rule');

test('the extra hours report keeps the current overtime result', function (string $date, array $times, bool $holiday, int $minutes, string $bucket) {
    if ($holiday) {
        Holiday::factory()->create(['date' => $date, 'description' => 'Feriado']);
    }

    characterizationEmployee($date, $times);

    $results = Livewire::test(EmployeesExtraReport::class)
        ->set('minutesFilter', 0)
        ->set('startDate', $date)
        ->set('endDate', $date)
        ->get('results');

    if ($minutes === 0) {
        expect($results)->toBe([]);

        return;
    }

    expect($results)->toHaveCount(1)
        ->and($results[0]['minutes'])->toBe($minutes)
        ->and($results[0]['holiday_minutes'])->toBe($holiday ? $minutes : 0);
})->with('current overtime rule');

test('the reports add up the overtime of every day in the period', function () {
    Holiday::factory()->create(['date' => '2026-07-09', 'description' => 'Feriado']);

    $employee = Employee::factory()->create();

    foreach ([
        '2026-07-08' => ['07:00', '12:00', '13:00', '18:30'],
        '2026-07-09' => ['07:30', '12:00', '13:00', '17:30'],
        '2026-07-10' => ['07:30', '12:00', '13:00', '17:30'],
        '2026-07-11' => ['07:00', '12:00'],
        '2026-07-12' => ['08:00', '12:00', '13:00', '17:00'],
    ] as $date => $times) {
        foreach ($times as $time) {
            Point::factory()->forEmployee($employee)->on($date)->create(['time' => $time.':00']);
        }
    }

    Livewire::test(EmployeeHorasExtras::class, ['employee' => $employee->id])
        ->set('startDate', '2026-07-08')
        ->set('endDate', '2026-07-12')
        ->assertSet('totalWeekdayMinutes', 150)
        ->assertSet('totalHolidayMinutes', 540)
        ->assertSet('totalSaturdayMinutes', 300)
        ->assertSet('totalSundayMinutes', 480)
        ->assertSet('totalExtraMinutes', 1470)
        ->assertSet('totalExtraHours', '24:30');

    $row = Livewire::test(EmployeeResumeReport::class)
        ->set('startDate', '2026-07-08')
        ->set('endDate', '2026-07-12')
        ->get('results')[0];

    expect($row['fifty_minutes'])->toBe(450)
        ->and($row['hundred_minutes'])->toBe(1020)
        ->and($row['total_hours'])->toBe('24:30');

    $results = Livewire::test(EmployeesExtraReport::class)
        ->set('minutesFilter', 0)
        ->set('startDate', '2026-07-08')
        ->set('endDate', '2026-07-12')
        ->get('results');

    expect($results[0]['minutes'])->toBe(1470)
        ->and($results[0]['holiday_minutes'])->toBe(540);
});
