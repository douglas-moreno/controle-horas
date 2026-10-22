<?php

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Point;
use App\Services\AlternativeTimesheetRule;
use App\Services\BusinessCalendar;
use App\Services\OvertimeCalculator;
use App\Services\TimesheetMirror;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Fase 7.3: bloco de configuração da regra alternativa, desligado por padrão.
 * Datas: 2026-07-08 quarta, 2026-07-09 quinta (feriado), 2026-07-10 sexta e 2026-07-11 sábado.
 */
uses(RefreshDatabase::class);

/**
 * @param  list<string>  $times
 */
function alternativeForDay(string $date, array $times): mixed
{
    $day = CarbonImmutable::parse($date);

    return app(AlternativeTimesheetRule::class)->forDay($day, app(BusinessCalendar::class)->classify($day), $times, 0);
}

test('the alternative rule is disabled by default', function () {
    expect(config('timesheet.alternative_rule.enabled'))->toBeFalse()
        ->and(app(AlternativeTimesheetRule::class)->isEnabled())->toBeFalse();
});

test('no alternative data is produced while the configuration is disabled', function (string $date, array $times) {
    Holiday::factory()->create(['date' => '2026-07-09', 'description' => 'Feriado']);

    expect(alternativeForDay($date, $times))->toBeNull();
})->with([
    'regular business day' => ['2026-07-08', ['07:36', '12:00', '13:00', '17:30']],
    'two punches' => ['2026-07-08', ['07:23', '12:17']],
    'manual review' => ['2026-07-08', ['08:27', '14:16', '16:04', '17:28']],
    'night shift guard' => ['2026-07-08', ['06:00', '22:00']],
    'odd punches' => ['2026-07-08', ['07:30', '12:00', '13:00']],
    'no punches' => ['2026-07-08', []],
    'holiday' => ['2026-07-09', ['07:30', '12:00', '13:00', '17:30']],
    'saturday' => ['2026-07-11', ['07:08', '12:05']],
]);

test('only an explicit true value enables the alternative rule', function (mixed $value) {
    config(['timesheet.alternative_rule.enabled' => $value]);

    expect(app(AlternativeTimesheetRule::class)->isEnabled())->toBeFalse()
        ->and(alternativeForDay('2026-07-08', ['07:36', '12:00', '13:00', '17:30']))->toBeNull();
})->with([
    'false' => [false],
    'null' => [null],
    'string' => ['true'],
    'integer' => [1],
]);

test('alternative data is produced once the configuration is enabled', function () {
    config(['timesheet.alternative_rule.enabled' => true]);

    $result = alternativeForDay('2026-07-08', ['07:36', '12:00', '13:00', '17:30']);

    expect($result)->not->toBeNull()
        ->and($result->overtimeMinutes)->toBe(0)
        ->and($result->delayMinutes)->toBe(6);
});

test('the pure evaluation does not depend on the configuration', function () {
    $schedule = config('timesheet.schedule.3');
    $rule = app(AlternativeTimesheetRule::class);

    $disabled = $rule->evaluate(app(BusinessCalendar::class)->classify(CarbonImmutable::parse('2026-07-08')), $schedule, ['07:36', '12:00', '13:00', '17:30'], 0);

    config(['timesheet.alternative_rule.enabled' => true]);

    $enabled = $rule->evaluate(app(BusinessCalendar::class)->classify(CarbonImmutable::parse('2026-07-08')), $schedule, ['07:36', '12:00', '13:00', '17:30'], 0);

    expect($disabled)->toEqual($enabled);
});

test('the official mirror and overtime are identical with the configuration enabled and disabled', function () {
    Holiday::factory()->create(['date' => '2026-07-09', 'description' => 'Feriado']);
    $employee = Employee::factory()->create();
    $pointsByDate = [
        '2026-07-06' => ['07:23', '12:17'],
        '2026-07-07' => ['07:30', '12:00', '12:30', '17:30'],
        '2026-07-08' => ['08:27', '14:16', '16:04', '17:28'],
        '2026-07-09' => ['05:49', '12:01', '13:00'],
        '2026-07-10' => ['07:27', '12:00', '13:00', '16:53'],
        '2026-07-11' => ['07:08', '12:05'],
    ];

    foreach ($pointsByDate as $date => $times) {
        foreach ($times as $time) {
            Point::factory()->forEmployee($employee)->on($date)->create(['time' => $time.':00']);
        }
    }

    $start = CarbonImmutable::parse('2026-07-06');
    $end = CarbonImmutable::parse('2026-07-12');
    $slots = ['entrada' => '07:30', 'almoco_inicio' => '12:00', 'almoco_fim' => '12:30', 'saida' => '17:30'];

    $disabledMirror = app(TimesheetMirror::class)->forEmployee($employee, $start, $end);
    $disabledOvertime = app(OvertimeCalculator::class)->minutes(CarbonImmutable::parse('2026-07-07'), $slots);

    config(['timesheet.alternative_rule.enabled' => true]);

    expect(app(TimesheetMirror::class)->forEmployee($employee, $start, $end))->toEqual($disabledMirror)
        ->and(app(OvertimeCalculator::class)->minutes(CarbonImmutable::parse('2026-07-07'), $slots))->toBe($disabledOvertime)
        ->and($disabledOvertime)->toBe(30)
        ->and(Point::query()->count())->toBe(19);
});
