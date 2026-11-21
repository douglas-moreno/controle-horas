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
use App\Services\PointJustificationService;
use App\Services\TimesheetSnapshotRecorder;
use Database\Seeders\PointJustificationReasonSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/*
 * F6.2b: snapshots antes e depois das alterações de justificativas em competência fechada.
 * Datas de setembro pertencem à competência 10/2026; de outubro, à 11/2026.
 */
beforeEach(function () {
    $this->seed(PointJustificationReasonSeeder::class);

    $this->employee = Employee::factory()->create(['name' => 'Ana Souza']);
    $this->hr = User::factory()->create(['name' => 'Analista RH']);
    $this->hr->grantPermission(Permission::Hr);
    $this->hr->grantPermission(Permission::HrClosedPeriod);
    $this->hr->refresh();
    $this->service = app(PointJustificationService::class);
    $this->recorder = app(TimesheetSnapshotRecorder::class);

    foreach (['2026-09-15', '2026-09-16', '2026-10-15'] as $date) {
        foreach (['07:50', '12:00', '13:00', '17:30'] as $time) {
            Point::factory()->forEmployee($this->employee)->on($date)->create(['time' => $time.':00']);
        }
    }

    $this->reason = fn (string $code) => PointJustificationReason::query()->where('code', $code)->sole();
    $this->interval = fn (string $start, string $end, array $extra = []) => ['reason_id' => ($this->reason)('justified_delay')->id, 'treatment' => 'full_allowance', 'start_time' => $start, 'end_time' => $end, ...$extra];
    $this->punch = fn (string $time, string $kind, array $extra = []) => ['reason_id' => ($this->reason)('forgotten_punch')->id, 'treatment' => 'no_allowance', 'start_time' => $time, 'punch_kind' => $kind, ...$extra];

    $this->register = fn (array $input, string $date = '2026-09-15', ?User $user = null) => $this->service
        ->register($this->employee, $date, ['request_uuid' => (string) Str::uuid(), ...$input], $user ?? $this->hr)['justification'];
    $this->update = fn (PointJustification $justification, array $input, ?User $user = null) => $this->service
        ->update($justification, $input, $justification->fresh()->version, $user ?? $this->hr);
    $this->cancel = fn (PointJustification $justification, ?User $user = null) => $this->service
        ->cancel($justification, 'Lançado por engano', $justification->fresh()->version, $user ?? $this->hr);

    $this->close = fn (string $competence = '2026-10') => BenefitPeriod::factory()->forCompetence($competence)->closed()->create();

    $this->snapshots = fn () => TimesheetSnapshot::query()->whereIn('source', [TimesheetSnapshotSource::BeforeChange, TimesheetSnapshotSource::AfterChange])->orderBy('id')->get();
    $this->tables = fn () => collect(['points', 'point_justifications', 'point_justification_histories', 'timesheet_snapshots'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])
        ->all();
});

describe('open competence', function () {
    test('operations in an open competence record no snapshot', function () {
        $justification = ($this->register)(($this->interval)('07:30', '07:50'));
        ($this->update)($justification, ($this->interval)('07:30', '07:45'));
        ($this->update)($justification, ($this->interval)('07:30', '07:45', ['occurrence_date' => '2026-09-16']));
        ($this->cancel)($justification);

        expect(TimesheetSnapshot::query()->count())->toBe(0);
    });

    test('an existing competence that is not closed records no snapshot', function (string $state) {
        $factory = BenefitPeriod::factory()->forCompetence('2026-10');
        ($state === 'calculated' ? $factory->calculated() : $factory)->create();

        $justification = ($this->register)(($this->interval)('07:30', '07:50'));
        ($this->update)($justification, ($this->interval)('07:30', '07:45'));
        ($this->cancel)($justification);

        expect(TimesheetSnapshot::query()->count())->toBe(0);
    })->with(['open', 'calculated']);

    test('a resubmission and an edition without changes record no snapshot', function () {
        ($this->close)();
        $uuid = (string) Str::uuid();
        $justification = $this->service->register($this->employee, '2026-09-15', ['request_uuid' => $uuid, ...($this->interval)('07:30', '07:50')], $this->hr)['justification'];
        $count = TimesheetSnapshot::query()->count();

        $this->service->register($this->employee, '2026-09-15', ['request_uuid' => $uuid, ...($this->interval)('07:30', '07:50')], $this->hr);
        ($this->update)($justification, ($this->interval)('07:30', '07:50'));

        expect($count)->toBe(3)->and(TimesheetSnapshot::query()->count())->toBe(3);
    });
});

describe('closed competence', function () {
    test('a registration records the day before and after, linked to the operation, history and period', function () {
        $period = ($this->close)();
        $expectedBefore = $this->recorder->capture($this->employee, ['2026-09-15'])['2026-09-15'];

        $justification = ($this->register)(($this->interval)('07:30', '07:50'));

        [$before, $after] = ($this->snapshots)()->all();
        $history = PointJustificationHistory::query()->where('point_justification_id', $justification->id)->where('action', JustificationHistoryAction::Created)->sole();

        expect([$before->source, $after->source])->toBe([TimesheetSnapshotSource::BeforeChange, TimesheetSnapshotSource::AfterChange])
            ->and([$before->sequence, $after->sequence])->toBe([2, 3])
            ->and($before->operation_uuid)->not->toBeNull()->toBe($after->operation_uuid)
            ->and([$before->point_justification_history_id, $after->point_justification_history_id])->toBe([$history->id, $history->id])
            ->and([$before->benefit_period_id, $after->benefit_period_id])->toBe([$period->id, $period->id])
            ->and([$before->created_by, $before->created_by_name])->toBe([$this->hr->id, 'Analista RH'])
            ->and($before->result_hash)->toBe($expectedBefore['result_hash'])
            ->and($before->inputs_hash)->toBe($expectedBefore['inputs_hash'])
            ->and($before->fresh()->result['delay_minutes'])->toBe(15)
            ->and($before->fresh()->inputs['calculation']['justifications'])->toBe([])
            ->and($after->fresh()->result['delay_minutes'])->toBe(0)
            ->and($after->fresh()->inputs['references']['justifications'][0]['id'])->toBe($justification->id)
            ->and($after->fresh()->inputs['references']['justifications'][0]['last_history_id'])->toBe($history->id)
            ->and($this->recorder->divergence($after->fresh()))->toMatchArray(['inputs_changed' => false, 'result_changed' => false])
            ->and($before->fresh()->integrityProblems())->toBe([])
            ->and($after->fresh()->integrityProblems())->toBe([]);
    });

    test('an edition records the previous and the new result', function () {
        $justification = ($this->register)(($this->interval)('07:30', '07:50'));
        ($this->close)();

        ($this->update)($justification, ($this->interval)('07:30', '07:40'));

        [$before, $after] = ($this->snapshots)()->all();
        $history = PointJustificationHistory::query()->where('action', JustificationHistoryAction::Updated)->sole();

        expect(($this->snapshots)())->toHaveCount(2)
            ->and([$before->fresh()->result['allowed_delay_minutes'], $after->fresh()->result['allowed_delay_minutes']])->toBe([15, 10])
            ->and([$before->fresh()->result['delay_minutes'], $after->fresh()->result['delay_minutes']])->toBe([0, 5])
            ->and([$before->fresh()->inputs['references']['justifications'][0]['version'], $after->fresh()->inputs['references']['justifications'][0]['version']])->toBe([1, 2])
            ->and($after->point_justification_history_id)->toBe($history->id);
    });

    test('a cancellation records the day with and without the justification and the removed punch', function () {
        $justification = ($this->register)(($this->punch)('18:00', 'entry'), '2026-09-16');
        ($this->close)();

        ($this->cancel)($justification);

        [$before, $after] = ($this->snapshots)()->all();

        expect($before->date->toDateString())->toBe('2026-09-16')
            ->and($before->fresh()->result['punch_count'])->toBe(5)
            ->and($before->fresh()->result['has_manual_punch'])->toBeTrue()
            ->and($after->fresh()->result['punch_count'])->toBe(4)
            ->and($after->fresh()->result['has_manual_punch'])->toBeFalse()
            ->and($after->fresh()->inputs['calculation']['justifications'])->toBe([])
            ->and($after->justificationHistory->action)->toBe(JustificationHistoryAction::Canceled);
    });

    test('moving the date inside the closed competence records both dates in one operation', function () {
        $justification = ($this->register)(($this->interval)('07:30', '07:50'));
        ($this->close)();

        ($this->update)($justification, ($this->interval)('07:30', '07:50', ['occurrence_date' => '2026-09-16']));

        $snapshots = ($this->snapshots)();
        $byDate = $snapshots->groupBy(fn ($snapshot) => $snapshot->date->toDateString());

        expect($snapshots)->toHaveCount(4)
            ->and($snapshots->pluck('operation_uuid')->unique())->toHaveCount(1)
            ->and($byDate->keys()->sort()->values()->all())->toBe(['2026-09-15', '2026-09-16'])
            ->and($byDate['2026-09-15']->map(fn ($snapshot) => [$snapshot->source, $snapshot->fresh()->result['delay_minutes']])->all())->toBe([[TimesheetSnapshotSource::BeforeChange, 0], [TimesheetSnapshotSource::AfterChange, 15]])
            ->and($byDate['2026-09-16']->map(fn ($snapshot) => [$snapshot->source, $snapshot->fresh()->result['delay_minutes']])->all())->toBe([[TimesheetSnapshotSource::BeforeChange, 15], [TimesheetSnapshotSource::AfterChange, 0]]);
    });

    test('moving from an open to a closed competence records only the destination', function () {
        $justification = ($this->register)(($this->interval)('07:30', '07:50'));
        $period = ($this->close)('2026-11');

        ($this->update)($justification, ($this->interval)('07:30', '07:50', ['occurrence_date' => '2026-10-15']));

        $snapshots = ($this->snapshots)();

        expect($snapshots->map(fn ($snapshot) => [$snapshot->date->toDateString(), $snapshot->source, $snapshot->benefit_period_id])->all())->toBe([
            ['2026-10-15', TimesheetSnapshotSource::BeforeChange, $period->id],
            ['2026-10-15', TimesheetSnapshotSource::AfterChange, $period->id],
        ]);
    });

    test('moving from a closed to an open competence records only the origin', function () {
        $justification = ($this->register)(($this->interval)('07:30', '07:50'));
        $period = ($this->close)('2026-10');

        ($this->update)($justification, ($this->interval)('07:30', '07:50', ['occurrence_date' => '2026-10-15']));

        expect(($this->snapshots)()->map(fn ($snapshot) => [$snapshot->date->toDateString(), $snapshot->benefit_period_id])->all())
            ->toBe([['2026-09-15', $period->id], ['2026-09-15', $period->id]]);
    });

    test('a justification ending on the next day records both days, each with its own closed period', function () {
        $october = ($this->close)('2026-10');
        $november = ($this->close)('2026-11');

        ($this->register)(($this->interval)('22:00', '08:00', ['ends_next_day' => true]), '2026-09-30');

        $snapshots = ($this->snapshots)();

        expect($snapshots)->toHaveCount(4)
            ->and($snapshots->pluck('operation_uuid')->unique())->toHaveCount(1)
            ->and($snapshots->map(fn ($snapshot) => [$snapshot->date->toDateString(), $snapshot->source, $snapshot->benefit_period_id])->all())->toBe([
                ['2026-09-30', TimesheetSnapshotSource::BeforeChange, $october->id],
                ['2026-09-30', TimesheetSnapshotSource::AfterChange, $october->id],
                ['2026-10-01', TimesheetSnapshotSource::BeforeChange, $november->id],
                ['2026-10-01', TimesheetSnapshotSource::AfterChange, $november->id],
            ])
            ->and($snapshots[3]->fresh()->inputs['calculation']['justifications'][0]['occurrence_date'])->toBe('2026-09-30');
    });

    test('the next day in an open competence gets no snapshot', function () {
        ($this->close)('2026-10');

        ($this->register)(($this->interval)('22:00', '08:00', ['ends_next_day' => true]), '2026-09-30');

        expect(($this->snapshots)()->map(fn ($snapshot) => $snapshot->date->toDateString())->unique()->values()->all())->toBe(['2026-09-30']);
    });
});

describe('authorization', function () {
    test('without the closed period permission nothing is changed nor recorded', function (string $operation) {
        $justification = ($this->register)(($this->interval)('07:30', '07:50'));
        ($this->close)();
        $restricted = User::factory()->create();
        $restricted->grantPermission(Permission::Hr);
        $before = ($this->tables)();

        $call = match ($operation) {
            'register' => fn () => ($this->register)(($this->interval)('13:00', '13:30'), '2026-09-16', $restricted->fresh()),
            'update' => fn () => ($this->update)($justification, ($this->interval)('07:30', '07:40'), $restricted->fresh()),
            'cancel' => fn () => ($this->cancel)($justification, $restricted->fresh()),
        };

        expect($call)->toThrow(ValidationException::class)
            ->and(($this->tables)())->toBe($before);
    })->with(['register', 'update', 'cancel']);

    test('without hr nothing is changed nor recorded', function () {
        $justification = ($this->register)(($this->interval)('07:30', '07:50'));
        ($this->close)();
        $before = ($this->tables)();

        expect(fn () => ($this->update)($justification, ($this->interval)('07:30', '07:40'), User::factory()->create()))->toThrow(AuthorizationException::class)
            ->and(($this->tables)())->toBe($before);
    });
});

describe('atomicity', function () {
    test('a failure in the change rolls everything back', function (string $operation) {
        $justification = ($this->register)(($this->punch)('18:00', 'entry'), '2026-09-16');
        ($this->close)();
        $before = ($this->tables)();
        PointJustification::updating(fn () => throw new RuntimeException('Falha simulada na alteração.'));

        $call = $operation === 'update'
            ? fn () => ($this->update)($justification, ($this->punch)('18:10', 'entry'))
            : fn () => ($this->cancel)($justification);

        expect($call)->toThrow(RuntimeException::class, 'Falha simulada na alteração.')
            ->and(($this->tables)())->toBe($before);
    })->with(['update', 'cancel']);

    test('a failure while recording any snapshot rolls everything back', function (string $operation, int $failingSnapshot) {
        $justification = ($this->register)(($this->punch)('18:00', 'entry'), '2026-09-16');
        ($this->close)();
        $before = ($this->tables)();
        $count = 0;
        TimesheetSnapshot::creating(function () use (&$count, $failingSnapshot) {
            if (++$count === $failingSnapshot) {
                throw new RuntimeException('Falha simulada no snapshot.');
            }
        });

        $call = match ($operation) {
            'register' => fn () => ($this->register)(($this->punch)('18:00', 'entry'), '2026-09-15'),
            'update' => fn () => ($this->update)($justification, ($this->punch)('18:10', 'entry', ['occurrence_date' => '2026-09-15'])),
            'cancel' => fn () => ($this->cancel)($justification),
        };

        expect($call)->toThrow(RuntimeException::class, 'Falha simulada no snapshot.')
            ->and(($this->tables)())->toBe($before);
    })->with([
        'register, late baseline' => ['register', 1],
        'register, before' => ['register', 2],
        'register, after' => ['register', 3],
        'update, first date late baseline' => ['update', 1],
        'update, last date after' => ['update', 6],
        'cancel, late baseline' => ['cancel', 1],
        'cancel, after' => ['cancel', 3],
    ]);
});

describe('pis', function () {
    test('changing the PIS is detected even when the selected punches are identical', function () {
        ($this->close)();
        ($this->register)(($this->interval)('07:30', '07:50'));
        $after = ($this->snapshots)()->last();

        $newPis = '99999999999';
        foreach (Point::query()->where('pis', $this->employee->pis)->whereDate('date', '2026-09-15')->get() as $point) {
            Point::factory()->on('2026-09-15')->create(['pis' => $newPis, 'time' => $point->getRawOriginal('time'), 'type' => $point->type]);
        }
        $this->employee->forceFill(['pis' => $newPis])->save();

        $current = $this->recorder->capture($this->employee->fresh(), ['2026-09-15'])['2026-09-15'];

        expect(array_column($current['inputs']['calculation']['points'], 'time'))->toBe(array_column($after->fresh()->inputs['calculation']['points'], 'time'))
            ->and($this->recorder->divergence($after->fresh()))->toMatchArray(['inputs_changed' => true])
            ->and($current['inputs']['calculation']['employee_pis'])->toBe($newPis);
    });
});
