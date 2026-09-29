<?php

use App\Enums\CalendarDayType;
use App\Models\Holiday;
use App\Services\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->calendar = new BusinessCalendar;
});

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function holidayOn(string $date): Holiday
{
    return Holiday::factory()->create(['date' => $date]);
}

test('days are classified with holidays taking precedence over weekends', function (string $date, ?string $holiday, CalendarDayType $expected) {
    if ($holiday !== null) {
        holidayOn($holiday);
    }

    expect($this->calendar->classify(CarbonImmutable::parse($date)))->toBe($expected);
})->with([
    'ordinary monday' => ['2026-09-14', null, CalendarDayType::BusinessDay],
    'saturday' => ['2026-09-12', null, CalendarDayType::Saturday],
    'sunday' => ['2026-09-13', null, CalendarDayType::Sunday],
    'holiday on a monday' => ['2026-09-07', '2026-09-07', CalendarDayType::Holiday],
    'holiday on a saturday' => ['2026-11-14', '2026-11-14', CalendarDayType::Holiday],
    'holiday on a sunday' => ['2026-11-15', '2026-11-15', CalendarDayType::Holiday],
]);

test('a holiday on another day does not change the classification', function () {
    holidayOn('2026-09-07');

    expect($this->calendar->classify(CarbonImmutable::parse('2026-09-08')))->toBe(CalendarDayType::BusinessDay);
});

test('a single day interval returns only that day', function () {
    expect($this->calendar->days(CarbonImmutable::parse('2026-09-12'), CarbonImmutable::parse('2026-09-12'))->all())
        ->toBe(['2026-09-12' => CalendarDayType::Saturday]);
});

test('the interval includes both ends', function () {
    holidayOn('2026-09-07');

    $days = $this->calendar->days(CarbonImmutable::parse('2026-09-05'), CarbonImmutable::parse('2026-09-08'));

    expect($days->all())->toBe([
        '2026-09-05' => CalendarDayType::Saturday,
        '2026-09-06' => CalendarDayType::Sunday,
        '2026-09-07' => CalendarDayType::Holiday,
        '2026-09-08' => CalendarDayType::BusinessDay,
    ]);
});

test('the interval can cross the end of a month', function () {
    $days = $this->calendar->days(CarbonImmutable::parse('2026-09-29'), CarbonImmutable::parse('2026-10-02'));

    expect($days->keys()->all())->toBe(['2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02'])
        ->and($days->unique()->all())->toBe(['2026-09-29' => CalendarDayType::BusinessDay]);
});

test('a full month interval has one entry per day', function () {
    expect($this->calendar->days(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30')))
        ->toHaveCount(30);
});

test('an inverted interval is rejected', function () {
    $this->calendar->days(CarbonImmutable::parse('2026-09-30'), CarbonImmutable::parse('2026-09-01'));
})->throws(InvalidArgumentException::class);

test('business days exclude weekends', function () {
    $businessDays = $this->calendar->businessDays(CarbonImmutable::parse('2026-09-11'), CarbonImmutable::parse('2026-09-14'));

    expect($businessDays->map->toDateString()->all())->toBe(['2026-09-11', '2026-09-14'])
        ->and($businessDays->first())->toBeInstanceOf(CarbonImmutable::class);
});

test('business days exclude holidays', function () {
    holidayOn('2026-10-12');

    $businessDays = $this->calendar->businessDays(CarbonImmutable::parse('2026-10-09'), CarbonImmutable::parse('2026-10-13'));

    expect($businessDays->map->toDateString()->all())->toBe(['2026-10-09', '2026-10-13']);
});

test('september 2026 has 21 business days with the independence day holiday', function () {
    holidayOn('2026-09-07');

    expect($this->calendar->businessDaysInMonth(CarbonImmutable::parse('2026-09-01')))->toBe(21);
});

test('october 2026 has 21 business days with the 12 october holiday', function () {
    holidayOn('2026-10-12');

    expect($this->calendar->businessDaysInMonth(CarbonImmutable::parse('2026-10-01')))->toBe(21);
});

test('a month without registered holidays counts every weekday', function () {
    expect($this->calendar->businessDaysInMonth(CarbonImmutable::parse('2026-09-01')))->toBe(22);
});

test('the month can be given by any of its days', function () {
    holidayOn('2026-09-07');

    expect($this->calendar->businessDaysInMonth(CarbonImmutable::parse('2026-09-23')))->toBe(21);
});

test('a holiday registered after a previous lookup is taken into account', function () {
    expect($this->calendar->businessDaysInMonth(CarbonImmutable::parse('2026-09-01')))->toBe(22);

    holidayOn('2026-09-07');

    expect($this->calendar->businessDaysInMonth(CarbonImmutable::parse('2026-09-01')))->toBe(21);
});

test('year has holidays when at least one is registered', function () {
    holidayOn('2026-09-07');

    expect($this->calendar->yearHasHolidays(2026))->toBeTrue()
        ->and($this->calendar->yearHasHolidays(2027))->toBeFalse();
});

test('year has no holidays when none is registered', function () {
    expect($this->calendar->yearHasHolidays(2026))->toBeFalse();
});

test('results do not depend on the current date or time of day', function () {
    holidayOn('2026-09-07');

    Carbon::setTestNow('2030-01-01 23:59:59');
    CarbonImmutable::setTestNow('2030-01-01 23:59:59');

    expect($this->calendar->classify(CarbonImmutable::parse('2026-09-07 23:59:59')))->toBe(CalendarDayType::Holiday)
        ->and($this->calendar->classify(Carbon::parse('2026-09-12 00:00:01')))->toBe(CalendarDayType::Saturday)
        ->and($this->calendar->businessDaysInMonth(CarbonImmutable::parse('2026-09-30 23:59:59')))->toBe(21)
        ->and($this->calendar->days(CarbonImmutable::parse('2026-09-07 18:00'), CarbonImmutable::parse('2026-09-08 06:00'))->keys()->all())
        ->toBe(['2026-09-07', '2026-09-08']);
});
