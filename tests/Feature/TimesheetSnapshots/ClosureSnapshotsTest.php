<?php

use App\Enums\BenefitPeriodStatus;
use App\Enums\Permission;
use App\Enums\TimesheetSnapshotSource;
use App\Livewire\Benefits\BenefitPeriodIndex;
use App\Livewire\EmployeeIndex;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Point;
use App\Models\PointJustificationReason;
use App\Models\TimesheetSnapshot;
use App\Models\User;
use App\Services\BenefitPeriodWorkflow;
use App\Services\PointJustificationService;
use App\Services\TimesheetMirror;
use Carbon\CarbonImmutable;
use Database\Seeders\PointJustificationReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * F6.2c: ponto de partida do espelho no fechamento, reabertura, ponto de partida tardio e
 * exclusões. Competência 10/2026: janela de 01/09/2026 a 30/09/2026 (mês civil anterior).
 * Os espelhos de 26/08–25/09 e 26/09–25/10 atravessam a janela.
 */
beforeEach(function () {
    $this->seed(PointJustificationReasonSeeder::class);
    Holiday::factory()->create(['date' => '2026-09-07', 'description' => 'Independência']);
    Holiday::flushCachedHolidays();

    $this->user = User::factory()->create(['name' => 'Analista RH']);
    $this->user->grantPermission(Permission::Hr);
    $this->user->grantPermission(Permission::HrClosedPeriod);
    $this->user->refresh();
    $this->actingAs($this->user);

    $this->workflow = app(BenefitPeriodWorkflow::class);
    $this->service = app(PointJustificationService::class);

    $this->punches = function (Employee $employee, string $date, array $times = ['07:30', '12:00', '13:00', '17:30']): void {
        foreach ($times as $time) {
            Point::factory()->forEmployee($employee)->on($date)->create(['time' => $time.':00']);
        }
    };

    $this->ana = Employee::factory()->create(['name' => 'Ana Souza']);
    $this->bruno = Employee::factory()->create(['name' => 'Bruno Lima']);
    $this->carla = Employee::factory()->create(['name' => 'Carla Dias', 'recision_date' => '2026-07-31 00:00:00']);
    $this->davi = Employee::factory()->create(['name' => 'Davi Rocha', 'recision_date' => '2026-07-31 00:00:00']);

    foreach (['2026-08-28', '2026-09-01', '2026-09-15', '2026-09-26', '2026-10-01'] as $date) {
        ($this->punches)($this->ana, $date);
    }
    ($this->punches)($this->davi, '2026-09-10', ['08:00', '12:00']);

    $this->reason = fn (string $code) => PointJustificationReason::query()->where('code', $code)->sole();
    $this->justify = fn (Employee $employee, string $date, string $start, string $end, bool $endsNextDay = false) => $this->service->register($employee, $date, [
        'request_uuid' => (string) Str::uuid(),
        'reason_id' => ($this->reason)('justified_delay')->id,
        'treatment' => 'full_allowance',
        'start_time' => $start,
        'end_time' => $end,
        'ends_next_day' => $endsNextDay,
    ], $this->user)['justification'];

    $this->period = BenefitPeriod::factory()->forCompetence('2026-10')->create();
    $this->close = function (): void {
        $this->workflow->calculate($this->period);
        $this->workflow->close($this->period);
    };

    $this->september = collect(range(1, 30))->map(fn (int $day) => sprintf('2026-09-%02d', $day))->all();
    $this->rows = fn () => DB::table('timesheet_snapshots')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
});

describe('closing', function () {
    test('the closing records every day of the window for each employee in scope, inside the same operation', function () {
        ($this->close)();

        $snapshots = TimesheetSnapshot::query()->orderBy('id')->get();
        $byEmployee = $snapshots->groupBy('employee_id');

        expect($this->period->fresh()->status)->toBe(BenefitPeriodStatus::Closed)
            ->and($byEmployee->keys()->sort()->values()->all())->toBe([$this->ana->id, $this->bruno->id, $this->davi->id])
            ->and($byEmployee->map(fn ($days) => $days->map(fn ($snapshot) => $snapshot->date->toDateString())->all())->all())
            ->toBe([$this->ana->id => $this->september, $this->bruno->id => $this->september, $this->davi->id => $this->september])
            ->and($snapshots->every(fn ($snapshot) => $snapshot->source === TimesheetSnapshotSource::ClosureBaseline
                && $snapshot->sequence === 1
                && $snapshot->benefit_period_id === $this->period->id
                && $snapshot->competence->toDateString() === '2026-10-01'
                && $snapshot->created_by === $this->user->id
                && $snapshot->reason === 'Fechamento nº 1 da competência 10/2026.'))->toBeTrue()
            ->and($snapshots->pluck('operation_uuid')->unique())->toHaveCount(1)
            ->and($snapshots->first()->operation_uuid)->not->toBeNull()
            ->and($snapshots->every(fn ($snapshot) => $snapshot->integrityProblems() === []))->toBeTrue();
    });

    test('employees without punches, weekends and holidays are recorded', function () {
        ($this->close)();

        $bruno = TimesheetSnapshot::query()->where('employee_id', $this->bruno->id)->get()->keyBy(fn ($snapshot) => $snapshot->date->toDateString());

        expect($bruno['2026-09-15']->result['status'])->toBe('no_punches')
            ->and($bruno['2026-09-15']->result['needs_review'])->toBeTrue()
            ->and($bruno['2026-09-05']->result['day_type'])->toBe('saturday')
            ->and($bruno['2026-09-06']->result['day_type'])->toBe('sunday')
            ->and($bruno['2026-09-07']->result['day_type'])->toBe('holiday')
            ->and($bruno['2026-09-07']->inputs['calculation']['holiday'])->toBe('Independência');
    });

    test('the stored days are the same as both mirrors that cross the window', function () {
        ($this->close)();

        $mirror = app(TimesheetMirror::class);
        $days = collect([
            ...$mirror->forEmployee($this->ana, CarbonImmutable::parse('2026-08-26'), CarbonImmutable::parse('2026-09-25'))['days'],
            ...$mirror->forEmployee($this->ana, CarbonImmutable::parse('2026-09-26'), CarbonImmutable::parse('2026-10-25'))['days'],
        ])->keyBy('date');
        $stored = TimesheetSnapshot::query()->where('employee_id', $this->ana->id)->get()->keyBy(fn ($snapshot) => $snapshot->date->toDateString());

        expect($stored->keys()->all())->toBe($this->september)
            ->and($stored->has('2026-08-28'))->toBeFalse()
            ->and($stored->has('2026-10-01'))->toBeFalse();

        foreach ($stored as $date => $snapshot) {
            expect($snapshot->result)->toEqual(json_decode(json_encode($days[$date]), true));
        }

        expect($stored['2026-09-15']->result['punch_count'])->toBe(4)
            ->and($stored['2026-09-26']->result['punch_count'])->toBe(4);
    });

    test('justifications crossing midnight at the window edges are inputs only of the days they reach', function () {
        ($this->justify)($this->ana, '2026-08-31', '22:00', '08:00', true);
        ($this->justify)($this->ana, '2026-09-30', '22:00', '07:40', true);

        ($this->close)();

        $stored = TimesheetSnapshot::query()->where('employee_id', $this->ana->id)->get()->keyBy(fn ($snapshot) => $snapshot->date->toDateString());

        expect(array_column($stored['2026-09-01']->inputs['calculation']['justifications'], 'occurrence_date'))->toBe(['2026-08-31'])
            ->and(array_column($stored['2026-09-30']->inputs['calculation']['justifications'], 'occurrence_date'))->toBe(['2026-09-30'])
            ->and($stored['2026-09-02']->inputs['calculation']['justifications'])->toBe([]);
    });

    test('a failure in any snapshot keeps the competence calculated and leaves no snapshot', function (int $failing) {
        $this->workflow->calculate($this->period);
        $tables = fn () => collect(['benefit_periods', 'benefit_period_status_changes', 'benefit_period_employees', 'timesheet_snapshots'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])
            ->all();
        $before = $tables();
        $count = 0;
        TimesheetSnapshot::creating(function () use (&$count, $failing) {
            if (++$count === $failing) {
                throw new RuntimeException('Falha simulada no snapshot do fechamento.');
            }
        });

        expect(fn () => $this->workflow->close($this->period))->toThrow(RuntimeException::class, 'Falha simulada no snapshot do fechamento.')
            ->and($tables())->toBe($before)
            ->and($this->period->fresh()->status)->toBe(BenefitPeriodStatus::Calculated)
            ->and($this->period->fresh()->closed_at)->toBeNull();
    })->with(['first' => 1, 'middle' => 45, 'last' => 90]);

    test('a closing refused by the business rules records no snapshot', function () {
        expect(fn () => $this->workflow->close($this->period))->toThrow(DomainException::class, 'Somente competências calculadas')
            ->and(TimesheetSnapshot::query()->count())->toBe(0);
    });

    test('the benefit calculation is the same with the snapshots', function () {
        $this->workflow->calculate($this->period);
        $preview = DB::table('benefit_period_employees')->orderBy('employee_id')->get(['employee_id'])->all();

        $this->workflow->close($this->period);

        expect(DB::table('benefit_period_employees')->orderBy('employee_id')->get(['employee_id'])->all())->toEqual($preview);
    });
});

describe('reopening and closing again', function () {
    test('reopening keeps the snapshots and a new closing records new versions', function () {
        ($this->close)();
        $first = ($this->rows)();

        $this->workflow->reopen($this->period, 'Correção de ponto');

        expect(($this->rows)())->toBe($first);

        ($this->punches)($this->bruno, '2026-09-15');
        ($this->close)();

        $all = TimesheetSnapshot::query()->orderBy('id')->get();
        $second = $all->slice(count($first))->values();
        $bruno15 = $all->where('employee_id', $this->bruno->id)->filter(fn ($snapshot) => $snapshot->date->toDateString() === '2026-09-15')->values();

        expect(array_slice(($this->rows)(), 0, count($first)))->toBe($first)
            ->and($second)->toHaveCount(count($first))
            ->and($second->every(fn ($snapshot) => $snapshot->sequence === 2 && $snapshot->reason === 'Fechamento nº 2 da competência 10/2026.'))->toBeTrue()
            ->and($second->pluck('operation_uuid')->unique()->all())->not->toContain($first[0]['operation_uuid'])
            ->and($second->pluck('operation_uuid')->unique())->toHaveCount(1)
            ->and($bruno15->map(fn ($snapshot) => [$snapshot->sequence, $snapshot->result['status']])->all())->toBe([[1, 'no_punches'], [2, 'regular']]);
    });
});

describe('late baseline', function () {
    test('a competence closed before the deployment gets a late baseline on the first operation, only once', function () {
        BenefitPeriod::query()->whereKey($this->period->id)->update(['status' => BenefitPeriodStatus::Closed->value, 'closed_at' => now()]);

        $justification = ($this->justify)($this->ana, '2026-09-15', '07:30', '07:40');
        $first = TimesheetSnapshot::query()->orderBy('id')->get();

        $this->service->update($justification, ['reason_id' => ($this->reason)('justified_delay')->id, 'treatment' => 'full_allowance', 'start_time' => '07:30', 'end_time' => '07:35'], 1, $this->user);
        $all = TimesheetSnapshot::query()->orderBy('id')->get();

        expect($first->map(fn ($snapshot) => [$snapshot->source, $snapshot->sequence])->all())->toBe([
            [TimesheetSnapshotSource::LateBaseline, 1],
            [TimesheetSnapshotSource::BeforeChange, 2],
            [TimesheetSnapshotSource::AfterChange, 3],
        ])
            ->and($first[0]->result_hash)->toBe($first[1]->result_hash)
            ->and($first[0]->operation_uuid)->toBe($first[1]->operation_uuid)
            ->and($first[0]->reason)->toContain('não o fechamento original')
            ->and($first[0]->source->label())->toContain('não é o fechamento original')
            ->and($first[0]->source)->not->toBe(TimesheetSnapshotSource::ClosureBaseline)
            ->and($all->slice(3)->map(fn ($snapshot) => [$snapshot->source, $snapshot->sequence])->values()->all())->toBe([
                [TimesheetSnapshotSource::BeforeChange, 4],
                [TimesheetSnapshotSource::AfterChange, 5],
            ])
            ->and($all->where('source', TimesheetSnapshotSource::LateBaseline))->toHaveCount(1);
    });

    test('after a real closing no late baseline is recorded', function () {
        ($this->close)();
        $count = TimesheetSnapshot::query()->count();

        ($this->justify)($this->ana, '2026-09-15', '07:30', '07:40');

        expect(TimesheetSnapshot::query()->where('source', TimesheetSnapshotSource::LateBaseline)->count())->toBe(0)
            ->and(TimesheetSnapshot::query()->count())->toBe($count + 2);
    });

    test('an employee left out of the closing gets a late baseline', function () {
        ($this->close)();

        ($this->justify)($this->carla, '2026-09-15', '07:30', '07:40');

        expect(TimesheetSnapshot::query()->where('employee_id', $this->carla->id)->orderBy('id')->pluck('source')->all())
            ->toBe([TimesheetSnapshotSource::LateBaseline, TimesheetSnapshotSource::BeforeChange, TimesheetSnapshotSource::AfterChange]);
    });
});

describe('deletion', function () {
    test('an employee with snapshots cannot be deleted and the screen explains why', function () {
        ($this->close)();

        Livewire::test(EmployeeIndex::class)
            ->call('destroy', $this->bruno->id)
            ->assertDispatched('wireui:notification', fn (string $name, array $params) => $params[0]['options']['title'] === 'Exclusão Não Permitida'
                && str_contains($params[0]['options']['description'], 'registros históricos do espelho de ponto'));

        $this->assertModelExists($this->bruno);
        expect(TimesheetSnapshot::query()->where('employee_id', $this->bruno->id)->count())->toBe(30);
    });

    test('an employee without snapshots can still be deleted', function () {
        ($this->close)();

        Livewire::test(EmployeeIndex::class)->call('destroy', $this->carla->id);

        $this->assertModelMissing($this->carla);
    });

    test('a reopened competence with snapshots cannot be deleted and the screen explains why', function () {
        ($this->close)();
        $this->workflow->reopen($this->period, 'Correção');
        $period = $this->period->fresh();

        expect($period->status)->toBe(BenefitPeriodStatus::Open)
            ->and($this->workflow->deletionBlocker($period))->toContain('snapshots do espelho de ponto')
            ->and(fn () => $this->workflow->delete($period))->toThrow(DomainException::class, 'A competência 10/2026 não pode ser excluída. A competência possui snapshots do espelho de ponto');

        Livewire::test(BenefitPeriodIndex::class)
            ->assertViewHas('deletablePeriodIds', [])
            ->call('destroy', $period->id)
            ->assertDispatched('wireui:notification', fn (string $name, array $params) => $params[0]['options']['title'] === 'Exclusão Não Permitida'
                && str_contains($params[0]['options']['description'], 'snapshots do espelho de ponto'));

        $this->assertModelExists($period);
        expect(TimesheetSnapshot::query()->count())->toBe(90);
    });

    test('an open competence without snapshots keeps the previous deletion rules', function () {
        expect($this->workflow->deletionBlocker($this->period))->toBeNull();

        $this->workflow->delete($this->period);

        $this->assertModelMissing($this->period);
    });
});
