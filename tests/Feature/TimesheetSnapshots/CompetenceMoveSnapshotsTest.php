<?php

use App\Enums\JustificationHistoryAction;
use App\Enums\Permission;
use App\Enums\TimesheetSnapshotSource;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\Point;
use App\Models\PointJustification;
use App\Models\PointJustificationHistory;
use App\Models\PointJustificationReason;
use App\Models\TimesheetSnapshot;
use App\Models\User;
use App\Services\BenefitPeriodWorkflow;
use App\Services\PointJustificationService;
use App\Services\TimesheetSnapshotHistory;
use App\Services\TimesheetSnapshotRecorder;
use Database\Seeders\PointJustificationReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * F6.3: mudança de data de justificativa entre datas e competências, com fechamentos reais
 * (BenefitPeriodWorkflow). Setembro pertence à competência 10/2026; outubro, à 11/2026.
 * Os dias de competência fechada já têm `closure_baseline` (versão 1) antes da mudança.
 */
beforeEach(function () {
    $this->seed(PointJustificationReasonSeeder::class);

    $this->hr = User::factory()->create(['name' => 'Analista RH']);
    $this->hr->grantPermission(Permission::Hr);
    $this->hr->grantPermission(Permission::HrClosedPeriod);
    $this->hr->refresh();
    $this->actingAs($this->hr);

    $this->workflow = app(BenefitPeriodWorkflow::class);
    $this->service = app(PointJustificationService::class);
    $this->history = app(TimesheetSnapshotHistory::class);

    $this->employee = Employee::factory()->create(['name' => 'Ana Souza']);

    foreach (['2026-09-15', '2026-09-16', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-15'] as $date) {
        foreach (['07:50', '12:00', '13:00', '17:30'] as $time) {
            Point::factory()->forEmployee($this->employee)->on($date)->create(['time' => $time.':00']);
        }
    }

    $this->reason = fn (string $code) => PointJustificationReason::query()->where('code', $code)->sole()->id;
    $this->interval = fn (string $start, string $end, array $extra = []) => ['reason_id' => ($this->reason)('justified_delay'), 'treatment' => 'full_allowance', 'start_time' => $start, 'end_time' => $end, ...$extra];
    $this->punch = fn (string $time, array $extra = []) => ['reason_id' => ($this->reason)('forgotten_punch'), 'treatment' => 'no_allowance', 'start_time' => $time, 'punch_kind' => 'entry', ...$extra];

    $this->register = fn (array $input, string $date) => $this->service
        ->register($this->employee, $date, ['request_uuid' => (string) Str::uuid(), ...$input], $this->hr)['justification'];
    $this->move = fn (PointJustification $justification, array $input) => $this->service
        ->update($justification, $input, $justification->fresh()->version, $this->hr);

    /*
     * Fecha a competência pelo fluxo real (cálculo e fechamento); a anterior precisa estar fechada.
     */
    $this->closeCompetence = function (string $yearMonth): BenefitPeriod {
        $period = BenefitPeriod::query()->where('competence', $yearMonth.'-01')->first() ?? BenefitPeriod::factory()->forCompetence($yearMonth)->create();
        $this->workflow->calculate($period);
        $this->workflow->close($period->fresh());

        return $period->fresh();
    };

    $this->rows = fn () => DB::table('timesheet_snapshots')->orderBy('id')->get()->keyBy('id')->map(fn ($row) => (array) $row)->all();
    $this->tables = fn () => collect(['points', 'point_justifications', 'point_justification_histories', 'timesheet_snapshots', 'benefit_periods'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])
        ->all();
    $this->changes = fn (int $afterId) => TimesheetSnapshot::query()->where('id', '>', $afterId)->orderBy('id')->get();
    $this->describeChanges = fn ($snapshots) => $snapshots
        ->map(fn (TimesheetSnapshot $snapshot) => [$snapshot->date->toDateString(), $snapshot->source->value, $snapshot->benefitPeriod?->competence->format('Y-m')])
        ->sortBy(fn (array $row) => $row[0].($row[1] === 'before_change' ? '1' : '2'))
        ->values()->all();
    $this->sequencesAreContiguous = fn () => TimesheetSnapshot::query()->where('employee_id', $this->employee->id)->orderBy('sequence')->get()
        ->groupBy(fn (TimesheetSnapshot $snapshot) => $snapshot->date->toDateString())
        ->every(fn ($versions) => $versions->pluck('sequence')->all() === range(1, $versions->count()));
});

describe('same closed competence', function () {
    test('moving inside a closed competence records origin and destination in one operation and keeps the closing', function () {
        $justification = ($this->register)(($this->interval)('07:30', '07:50'), '2026-09-15');
        $october = ($this->closeCompetence)('2026-10');
        $before = ($this->rows)();
        $lastId = max(array_keys($before));

        ($this->move)($justification, ($this->interval)('07:30', '07:50', ['occurrence_date' => '2026-09-16']));

        $changes = ($this->changes)($lastId);
        $history = PointJustificationHistory::query()->where('point_justification_id', $justification->id)->where('action', JustificationHistoryAction::Updated)->sole();
        $byDay = $changes->groupBy(fn ($snapshot) => $snapshot->date->toDateString());

        expect(($this->describeChanges)($changes))->toBe([
            ['2026-09-15', 'before_change', '2026-10'],
            ['2026-09-15', 'after_change', '2026-10'],
            ['2026-09-16', 'before_change', '2026-10'],
            ['2026-09-16', 'after_change', '2026-10'],
        ])
            ->and($changes->pluck('operation_uuid')->unique())->toHaveCount(1)
            ->and($changes->pluck('point_justification_history_id')->unique()->all())->toBe([$history->id])
            ->and($changes->pluck('benefit_period_id')->unique()->all())->toBe([$october->id])
            ->and($changes->pluck('sequence')->unique()->values()->all())->toBe([2, 3])
            ->and($byDay['2026-09-15']->map(fn ($snapshot) => $snapshot->result['delay_minutes'])->all())->toBe([0, 15])
            ->and($byDay['2026-09-16']->map(fn ($snapshot) => $snapshot->result['delay_minutes'])->all())->toBe([15, 0])
            ->and(array_intersect_key(($this->rows)(), $before))->toBe($before)
            ->and(($this->sequencesAreContiguous)())->toBeTrue();
    });
});

describe('two competences', function () {
    test('moving between two closed competences records each day with its own competence', function () {
        $justification = ($this->register)(($this->interval)('07:30', '07:50'), '2026-09-16');
        $october = ($this->closeCompetence)('2026-10');
        $november = ($this->closeCompetence)('2026-11');
        $before = ($this->rows)();
        $lastId = max(array_keys($before));

        ($this->move)($justification, ($this->interval)('07:30', '07:50', ['occurrence_date' => '2026-10-15']));

        $changes = ($this->changes)($lastId);
        $byDay = $changes->groupBy(fn ($snapshot) => $snapshot->date->toDateString());

        expect(($this->describeChanges)($changes))->toBe([
            ['2026-09-16', 'before_change', '2026-10'],
            ['2026-09-16', 'after_change', '2026-10'],
            ['2026-10-15', 'before_change', '2026-11'],
            ['2026-10-15', 'after_change', '2026-11'],
        ])
            ->and($changes->every(fn ($snapshot) => $snapshot->competence->format('Y-m') === $snapshot->benefitPeriod->competence->format('Y-m')))->toBeTrue()
            ->and($byDay['2026-09-16']->pluck('benefit_period_id')->unique()->all())->toBe([$october->id])
            ->and($byDay['2026-10-15']->pluck('benefit_period_id')->unique()->all())->toBe([$november->id])
            ->and($changes->pluck('operation_uuid')->unique())->toHaveCount(1)
            ->and($byDay['2026-09-16']->map(fn ($snapshot) => count($snapshot->inputs['calculation']['justifications']))->all())->toBe([1, 0])
            ->and($byDay['2026-10-15']->map(fn ($snapshot) => count($snapshot->inputs['calculation']['justifications']))->all())->toBe([0, 1])
            ->and($byDay['2026-09-16']->map(fn ($snapshot) => $snapshot->result['delay_minutes'])->all())->toBe([0, 15])
            ->and($byDay['2026-10-15']->map(fn ($snapshot) => $snapshot->result['delay_minutes'])->all())->toBe([15, 0])
            ->and(array_intersect_key(($this->rows)(), $before))->toBe($before)
            ->and(($this->sequencesAreContiguous)())->toBeTrue();
    });

    test('a justification with a manual punch moves the punch and both competences record it', function () {
        $justification = ($this->register)(($this->punch)('18:00'), '2026-09-16');
        ($this->closeCompetence)('2026-10');
        ($this->closeCompetence)('2026-11');
        $lastId = TimesheetSnapshot::query()->max('id');

        $moved = ($this->move)($justification, ($this->punch)('18:00', ['occurrence_date' => '2026-10-15']))['justification'];

        $byDay = ($this->changes)($lastId)->groupBy(fn ($snapshot) => $snapshot->date->toDateString());
        $punches = fn ($snapshot) => collect($snapshot->inputs['calculation']['points'])->where('type', 'manual')->pluck('time')->all();

        expect($moved->manualPoint->date->toDateString())->toBe('2026-10-15')
            ->and($byDay['2026-09-16']->map($punches)->all())->toBe([['18:00:00'], []])
            ->and($byDay['2026-10-15']->map($punches)->all())->toBe([[], ['18:00:00']]);
    });

    test('each combination of closed and open competences records only the closed days', function (string $origin, string $destination, array $closing, array $expected) {
        $justification = ($this->register)(($this->interval)('07:30', '07:50'), $origin);
        foreach ($closing as $competence) {
            ($this->closeCompetence)($competence);
        }
        $before = ($this->rows)();
        $lastId = $before === [] ? 0 : max(array_keys($before));

        ($this->move)($justification, ($this->interval)('07:30', '07:50', ['occurrence_date' => $destination]));

        $changes = ($this->changes)($lastId);

        expect(($this->describeChanges)($changes))->toBe($expected)
            ->and($justification->fresh()->occurrence_date->toDateString())->toBe($destination)
            ->and(array_intersect_key(($this->rows)(), $before))->toBe($before)
            ->and(TimesheetSnapshot::query()->where('source', TimesheetSnapshotSource::LateBaseline)->count())->toBe(0)
            ->and(($this->sequencesAreContiguous)())->toBeTrue();
    })->with([
        'closed to closed' => ['2026-09-16', '2026-10-15', ['2026-10', '2026-11'], [
            ['2026-09-16', 'before_change', '2026-10'], ['2026-09-16', 'after_change', '2026-10'],
            ['2026-10-15', 'before_change', '2026-11'], ['2026-10-15', 'after_change', '2026-11'],
        ]],
        'closed to open' => ['2026-09-16', '2026-10-15', ['2026-10'], [
            ['2026-09-16', 'before_change', '2026-10'], ['2026-09-16', 'after_change', '2026-10'],
        ]],
        'open to closed' => ['2026-10-15', '2026-09-16', ['2026-10'], [
            ['2026-09-16', 'before_change', '2026-10'], ['2026-09-16', 'after_change', '2026-10'],
        ]],
        'open to open' => ['2026-09-16', '2026-10-15', [], []],
    ]);

    test('the last day of a competence moved to the first day of the next one', function (array $closing, array $expected) {
        $justification = ($this->register)(($this->interval)('07:30', '07:50'), '2026-09-30');
        foreach ($closing as $competence) {
            ($this->closeCompetence)($competence);
        }
        $lastId = (int) TimesheetSnapshot::query()->max('id');

        ($this->move)($justification, ($this->interval)('07:30', '07:50', ['occurrence_date' => '2026-10-01']));

        expect(($this->describeChanges)(($this->changes)($lastId)))->toBe($expected)
            ->and(($this->sequencesAreContiguous)())->toBeTrue();
    })->with([
        'both closed' => [['2026-10', '2026-11'], [
            ['2026-09-30', 'before_change', '2026-10'], ['2026-09-30', 'after_change', '2026-10'],
            ['2026-10-01', 'before_change', '2026-11'], ['2026-10-01', 'after_change', '2026-11'],
        ]],
        'next one open' => [['2026-10'], [
            ['2026-09-30', 'before_change', '2026-10'], ['2026-09-30', 'after_change', '2026-10'],
        ]],
    ]);
});

describe('midnight', function () {
    test('moving a justification that crosses midnight records the old and the new days in their competences', function (array $closing, array $expected) {
        $justification = ($this->register)(($this->interval)('22:00', '08:00', ['ends_next_day' => true]), '2026-09-29');
        foreach ($closing as $competence) {
            ($this->closeCompetence)($competence);
        }
        $lastId = (int) TimesheetSnapshot::query()->max('id');

        ($this->move)($justification, ($this->interval)('22:00', '08:00', ['ends_next_day' => true, 'occurrence_date' => '2026-09-30']));

        $changes = ($this->changes)($lastId);
        $byDay = $changes->groupBy(fn ($snapshot) => $snapshot->date->toDateString());
        $notes = fn ($snapshot) => count($snapshot->result['justification_notes']);

        expect(($this->describeChanges)($changes))->toBe($expected)
            ->and($changes->pluck('operation_uuid')->unique())->toHaveCount(1)
            ->and($byDay['2026-09-29']->map($notes)->all())->toBe([1, 0])
            ->and($byDay['2026-09-30']->map($notes)->all())->toBe([1, 1])
            ->and($byDay['2026-09-30'][1]->inputs['calculation']['justifications'][0]['occurrence_date'])->toBe('2026-09-30')
            ->and(($this->sequencesAreContiguous)())->toBeTrue();

        if (isset($byDay['2026-10-01'])) {
            expect($byDay['2026-10-01']->map(fn ($snapshot) => [$notes($snapshot), $snapshot->result['delay_minutes']])->all())->toBe([[0, 15], [1, 0]])
                ->and($byDay['2026-10-01'][1]->inputs['calculation']['justifications'][0]['occurrence_date'])->toBe('2026-09-30');
        }
    })->with([
        'both closed' => [['2026-10', '2026-11'], [
            ['2026-09-29', 'before_change', '2026-10'], ['2026-09-29', 'after_change', '2026-10'],
            ['2026-09-30', 'before_change', '2026-10'], ['2026-09-30', 'after_change', '2026-10'],
            ['2026-10-01', 'before_change', '2026-11'], ['2026-10-01', 'after_change', '2026-11'],
        ]],
        'next competence open' => [['2026-10'], [
            ['2026-09-29', 'before_change', '2026-10'], ['2026-09-29', 'after_change', '2026-10'],
            ['2026-09-30', 'before_change', '2026-10'], ['2026-09-30', 'after_change', '2026-10'],
        ]],
    ]);
});

describe('atomicity', function () {
    test('a failure at any step of a move between closed competences rolls everything back', function (string $failure) {
        $justification = ($this->register)(($this->punch)('18:00'), '2026-09-16');
        ($this->closeCompetence)('2026-10');
        ($this->closeCompetence)('2026-11');
        $before = ($this->tables)();

        [$model, $event, $occurrence] = match ($failure) {
            'manual punch move' => [Point::class, 'updating', 1],
            'justification change' => [PointJustification::class, 'updating', 1],
            'history' => [PointJustificationHistory::class, 'creating', 1],
            'origin before' => [TimesheetSnapshot::class, 'creating', 1],
            'origin after' => [TimesheetSnapshot::class, 'creating', 2],
            'destination before' => [TimesheetSnapshot::class, 'creating', 3],
            'destination after' => [TimesheetSnapshot::class, 'creating', 4],
        };
        $count = 0;
        $model::$event(function () use (&$count, $occurrence) {
            if (++$count === $occurrence) {
                throw new RuntimeException('Falha simulada.');
            }
        });

        expect(fn () => ($this->move)($justification, ($this->punch)('18:00', ['occurrence_date' => '2026-10-15'])))->toThrow(RuntimeException::class, 'Falha simulada.')
            ->and(($this->tables)())->toBe($before)
            ->and($count)->toBe($occurrence);
    })->with(['manual punch move', 'justification change', 'history', 'origin before', 'origin after', 'destination before', 'destination after']);

    test('a failure in the late baseline of a destination closed before the deployment rolls everything back', function () {
        $justification = ($this->register)(($this->interval)('07:30', '07:50'), '2026-09-16');
        ($this->closeCompetence)('2026-10');
        BenefitPeriod::factory()->forCompetence('2026-11')->closed()->create();
        $before = ($this->tables)();
        $count = 0;
        TimesheetSnapshot::creating(function (TimesheetSnapshot $snapshot) use (&$count) {
            $count++;

            if ($snapshot->source === TimesheetSnapshotSource::LateBaseline) {
                throw new RuntimeException('Falha simulada.');
            }
        });

        expect(fn () => ($this->move)($justification, ($this->interval)('07:30', '07:50', ['occurrence_date' => '2026-10-15'])))->toThrow(RuntimeException::class)
            ->and($count)->toBe(3)
            ->and(($this->tables)())->toBe($before);
    });
});

describe('historical integrity', function () {
    test('after a move between closed competences hashes, references, consultation and print are consistent', function () {
        $justification = ($this->register)(($this->interval)('07:30', '07:50'), '2026-09-16');
        ($this->closeCompetence)('2026-10');
        ($this->closeCompetence)('2026-11');
        $lastId = (int) TimesheetSnapshot::query()->max('id');

        ($this->move)($justification, ($this->interval)('07:30', '07:50', ['occurrence_date' => '2026-10-15']));

        $changes = ($this->changes)($lastId);
        $after = $changes->where('source', TimesheetSnapshotSource::AfterChange)->keyBy(fn ($snapshot) => $snapshot->date->toDateString());
        $beforeChange = $changes->where('source', TimesheetSnapshotSource::BeforeChange)->keyBy(fn ($snapshot) => $snapshot->date->toDateString());

        foreach (['2026-09-16', '2026-10-15'] as $date) {
            $reference = $this->history->reference($this->employee, $date);

            expect($reference['snapshot']->id)->toBe($after[$date]->id)
                ->and($reference['stale'])->toBeFalse()
                ->and($this->history->notice($this->employee, $date))->toBeNull()
                ->and(app(TimesheetSnapshotRecorder::class)->divergence($after[$date]))->toMatchArray(['inputs_changed' => false, 'result_changed' => false])
                ->and(app(TimesheetSnapshotRecorder::class)->divergence($beforeChange[$date]))->toMatchArray(['inputs_changed' => true, 'result_changed' => true])
                ->and($changes->every(fn ($snapshot) => $snapshot->integrityProblems() === []))->toBeTrue();
        }

        $response = $this->get(route('reports.timesheet.snapshot-print', $after['2026-09-16']))->assertOk();

        expect($response->viewData('days')->pluck('snapshot.id')->all())->toBe([$after['2026-09-16']->id])
            ->and($response->viewData('selected')['competence'])->toBe('10/2026');

        $response = $this->get(route('reports.timesheet.snapshot-print', $after['2026-10-15']))->assertOk();

        expect($response->viewData('days')->pluck('snapshot.id')->all())->toBe([$after['2026-10-15']->id])
            ->and($response->viewData('selected')['competence'])->toBe('11/2026');
    });
});
