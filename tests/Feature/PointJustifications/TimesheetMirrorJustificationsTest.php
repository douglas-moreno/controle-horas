<?php

use App\Enums\JustificationTimeMode;
use App\Enums\JustificationTreatment;
use App\Enums\Permission;
use App\Enums\TimesheetDayStatus;
use App\Livewire\EmployeeHorasExtras;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\Point;
use App\Models\PointJustification;
use App\Models\PointJustificationHistory;
use App\Models\PointJustificationReason;
use App\Models\User;
use App\Services\PointJustificationService;
use App\Services\TimesheetJustifications;
use App\Services\TimesheetMirror;
use Carbon\CarbonImmutable;
use Database\Seeders\PointJustificationReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Datas: 2026-07-07 terça, 2026-07-08 quarta, 2026-07-09 quinta (jornada 07:30–12:00 e
 * 13:00–17:30, tolerância de entrada de 5 minutos), 2026-07-11 sábado.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['timesheet.entry_tolerance_minutes' => 5]);

    $this->employee = Employee::factory()->create(['recision_date' => null]);
    $this->delayReason = PointJustificationReason::factory()->create(['name' => 'Atraso no trânsito', 'time_mode' => JustificationTimeMode::Interval]);
    $this->absenceReason = PointJustificationReason::factory()->create(['name' => 'Atestado de consulta', 'time_mode' => JustificationTimeMode::FullDayOrInterval]);

    $this->punches = function (string $date, array $times, ?Employee $employee = null): void {
        foreach ($times as $time) {
            Point::factory()->forEmployee($employee ?? $this->employee)->on($date)->create(['time' => $time.':00']);
        }
    };

    $this->interval = fn (string $date, string $start, string $end, string $treatment = 'full_allowance', ?int $minutes = null, array $extra = []) => PointJustification::factory()
        ->for($extra['employee'] ?? $this->employee)
        ->between($date, $start, $end)
        ->create([
            'reason_id' => $this->delayReason->id,
            'treatment' => $treatment,
            'allowed_minutes' => $minutes,
            ...collect($extra)->except('employee')->all(),
        ]);

    $this->fullDay = fn (string $date, string $treatment = 'full_allowance', array $extra = []) => PointJustification::factory()
        ->for($this->employee)
        ->create(['reason_id' => $this->absenceReason->id, 'occurrence_date' => $date, 'treatment' => $treatment, ...$extra]);

    $this->mirror = fn (string $start = '2026-07-08', ?string $end = null, ?Employee $employee = null) => app(TimesheetMirror::class)
        ->forEmployee($employee ?? $this->employee, CarbonImmutable::parse($start), CarbonImmutable::parse($end ?? $start));

    $this->day = fn (string $date = '2026-07-08', ?Employee $employee = null) => collect(($this->mirror)($date, $date, $employee)['days'])->sole();
});

describe('delay allowance', function () {
    test('full allowance neutralizes the late entry inside the interval, after the tolerance', function () {
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30']);
        $justification = ($this->interval)('2026-07-08', '07:30', '08:30');

        $day = ($this->day)();

        expect($day['status'])->toBe(TimesheetDayStatus::Regular)
            ->and($day['late_entry_minutes'])->toBe(55)
            ->and($day['early_leave_minutes'])->toBe(0)
            ->and($day['delay_before_allowance_minutes'])->toBe(55)
            ->and($day['allowed_delay_minutes'])->toBe(55)
            ->and($day['delay_minutes'])->toBe(0)
            ->and($day['delay'])->toBe('00:00')
            ->and($day['needs_review'])->toBeFalse()
            ->and($day['justifications'])->toBe([[
                'id' => $justification->id,
                'reason' => 'Atraso no trânsito',
                'category' => $this->delayReason->category->label(),
                'treatment' => 'full_allowance',
                'treatment_label' => 'Abonar integralmente',
                'period' => '07:30–08:30',
                'allowed_minutes' => null,
                'allowed_delay_minutes' => 55,
            ]]);
    });

    test('only the delay inside the justified interval is allowed', function () {
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30']);
        ($this->interval)('2026-07-08', '08:00', '08:30');

        $day = ($this->day)();

        expect($day['allowed_delay_minutes'])->toBe(30)
            ->and($day['delay_minutes'])->toBe(25)
            ->and($day['late_entry_minutes'])->toBe(55);
    });

    test('partial allowance is the smaller of the informed minutes and the delay in the interval', function (int $informed, int $allowed) {
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30']);
        ($this->interval)('2026-07-08', '07:30', '08:30', 'partial_allowance', $informed);

        $day = ($this->day)();

        expect($day['allowed_delay_minutes'])->toBe($allowed)
            ->and($day['delay_minutes'])->toBe(55 - $allowed);
    })->with([
        'less than the delay' => [20, 20],
        'equal to the delay' => [55, 55],
        'above the delay after the tolerance' => [58, 55],
        'whole interval' => [60, 55],
    ]);

    test('the early leave is allowed without tolerance', function () {
        ($this->punches)('2026-07-08', ['07:30', '12:00', '13:00', '16:30']);
        ($this->interval)('2026-07-08', '16:30', '17:30');

        $day = ($this->day)();

        expect($day['early_leave_minutes'])->toBe(60)
            ->and($day['allowed_delay_minutes'])->toBe(60)
            ->and($day['delay_minutes'])->toBe(0);
    });

    test('a partial allowance covering late entry and early leave fills the entry first', function () {
        ($this->punches)('2026-07-08', ['08:00', '12:00', '13:00', '17:00']);
        ($this->fullDay)('2026-07-08', 'partial_allowance', ['allowed_minutes' => 40]);

        $day = ($this->day)();

        expect($day['delay_before_allowance_minutes'])->toBe(55)
            ->and($day['allowed_delay_minutes'])->toBe(40)
            ->and($day['delay_minutes'])->toBe(15)
            ->and($day['justifications'][0]['allowed_delay_minutes'])->toBe(40);
    });

    test('a full day allowance on a day with punches neutralizes the whole delay', function () {
        ($this->punches)('2026-07-08', ['08:00', '12:00', '13:00', '17:00']);
        ($this->fullDay)('2026-07-08');

        $day = ($this->day)();

        expect($day['delay_before_allowance_minutes'])->toBe(55)
            ->and($day['allowed_delay_minutes'])->toBe(55)
            ->and($day['delay_minutes'])->toBe(0)
            ->and($day['justified'])->toBeFalse();
    });

    test('the allowance never exceeds the calculated delay nor counts the break', function () {
        ($this->punches)('2026-07-08', ['13:30', '17:30']);
        ($this->interval)('2026-07-08', '06:00', '14:00');

        $day = ($this->day)();

        expect($day['delay_before_allowance_minutes'])->toBe(295)
            ->and($day['allowed_delay_minutes'])->toBe(295)
            ->and($day['delay_minutes'])->toBe(0);
    });

    test('several justifications add up without exceeding the delay', function () {
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:00']);
        $first = ($this->interval)('2026-07-08', '07:30', '08:00');
        $second = ($this->interval)('2026-07-08', '08:00', '08:30');
        $exit = ($this->interval)('2026-07-08', '17:00', '17:30', 'partial_allowance', 10);

        $day = ($this->day)();
        $byId = collect($day['justifications'])->pluck('allowed_delay_minutes', 'id');

        expect($day['delay_before_allowance_minutes'])->toBe(85)
            ->and($day['allowed_delay_minutes'])->toBe(65)
            ->and($day['delay_minutes'])->toBe(20)
            ->and($byId[$first->id])->toBe(30)
            ->and($byId[$second->id])->toBe(25)
            ->and($byId[$exit->id])->toBe(10);
    });

    test('justifications without allowance or without a period do not change the delay', function (string $case) {
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30']);

        match ($case) {
            'no allowance' => ($this->interval)('2026-07-08', '07:30', '08:30', 'no_allowance'),
            'single punch' => PointJustification::factory()->for($this->employee)->create([
                'reason_id' => PointJustificationReason::factory()->create(['time_mode' => JustificationTimeMode::SinglePunch])->id,
                'occurrence_date' => '2026-07-08',
                'start_time' => '08:30:00',
                'punch_kind' => 'entry',
                'treatment' => JustificationTreatment::FullAllowance,
            ]),
            'outside the delay' => ($this->interval)('2026-07-08', '09:00', '10:00'),
        };

        $day = ($this->day)();

        expect($day['delay_before_allowance_minutes'])->toBe(55)
            ->and($day['allowed_delay_minutes'])->toBe(0)
            ->and($day['delay_minutes'])->toBe(55)
            ->and($day['justifications'])->toHaveCount(1);
    })->with(['no allowance', 'single punch', 'outside the delay']);

    test('a late break return has no effect because the official delay does not count the break', function () {
        ($this->punches)('2026-07-08', ['07:30', '12:00', '13:30', '17:30']);
        ($this->interval)('2026-07-08', '13:00', '13:30');

        $day = ($this->day)();

        expect($day['delay_before_allowance_minutes'])->toBe(0)
            ->and($day['allowed_delay_minutes'])->toBe(0)
            ->and($day['delay_minutes'])->toBe(0);
    });

    test('days without a calculated delay keep the allowance empty', function () {
        ($this->punches)('2026-07-08', ['08:30', '12:00', '17:30']);
        ($this->punches)('2026-07-11', ['08:00', '12:00']);
        ($this->interval)('2026-07-08', '07:30', '08:30');
        ($this->interval)('2026-07-11', '08:00', '12:00');

        $days = collect(($this->mirror)('2026-07-08', '2026-07-11')['days'])->keyBy('date');

        expect($days['2026-07-08']['status'])->toBe(TimesheetDayStatus::OddPunches)
            ->and($days['2026-07-08']['delay_minutes'])->toBeNull()
            ->and($days['2026-07-08']['allowed_delay_minutes'])->toBeNull()
            ->and($days['2026-07-08']['needs_review'])->toBeTrue()
            ->and($days['2026-07-11']['allowed_delay_minutes'])->toBeNull()
            ->and($days['2026-07-11']['needs_review'])->toBeTrue();
    });

    test('a justification crossing midnight allows the delay of the next day', function () {
        ($this->punches)('2026-07-08', ['09:00', '12:00', '13:00', '17:30']);
        $night = ($this->interval)('2026-07-07', '22:00', '08:00', 'full_allowance', null, ['end_time' => '08:00:00', 'ends_next_day' => true, 'duration_minutes' => 600]);

        $days = collect(($this->mirror)('2026-07-07', '2026-07-08')['days'])->keyBy('date');

        expect($days['2026-07-08']['delay_before_allowance_minutes'])->toBe(85)
            ->and($days['2026-07-08']['allowed_delay_minutes'])->toBe(30)
            ->and($days['2026-07-08']['delay_minutes'])->toBe(55)
            ->and(collect($days['2026-07-08']['justifications'])->pluck('id')->all())->toBe([$night->id])
            ->and($days['2026-07-08']['justifications'][0]['period'])->toBe('22:00–08:00 (dia seguinte)');
    });

    test('a justification of the previous day crossing midnight is loaded even outside the period', function () {
        ($this->punches)('2026-07-08', ['09:00', '12:00', '13:00', '17:30']);
        ($this->interval)('2026-07-07', '22:00', '08:00', 'full_allowance', null, ['end_time' => '08:00:00', 'ends_next_day' => true, 'duration_minutes' => 600]);

        expect(($this->day)()['allowed_delay_minutes'])->toBe(30);
    });
});

describe('daily review of absences', function () {
    test('a full day absence with allowance leaves the review', function () {
        ($this->fullDay)('2026-07-08');

        $mirror = ($this->mirror)('2026-07-08', '2026-07-09');
        $day = collect($mirror['days'])->firstWhere('date', '2026-07-08');

        expect($day['status'])->toBe(TimesheetDayStatus::NoPunches)
            ->and($day['justified'])->toBeTrue()
            ->and($day['needs_review'])->toBeFalse()
            ->and($day['status_label'])->toBe('Justificado — Atestado de consulta')
            ->and($day['delay_minutes'])->toBeNull()
            ->and($mirror['totals']['review_days'])->toBe(1)
            ->and($mirror['totals']['justified_days'])->toBe(1);
    });

    test('a full day absence without allowance also leaves the review, flagged as not allowed', function () {
        ($this->fullDay)('2026-07-08', 'no_allowance');

        $day = ($this->day)();

        expect($day['justified'])->toBeTrue()
            ->and($day['needs_review'])->toBeFalse()
            ->and($day['status_label'])->toBe('Justificado — Atestado de consulta (não abonado)');
    });

    test('a partial absence on a day without punches keeps the review', function () {
        ($this->interval)('2026-07-08', '07:30', '09:30');

        $day = ($this->day)();

        expect($day['status'])->toBe(TimesheetDayStatus::NoPunches)
            ->and($day['justified'])->toBeFalse()
            ->and($day['needs_review'])->toBeTrue()
            ->and($day['status_label'])->toBe('Sem marcação')
            ->and($day['justifications'])->toHaveCount(1);
    });

    test('a full day justification does not hide other kinds of review', function () {
        ($this->punches)('2026-07-08', ['07:30', '12:00', '17:30']);
        ($this->fullDay)('2026-07-08');

        $day = ($this->day)();

        expect($day['status'])->toBe(TimesheetDayStatus::OddPunches)
            ->and($day['justified'])->toBeFalse()
            ->and($day['needs_review'])->toBeTrue();
    });
});

describe('only active justifications, reflected on every read', function () {
    test('a canceled justification has no effect', function () {
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30']);
        ($this->interval)('2026-07-08', '07:30', '08:30', 'full_allowance', null, ['canceled_at' => now(), 'canceled_by' => User::factory(), 'cancellation_reason' => 'Engano']);
        ($this->fullDay)('2026-07-09', 'full_allowance', ['canceled_at' => now(), 'canceled_by' => User::factory(), 'cancellation_reason' => 'Engano']);

        $days = collect(($this->mirror)('2026-07-08', '2026-07-09')['days'])->keyBy('date');

        expect($days['2026-07-08']['allowed_delay_minutes'])->toBe(0)
            ->and($days['2026-07-08']['delay_minutes'])->toBe(55)
            ->and($days['2026-07-08']['justifications'])->toBe([])
            ->and($days['2026-07-09']['justified'])->toBeFalse()
            ->and($days['2026-07-09']['needs_review'])->toBeTrue();
    });

    test('editing, moving and canceling the justification is reflected without recalculation', function () {
        $this->seed(PointJustificationReasonSeeder::class);
        $hr = User::factory()->create();
        $hr->grantPermission(Permission::Hr);
        $hr->refresh();
        $service = app(PointJustificationService::class);
        $reasonId = PointJustificationReason::query()->where('code', 'justified_delay')->value('id');
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30']);
        ($this->punches)('2026-07-09', ['08:30', '12:00', '13:00', '17:30']);
        $input = fn (array $extra = []) => ['reason_id' => $reasonId, 'treatment' => 'full_allowance', 'start_time' => '07:30', 'end_time' => '08:30', ...$extra];
        $delays = fn () => collect(($this->mirror)('2026-07-08', '2026-07-09')['days'])->pluck('delay_minutes', 'date')->all();

        $justification = $service->register($this->employee, '2026-07-08', ['request_uuid' => (string) Str::uuid(), ...$input()], $hr)['justification'];
        expect($delays())->toBe(['2026-07-08' => 0, '2026-07-09' => 55]);

        $service->update($justification, $input(['treatment' => 'partial_allowance', 'allowed_minutes' => 15]), 1, $hr);
        expect($delays())->toBe(['2026-07-08' => 40, '2026-07-09' => 55]);

        $service->update($justification, $input(), 2, $hr);
        expect($delays())->toBe(['2026-07-08' => 0, '2026-07-09' => 55]);

        $service->update($justification, $input(['occurrence_date' => '2026-07-09']), 3, $hr);
        expect($delays())->toBe(['2026-07-08' => 55, '2026-07-09' => 0]);

        $service->cancel($justification, 'Lançado por engano', 4, $hr);
        expect($delays())->toBe(['2026-07-08' => 55, '2026-07-09' => 55]);
    });

    test('a failed write leaves the mirror in the previous state', function () {
        $this->seed(PointJustificationReasonSeeder::class);
        $hr = User::factory()->create();
        $hr->grantPermission(Permission::Hr);
        $hr->refresh();
        $service = app(PointJustificationService::class);
        $reasonId = PointJustificationReason::query()->where('code', 'justified_delay')->value('id');
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30']);
        ($this->punches)('2026-07-09', ['08:30', '12:00', '13:00', '17:30']);
        $input = fn (array $extra = []) => ['reason_id' => $reasonId, 'treatment' => 'full_allowance', 'start_time' => '07:30', 'end_time' => '08:30', ...$extra];
        $delays = fn () => collect(($this->mirror)('2026-07-08', '2026-07-09')['days'])->pluck('delay_minutes', 'date')->all();
        $justification = $service->register($this->employee, '2026-07-08', ['request_uuid' => (string) Str::uuid(), ...$input()], $hr)['justification'];
        $before = $delays();

        PointJustification::created(fn () => throw new RuntimeException('Falha simulada na gravação.'));
        PointJustificationHistory::creating(fn () => throw new RuntimeException('Falha simulada na gravação.'));

        expect(fn () => $service->register($this->employee, '2026-07-09', ['request_uuid' => (string) Str::uuid(), ...$input()], $hr))->toThrow(RuntimeException::class, 'Falha simulada na gravação.')
            ->and(fn () => $service->update($justification, $input(['treatment' => 'partial_allowance', 'allowed_minutes' => 15]), 1, $hr))->toThrow(RuntimeException::class, 'Falha simulada na gravação.')
            ->and(fn () => $service->cancel($justification, 'Lançado por engano', 1, $hr))->toThrow(RuntimeException::class, 'Falha simulada na gravação.')
            ->and($before)->toBe(['2026-07-08' => 0, '2026-07-09' => 55])
            ->and($delays())->toBe($before)
            ->and(PointJustification::query()->count())->toBe(1)
            ->and($justification->fresh()->only(['version', 'treatment', 'canceled_at']))->toBe(['version' => 1, 'treatment' => JustificationTreatment::FullAllowance, 'canceled_at' => null]);
    });

    test('justifications of other employees never apply', function () {
        $other = Employee::factory()->create();
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30']);
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30'], $other);
        ($this->interval)('2026-07-08', '07:30', '08:30', 'full_allowance', null, ['employee' => $other]);
        PointJustification::factory()->for($other)->create(['reason_id' => $this->absenceReason->id, 'occurrence_date' => '2026-07-09']);

        $days = collect(($this->mirror)('2026-07-08', '2026-07-09')['days'])->keyBy('date');

        expect($days['2026-07-08']['delay_minutes'])->toBe(55)
            ->and($days['2026-07-08']['justifications'])->toBe([])
            ->and($days['2026-07-09']['justified'])->toBeFalse()
            ->and(($this->day)('2026-07-08', $other)['delay_minutes'])->toBe(0);
    });
});

describe('integrity and compatibility', function () {
    test('without justifications the mirror is the same as the plain build', function () {
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:00']);
        ($this->punches)('2026-07-09', ['07:30', '12:00', '17:30']);
        $start = CarbonImmutable::parse('2026-07-06');
        $end = CarbonImmutable::parse('2026-07-12');

        $loaded = app(TimesheetMirror::class)->forEmployee($this->employee, $start, $end);
        $plain = app(TimesheetMirror::class)->build($this->employee->points()->orderBy('date')->orderBy('time')->get(), $start, $end);

        expect($loaded)->toEqual($plain)
            ->and(collect($loaded['days'])->every(fn (array $day) => $day['delay_minutes'] === $day['delay_before_allowance_minutes']))->toBeTrue()
            ->and($loaded['totals']['allowed_delay_minutes'])->toBe(0)
            ->and($loaded['totals']['delay_minutes'])->toBe($loaded['totals']['delay_before_allowance_minutes']);
    });

    test('totals add the gross, allowed and net delay', function () {
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30']);
        ($this->punches)('2026-07-09', ['08:00', '12:00', '13:00', '17:30']);
        ($this->interval)('2026-07-08', '07:30', '08:30');

        $totals = ($this->mirror)('2026-07-08', '2026-07-09')['totals'];

        expect($totals['delay_before_allowance_minutes'])->toBe(80)
            ->and($totals['allowed_delay_minutes'])->toBe(55)
            ->and($totals['delay_minutes'])->toBe(25)
            ->and($totals['delay'])->toBe('00:25');
    });

    test('reading the mirror writes nothing and keeps the imported punches', function () {
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30']);
        ($this->interval)('2026-07-08', '07:30', '08:30');
        ($this->fullDay)('2026-07-09');
        BenefitPeriod::factory()->forCompetence('2026-08')->closed()->create();
        $tables = ['points', 'point_justifications', 'point_justification_histories', 'benefit_periods', 'benefit_adjustments'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
        $before = $snapshot();

        $day = ($this->day)();

        expect($day['delay_minutes'])->toBe(0)
            ->and($snapshot())->toBe($before);
    });

    test('justifications are loaded with a fixed number of queries', function () {
        foreach (['2026-07-06', '2026-07-07', '2026-07-08', '2026-07-09', '2026-07-10'] as $date) {
            ($this->punches)($date, ['08:30', '12:00', '13:00', '17:30']);
        }

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->mirror)('2026-07-06', '2026-07-12');
            $queries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'point_justification'))->count();
            DB::disableQueryLog();

            return $queries;
        };

        ($this->interval)('2026-07-06', '07:30', '08:00');
        $withOne = $count();

        foreach (['2026-07-07', '2026-07-08', '2026-07-09', '2026-07-10'] as $date) {
            ($this->interval)($date, '07:30', '08:00');
        }

        expect($count())->toBe($withOne)
            ->and($withOne)->toBe(2);
    });

    test('the service groups several employees in one query', function () {
        $other = Employee::factory()->create();
        ($this->interval)('2026-07-08', '07:30', '08:00');
        ($this->interval)('2026-07-08', '07:30', '08:00', 'full_allowance', null, ['employee' => $other]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $grouped = app(TimesheetJustifications::class)->forEmployees([$this->employee->id, $other->id], CarbonImmutable::parse('2026-07-08'), CarbonImmutable::parse('2026-07-08'));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect(array_keys($grouped))->toEqualCanonicalizing([$this->employee->id, $other->id])
            ->and($queries)->toBe(2);
    });

    test('the overtime screen and the print show the same net delay', function () {
        $this->actingAs(User::factory()->create());
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30']);
        ($this->interval)('2026-07-08', '07:30', '08:00');

        $screen = Livewire::withQueryParams(['startDate' => '2026-07-08', 'endDate' => '2026-07-08'])
            ->test(EmployeeHorasExtras::class, ['employee' => $this->employee->id]);

        expect($screen->get('totalDelayMinutes'))->toBe(25)
            ->and(collect($screen->get('days'))->sole()['allowed_delay_minutes'])->toBe(30);

        $this->get(route('reports.timesheet.print', ['start' => '2026-07-08', 'end' => '2026-07-08', 'employees' => [$this->employee->id]]))
            ->assertOk()
            ->assertViewHas('timesheets', fn ($timesheets) => $timesheets->sole()['totals']['delay_minutes'] === 25
                && $timesheets->sole()['days'][0]['allowed_delay_minutes'] === 30);
    });
});
