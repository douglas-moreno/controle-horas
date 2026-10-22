<?php

use App\Enums\CalendarDayType;
use App\Enums\TimesheetDayStatus;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Point;
use App\Services\OvertimeCalculator;
use App\Services\TimesheetMirror;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Datas: 2026-07-06 segunda, 2026-07-08 quarta, 2026-07-09 quinta, 2026-07-10 sexta,
 * 2026-07-11 sábado e 2026-07-12 domingo.
 */
uses(RefreshDatabase::class);

/**
 * @param  array<string, array<int, string>>  $pointsByDate
 */
function mirrorEmployee(array $pointsByDate): Employee
{
    $employee = Employee::factory()->create();

    foreach ($pointsByDate as $date => $times) {
        foreach ($times as $time) {
            Point::factory()->forEmployee($employee)->on($date)->create(['time' => $time.':00']);
        }
    }

    return $employee;
}

/**
 * @param  array<int, string>  $times
 * @return array<string, mixed>
 */
function mirrorDay(string $date, array $times): array
{
    $employee = mirrorEmployee([$date => $times]);
    $day = CarbonImmutable::parse($date);

    return app(TimesheetMirror::class)->forEmployee($employee, $day, $day)['days'][0];
}

test('the entry tolerance is deducted from the late entry', function (string $entry, int $lateEntry) {
    $day = mirrorDay('2026-07-08', [$entry, '12:00', '13:00', '17:30']);

    expect($day['status'])->toBe(TimesheetDayStatus::Regular)
        ->and($day['late_entry_minutes'])->toBe($lateEntry)
        ->and($day['early_leave_minutes'])->toBe(0)
        ->and($day['delay_minutes'])->toBe($lateEntry);
})->with([
    'no horário' => ['07:30', 0],
    'antes do horário' => ['07:10', 0],
    'no limite da tolerância' => ['07:35', 0],
    'um minuto após a tolerância' => ['07:36', 1],
    'entrada às 09:10' => ['09:10', 95],
]);

test('the tolerance comes from the configuration', function () {
    config(['timesheet.entry_tolerance_minutes' => 0]);

    expect(mirrorDay('2026-07-08', ['07:36', '12:00', '13:00', '17:30'])['late_entry_minutes'])->toBe(6);
});

test('the early leave has no tolerance and does not count the scheduled break', function (string $date, array $times, int $lateEntry, int $earlyLeave) {
    $day = mirrorDay($date, $times);

    expect($day['status'])->toBe(TimesheetDayStatus::Regular)
        ->and($day['late_entry_minutes'])->toBe($lateEntry)
        ->and($day['early_leave_minutes'])->toBe($earlyLeave)
        ->and($day['delay_minutes'])->toBe($lateEntry + $earlyLeave);
})->with([
    'saída um minuto antes' => ['2026-07-08', ['07:30', '12:00', '13:00', '17:29'], 0, 1],
    'saída às 16:22 com 2 marcações' => ['2026-07-08', ['07:23', '16:22'], 0, 68],
    'só a manhã, saída dentro do intervalo' => ['2026-07-06', ['07:23', '12:17'], 0, 270],
    'só a tarde, entrada após o intervalo' => ['2026-07-08', ['13:10', '17:30'], 275, 0],
    'atraso e saída antecipada no mesmo dia' => ['2026-07-08', ['08:00', '12:00', '13:00', '17:00'], 25, 30],
    'sexta até 16:30' => ['2026-07-10', ['07:30', '12:00', '13:00', '16:30'], 0, 0],
    'sexta saída às 16:00' => ['2026-07-10', ['07:30', '12:00', '13:00', '16:00'], 0, 30],
    'saída após o horário' => ['2026-07-08', ['07:30', '19:00'], 0, 0],
]);

test('days without reliable punches are flagged and get no delay', function (string $date, array $times, TimesheetDayStatus $status) {
    $day = mirrorDay($date, $times);

    expect($day['status'])->toBe($status)
        ->and($day['needs_review'])->toBeTrue()
        ->and($day['late_entry_minutes'])->toBeNull()
        ->and($day['early_leave_minutes'])->toBeNull()
        ->and($day['delay_minutes'])->toBeNull()
        ->and($day['delay'])->toBe('');
})->with([
    'dia útil sem marcação' => ['2026-07-08', [], TimesheetDayStatus::NoPunches],
    '1 marcação' => ['2026-07-08', ['09:00'], TimesheetDayStatus::OddPunches],
    '3 marcações' => ['2026-07-08', ['07:30', '12:00', '13:00'], TimesheetDayStatus::OddPunches],
    '5 marcações' => ['2026-07-08', ['07:30', '12:00', '13:00', '17:30', '18:00'], TimesheetDayStatus::ExcessPunches],
    'sábado trabalhado' => ['2026-07-11', ['07:00', '12:00'], TimesheetDayStatus::NonWorkingDayWorked],
    'domingo trabalhado' => ['2026-07-12', ['07:00', '12:00'], TimesheetDayStatus::NonWorkingDayWorked],
]);

test('a worked holiday gets no delay and is flagged for review', function () {
    Holiday::factory()->create(['date' => '2026-07-09', 'description' => 'Feriado municipal']);

    $day = mirrorDay('2026-07-09', ['09:00', '12:00']);

    expect($day['day_type'])->toBe(CalendarDayType::Holiday)
        ->and($day['holiday'])->toBe('Feriado municipal')
        ->and($day['status'])->toBe(TimesheetDayStatus::NonWorkingDayWorked)
        ->and($day['delay_minutes'])->toBeNull()
        ->and($day['overtime_minutes'])->toBe(180);
});

test('a weekend without punches is not flagged', function () {
    $day = mirrorDay('2026-07-12', []);

    expect($day['status'])->toBe(TimesheetDayStatus::NonWorkingDay)
        ->and($day['needs_review'])->toBeFalse()
        ->and($day['is_highlighted'])->toBeTrue();
});

test('a business day without configured schedule gets no delay', function () {
    config(['timesheet.schedule' => []]);

    $day = mirrorDay('2026-07-08', ['09:00', '17:30']);

    expect($day['status'])->toBe(TimesheetDayStatus::NoSchedule)
        ->and($day['delay_minutes'])->toBeNull();
});

test('an invalid schedule configuration is rejected', function () {
    config(['timesheet.schedule.3.entry' => '7h30']);

    mirrorDay('2026-07-08', ['07:30', '17:30']);
})->throws(InvalidArgumentException::class);

test('punches are distributed in the four columns by their count', function (array $times, array $expected) {
    $day = mirrorDay('2026-07-08', $times);

    expect([$day['entrada'], $day['almoco_inicio'], $day['almoco_fim'], $day['saida']])->toBe($expected)
        ->and($day['punch_count'])->toBe(count($times));
})->with([
    'nenhuma' => [[], ['', '', '', '']],
    '1' => [['07:30'], ['07:30', '', '', '']],
    '2' => [['17:30', '07:30'], ['07:30', '', '', '17:30']],
    '3' => [['07:30', '12:00', '17:30'], ['07:30', '12:00', '', '17:30']],
    '4' => [['07:30', '12:00', '13:00', '17:30'], ['07:30', '12:00', '13:00', '17:30']],
    '5' => [['07:30', '12:00', '13:00', '17:30', '18:00'], ['07:30', '12:00', '13:00', '17:30']],
]);

test('the period has one row per day and totals equal the sum of the rows', function () {
    Holiday::factory()->create(['date' => '2026-07-09', 'description' => 'Feriado']);

    $employee = mirrorEmployee([
        '2026-07-06' => ['07:40', '12:00', '13:00', '18:00'],
        '2026-07-08' => ['07:30', '16:30'],
        '2026-07-09' => ['08:00', '12:00'],
        '2026-07-10' => ['07:30'],
        '2026-07-11' => ['07:00', '12:00'],
    ]);

    $mirror = app(TimesheetMirror::class)->forEmployee($employee, CarbonImmutable::parse('2026-07-06'), CarbonImmutable::parse('2026-07-12'));
    $days = collect($mirror['days']);

    expect($days->pluck('date')->all())->toBe(['2026-07-06', '2026-07-07', '2026-07-08', '2026-07-09', '2026-07-10', '2026-07-11', '2026-07-12'])
        ->and($days->pluck('delay_minutes')->all())->toBe([5, null, 60, null, null, null, null])
        ->and($days->pluck('overtime_minutes')->all())->toBe([20, 0, 0, 240, 0, 300, 0])
        ->and($mirror['totals']['delay_minutes'])->toBe(65)
        ->and($mirror['totals']['late_entry_minutes'])->toBe(5)
        ->and($mirror['totals']['early_leave_minutes'])->toBe(60)
        ->and($mirror['totals']['overtime_minutes'])->toBe(560)
        ->and($mirror['totals']['holiday_overtime_minutes'])->toBe(240)
        ->and($mirror['totals']['saturday_overtime_minutes'])->toBe(300)
        ->and($mirror['totals']['review_days'])->toBe(4)
        ->and($mirror['totals']['delay'])->toBe('01:05')
        ->and($mirror['totals']['overtime'])->toBe('09:20');
});

test('an inverted period results in an empty mirror', function () {
    $employee = mirrorEmployee([]);

    $mirror = app(TimesheetMirror::class)->forEmployee($employee, CarbonImmutable::parse('2026-07-10'), CarbonImmutable::parse('2026-07-08'));

    expect($mirror['days'])->toBe([])
        ->and($mirror['totals']['delay_minutes'])->toBe(0);
});

test('the configured schedule lasts as long as the overtime limits', function () {
    $duration = function (array $schedule): int {
        $minutes = fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);

        return $minutes($schedule['break_start']) - $minutes($schedule['entry']) + $minutes($schedule['exit']) - $minutes($schedule['break_end']);
    };

    foreach ([1, 2, 3, 4] as $weekday) {
        expect($duration(config('timesheet.schedule.'.$weekday)))->toBe(OvertimeCalculator::MONDAY_TO_THURSDAY_MINUTES);
    }

    expect($duration(config('timesheet.schedule.5')))->toBe(OvertimeCalculator::FRIDAY_MINUTES);
});
