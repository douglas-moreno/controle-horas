<?php

use App\Enums\AlternativeReviewReason;
use App\Enums\TimesheetDayStatus;
use App\Livewire\EmployeeHorasExtras;
use App\Models\Employee;
use App\Models\Point;
use App\Models\User;
use App\Services\TimesheetMirror;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Livewire\Livewire;

/**
 * Fase 7.4: o TimesheetMirror acrescenta a chave `alternative` por dia e nos totais
 * somente com `timesheet.alternative_rule.enabled` ligada.
 * Datas: 2026-07-06 segunda a 2026-07-13 segunda; 2026-07-11 sábado e 2026-07-12 domingo.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->employee = Employee::factory()->create(['recision_date' => null]);

    foreach ([
        '2026-07-06' => ['07:36', '12:00', '13:00', '17:30'],
        '2026-07-07' => ['07:30', '12:00', '12:30', '17:30'],
        '2026-07-08' => ['08:27', '14:16', '16:04', '17:28'],
        '2026-07-09' => ['07:26', '17:30', '17:30'],
        '2026-07-10' => ['06:00', '22:00'],
        '2026-07-11' => ['07:08', '12:05'],
        '2026-07-13' => ['07:23', '12:17'],
    ] as $date => $times) {
        foreach ($times as $time) {
            Point::factory()->forEmployee($this->employee)->on($date)->create(['time' => $time.':00']);
        }
    }
});

/**
 * @return array{days: list<array<string, mixed>>, totals: array<string, mixed>}
 */
function alternativeMirror(Employee $employee): array
{
    return app(TimesheetMirror::class)->forEmployee($employee, CarbonImmutable::parse('2026-07-06'), CarbonImmutable::parse('2026-07-13'));
}

/**
 * @param  array{days: list<array<string, mixed>>, totals: array<string, mixed>}  $mirror
 * @return array{days: list<array<string, mixed>>, totals: array<string, mixed>}
 */
function officialPart(array $mirror): array
{
    return [
        'days' => array_map(fn (array $day) => Arr::except($day, 'alternative'), $mirror['days']),
        'totals' => Arr::except($mirror['totals'], 'alternative'),
    ];
}

test('the mirror has no alternative data by default', function () {
    $mirror = alternativeMirror($this->employee);

    expect(config('timesheet.alternative_rule.enabled'))->toBeFalse()
        ->and($mirror['totals'])->not->toHaveKey('alternative');

    foreach ($mirror['days'] as $day) {
        expect($day)->not->toHaveKey('alternative');
    }
});

test('the official fields and totals are identical with the configuration enabled and disabled', function () {
    $pointsBefore = Point::query()->orderBy('id')->get()->toArray();
    $disabled = alternativeMirror($this->employee);

    config(['timesheet.alternative_rule.enabled' => true]);

    $enabled = alternativeMirror($this->employee);

    expect(officialPart($enabled))->toEqual($disabled)
        ->and(array_keys($enabled['days'][0]))->toBe([...array_keys($disabled['days'][0]), 'alternative'])
        ->and(Point::query()->orderBy('id')->get()->toArray())->toBe($pointsBefore);
});

test('every day carries the alternative result next to the official values', function () {
    config(['timesheet.alternative_rule.enabled' => true]);

    $days = collect(alternativeMirror($this->employee)['days'])->keyBy('date');

    expect($days['2026-07-06']['delay_minutes'])->toBe(1)
        ->and($days['2026-07-06']['alternative'])->toMatchArray([
            'status' => TimesheetDayStatus::Regular,
            'needs_review' => false,
            'review_reason' => null,
            'overtime_minutes' => 0,
            'late_entry_minutes' => 6,
            'early_leave_minutes' => 0,
            'delay_minutes' => 6,
            'delay' => '00:06',
        ])
        ->and($days['2026-07-07']['overtime_minutes'])->toBe(30)
        ->and($days['2026-07-07']['alternative']['overtime_minutes'])->toBe(0)
        ->and($days['2026-07-09']['status'])->toBe(TimesheetDayStatus::OddPunches)
        ->and($days['2026-07-09']['overtime_minutes'])->toBe(4)
        ->and($days['2026-07-09']['alternative'])->toMatchArray([
            'status' => TimesheetDayStatus::Regular,
            'considered_times' => ['07:26', '17:30'],
            'overtime_minutes' => 4,
            'delay_minutes' => 0,
        ])
        ->and($days['2026-07-11']['alternative'])->toMatchArray([
            'status' => TimesheetDayStatus::NonWorkingDayWorked,
            'uses_official_overtime' => true,
            'overtime_minutes' => 297,
            'overtime' => '04:57',
            'delay_minutes' => null,
            'delay' => '',
        ])
        ->and($days['2026-07-13']['alternative'])->toMatchArray([
            'overtime_minutes' => 7,
            'early_leave_minutes' => 270,
            'delay_minutes' => 270,
        ]);
});

test('days in manual review keep the official values and have no alternative overtime or delay', function (string $date, AlternativeReviewReason $reason, int $officialOvertime) {
    config(['timesheet.alternative_rule.enabled' => true]);

    $day = collect(alternativeMirror($this->employee)['days'])->firstWhere('date', $date);

    expect($day['overtime_minutes'])->toBe($officialOvertime)
        ->and($day['status'])->toBe(TimesheetDayStatus::Regular)
        ->and($day['alternative'])->toMatchArray([
            'status' => TimesheetDayStatus::OutsideSchedule,
            'status_label' => 'Conferência manual — fora das janelas previstas',
            'needs_review' => true,
            'review_reason' => $reason,
            'review_reason_label' => $reason->label(),
            'overtime_minutes' => null,
            'overtime' => '',
            'delay_minutes' => null,
            'delay' => '',
        ]);
})->with([
    'shifted lunch' => ['2026-07-08', AlternativeReviewReason::ShiftedLunch, 0],
    'night shift guard' => ['2026-07-10', AlternativeReviewReason::NightShiftGuard, 420],
]);

test('the alternative totals are the sum of the alternative daily values', function () {
    config(['timesheet.alternative_rule.enabled' => true]);

    $mirror = alternativeMirror($this->employee);
    $alternatives = collect($mirror['days'])->pluck('alternative');

    expect($mirror['totals']['alternative'])->toBe([
        'overtime_minutes' => 308,
        'late_entry_minutes' => 6,
        'early_leave_minutes' => 270,
        'delay_minutes' => 276,
        'review_days' => 3,
        'overtime' => '05:08',
        'delay' => '04:36',
    ])
        ->and($alternatives->sum('overtime_minutes'))->toBe(308)
        ->and($alternatives->sum('delay_minutes'))->toBe(276)
        ->and($mirror['totals']['overtime_minutes'])->toBe(collect($mirror['days'])->sum('overtime_minutes'));
});

test('an empty period has zeroed alternative totals', function () {
    config(['timesheet.alternative_rule.enabled' => true]);

    $mirror = app(TimesheetMirror::class)->build(collect(), now(), now()->subDay());

    expect($mirror['days'])->toBe([])
        ->and($mirror['totals']['alternative'])->toMatchArray(['overtime_minutes' => 0, 'delay_minutes' => 0, 'review_days' => 0]);
});

test('the overtime screen keeps only official data with the configuration enabled', function () {
    $this->actingAs(User::factory()->create());

    $screen = fn () => Livewire::withQueryParams(['startDate' => '2026-07-06', 'endDate' => '2026-07-13'])
        ->test(EmployeeHorasExtras::class, ['employee' => $this->employee->id]);

    $disabled = $screen();

    config(['timesheet.alternative_rule.enabled' => true]);

    $enabled = $screen();

    expect($enabled->get('days'))->toBe($disabled->get('days'))
        ->and($enabled->get('totalExtraMinutes'))->toBe($disabled->get('totalExtraMinutes'))
        ->and($enabled->get('totalDelayMinutes'))->toBe($disabled->get('totalDelayMinutes'))
        ->and($enabled->get('reviewDays'))->toBe($disabled->get('reviewDays'));

    foreach ($enabled->get('days') as $day) {
        expect($day)->not->toHaveKey('alternative');
    }

    $enabled->assertDontSee('Conferência manual — fora das janelas previstas')
        ->assertDontSee(AlternativeReviewReason::ShiftedLunch->label())
        ->assertDontSee('shifted_lunch');
});

test('the print page is identical with the configuration enabled and disabled', function () {
    $this->actingAs(User::factory()->create());
    $this->travelTo('2026-07-20 10:00:00');

    $print = fn () => $this->get(route('reports.timesheet.print', [
        'start' => '2026-07-06',
        'end' => '2026-07-13',
        'employees' => [$this->employee->id],
    ]))->assertOk();

    $disabled = $print()->getContent();

    config(['timesheet.alternative_rule.enabled' => true]);

    $enabled = $print();

    expect($enabled->getContent())->toBe($disabled);
    $enabled->assertDontSee('Conferência manual — fora das janelas previstas')
        ->assertDontSee(AlternativeReviewReason::NightShiftGuard->label());
});
