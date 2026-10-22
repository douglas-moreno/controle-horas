<?php

use App\Enums\AlternativeReviewReason;
use App\Enums\CalendarDayType;
use App\Enums\TimesheetDayStatus;
use App\Models\Holiday;
use App\Services\AlternativeDayResult;
use App\Services\AlternativeTimesheetRule;
use App\Services\BusinessCalendar;
use App\Services\OvertimeCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Datas: 2026-07-08 quarta, 2026-07-09 quinta (feriado nos casos marcados), 2026-07-10
 * sexta e 2026-07-11 sábado.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['timesheet.alternative_rule.enabled' => true]);
});

/**
 * @param  list<string>  $times
 * @return array{0: AlternativeDayResult, 1: int}
 */
function alternativeAndOfficial(string $date, array $times): array
{
    $day = CarbonImmutable::parse($date);
    $slots = ['entrada' => '', 'almoco_inicio' => '', 'almoco_fim' => '', 'saida' => ''];

    if (count($times) === 2) {
        $slots['entrada'] = $times[0];
        $slots['saida'] = $times[1];
    } elseif (count($times) === 4) {
        [$slots['entrada'], $slots['almoco_inicio'], $slots['almoco_fim'], $slots['saida']] = $times;
    }

    $official = app(OvertimeCalculator::class)->minutes($day, $slots);
    $result = app(AlternativeTimesheetRule::class)->forDay($day, app(BusinessCalendar::class)->classify($day), $times, $official);

    return [$result, $official];
}

test('the configured schedule of the weekday is used', function () {
    [$wednesday] = alternativeAndOfficial('2026-07-08', ['07:30', '16:30']);
    [$friday] = alternativeAndOfficial('2026-07-10', ['07:30', '16:30']);

    expect($wednesday->earlyLeaveMinutes)->toBe(60)
        ->and($friday->earlyLeaveMinutes)->toBe(0)
        ->and($friday->overtimeMinutes)->toBe(0);
});

test('a holiday repeats the official overtime', function () {
    Holiday::factory()->create(['date' => '2026-07-09', 'description' => 'Feriado']);

    [$result, $official] = alternativeAndOfficial('2026-07-09', ['07:30', '12:00', '13:00', '17:30']);

    expect($official)->toBe(540)
        ->and($result->overtimeMinutes)->toBe(540)
        ->and($result->usesOfficialOvertime)->toBeTrue()
        ->and($result->delayMinutes)->toBeNull();
});

test('a saturday repeats the official overtime', function () {
    [$result, $official] = alternativeAndOfficial('2026-07-11', ['07:00', '12:00']);

    expect($result->overtimeMinutes)->toBe($official)->toBe(300);
});

test('a missing schedule for a business day is not calculated', function () {
    config(['timesheet.schedule' => []]);

    [$result] = alternativeAndOfficial('2026-07-08', ['07:30', '17:30']);

    expect($result->status)->toBe(TimesheetDayStatus::NoSchedule);
});

test('without compensation the official result equals the alternative overtime minus the raw shortfall', function () {
    $checked = 0;

    foreach (['2026-07-08' => 17 * 60 + 30, '2026-07-10' => 16 * 60 + 30] as $date => $exit) {
        for ($first = 5 * 60; $first <= 12 * 60; $first += 11) {
            for ($last = 13 * 60; $last <= 21 * 60 + 30; $last += 13) {
                $times = [sprintf('%02d:%02d', intdiv($first, 60), $first % 60), sprintf('%02d:%02d', intdiv($last, 60), $last % 60)];
                [$result, $official] = alternativeAndOfficial($date, $times);

                if ($result->status !== TimesheetDayStatus::Regular) {
                    continue;
                }

                $workedInWindows = max(0, 12 * 60 - max($first, 7 * 60 + 30)) + max(0, min($last, $exit) - 13 * 60);
                $rawShortfall = (12 * 60 - 7 * 60 - 30) + ($exit - 13 * 60) - $workedInWindows;

                expect($official)->toBe(max(0, $result->overtimeMinutes - $rawShortfall));
                $checked++;
            }
        }
    }

    expect($checked)->toBeGreaterThan(300);
});

test('manual review days keep the official overtime available and no alternative values', function () {
    [$result, $official] = alternativeAndOfficial('2026-07-08', ['08:27', '14:16', '16:04', '17:28']);

    expect($result->reviewReason)->toBe(AlternativeReviewReason::ShiftedLunch)
        ->and($result->overtimeMinutes)->toBeNull()
        ->and($result->delayMinutes)->toBeNull()
        ->and($official)->toBe(0);
});

test('the business calendar type drives the alternative rule', function () {
    Holiday::factory()->create(['date' => '2026-07-08', 'description' => 'Feriado']);

    [$result] = alternativeAndOfficial('2026-07-08', ['22:10', '23:00']);

    expect(app(BusinessCalendar::class)->classify(CarbonImmutable::parse('2026-07-08')))->toBe(CalendarDayType::Holiday)
        ->and($result->reviewReason)->toBeNull()
        ->and($result->usesOfficialOvertime)->toBeTrue();
});

test('half days with a lunch punch use the presumed lunch limits without changing the official result', function (string $date, array $times, int $official, int $overtime, int $delay) {
    [$result, $officialOvertime] = alternativeAndOfficial($date, $times);

    expect($officialOvertime)->toBe($official)
        ->and($result->status)->toBe(TimesheetDayStatus::Regular)
        ->and($result->overtimeMinutes)->toBe($overtime)
        ->and($result->delayMinutes)->toBe($delay)
        ->and($result->consideredTimes)->toBe($times);
})->with([
    'saída às 12:17 na quarta' => ['2026-07-08', ['07:23', '12:17'], 0, 7, 270],
    'saída às 12:12 na quarta' => ['2026-07-08', ['06:18', '12:12'], 0, 72, 270],
    'saída às 12:00 na quarta' => ['2026-07-08', ['07:30', '12:00'], 0, 0, 270],
    'entrada às 12:30 na quarta' => ['2026-07-08', ['12:30', '17:30'], 0, 0, 270],
    'entrada às 12:30 na sexta' => ['2026-07-10', ['12:30', '16:30'], 0, 0, 270],
]);
