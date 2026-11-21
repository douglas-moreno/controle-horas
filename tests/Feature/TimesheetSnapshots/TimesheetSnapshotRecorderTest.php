<?php

use App\Enums\Permission;
use App\Enums\TimesheetSnapshotSource;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Point;
use App\Models\PointJustification;
use App\Models\PointJustificationHistory;
use App\Models\PointJustificationReason;
use App\Models\TimesheetSnapshot;
use App\Models\User;
use App\Services\PointJustificationService;
use App\Services\TimesheetMirror;
use App\Services\TimesheetSnapshotRecorder;
use Carbon\CarbonImmutable;
use Database\Seeders\PointJustificationReasonSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * F6.2a: estrutura dos snapshots do espelho. 2026-09-15 é terça (jornada 07:30–17:30).
 */
beforeEach(function () {
    $this->seed(PointJustificationReasonSeeder::class);
    Holiday::flushCachedHolidays();

    $this->employee = Employee::factory()->create(['name' => 'Ana Souza']);
    $this->other = Employee::factory()->create(['name' => 'Bruno Lima']);
    $this->hr = User::factory()->create(['name' => 'Analista RH']);
    $this->hr->grantPermission(Permission::Hr);
    $this->hr->refresh();

    $this->recorder = app(TimesheetSnapshotRecorder::class);
    $this->service = app(PointJustificationService::class);

    $this->punches = function (string $date, array $times, ?Employee $employee = null): void {
        foreach ($times as $time) {
            Point::factory()->forEmployee($employee ?? $this->employee)->on($date)->create(['time' => $time.':00']);
        }
    };
    ($this->punches)('2026-09-15', ['07:50', '12:00', '13:00', '17:30']);

    $this->reason = fn (string $code) => PointJustificationReason::query()->where('code', $code)->sole();
    $this->justify = fn (string $start, string $end, string $date = '2026-09-15', array $extra = []) => $this->service->register($this->employee, $date, [
        'request_uuid' => (string) Str::uuid(),
        'reason_id' => ($this->reason)('justified_delay')->id,
        'treatment' => 'full_allowance',
        'start_time' => $start,
        'end_time' => $end,
        ...$extra,
    ], $this->hr)['justification'];

    $this->record = fn (TimesheetSnapshotSource $source = TimesheetSnapshotSource::ClosureBaseline, array $dates = ['2026-09-15'], array $context = [], ?Employee $employee = null) => $this->recorder
        ->record($employee ?? $this->employee, $dates, $source, $this->hr, $context);

    $this->inputsHash = fn (string $date = '2026-09-15') => $this->recorder->capture($this->employee->fresh(), [$date])[$date]['inputs_hash'];
});

describe('content', function () {
    test('a snapshot stores the day exactly as the mirror presents it, with inputs and hashes', function () {
        $justification = ($this->justify)('07:30', '07:50');

        $snapshot = ($this->record)()->sole()->fresh();
        $mirrorDay = collect(app(TimesheetMirror::class)->forEmployee($this->employee, CarbonImmutable::parse('2026-08-26'), CarbonImmutable::parse('2026-09-25'))['days'])->firstWhere('date', '2026-09-15');

        expect($snapshot->result)->toEqual(json_decode(json_encode($mirrorDay), true))
            ->and(TimesheetSnapshotRecorder::hash($snapshot->result))->toBe(TimesheetSnapshotRecorder::hash(json_decode(json_encode($mirrorDay), true)))
            ->and($snapshot->result)->toMatchArray([
                'date' => '2026-09-15',
                'status' => 'regular',
                'day_type' => 'business_day',
                'entrada' => '07:50',
                'saida' => '17:30',
                'late_entry_minutes' => 15,
                'delay_before_allowance_minutes' => 15,
                'allowed_delay_minutes' => 15,
                'delay_minutes' => 0,
            ])
            ->and($snapshot->result_hash)->toBe(TimesheetSnapshotRecorder::hash($snapshot->result))
            ->and($snapshot->inputs_hash)->toBe(TimesheetSnapshotRecorder::hash($snapshot->inputs['calculation']))
            ->and($snapshot->inputs['calculation'])->toMatchArray([
                'date' => '2026-09-15',
                'day_of_week_iso' => 2,
                'day_type' => 'business_day',
                'holiday' => null,
                'entry_tolerance_minutes' => 5,
                'alternative_rule' => ['enabled' => false],
            ])
            ->and($snapshot->inputs['calculation']['schedule'])->toEqual(['entry' => '07:30', 'break_start' => '12:00', 'break_end' => '13:00', 'exit' => '17:30'])
            ->and(array_column($snapshot->inputs['calculation']['points'], 'time'))->toBe(['07:50:00', '12:00:00', '13:00:00', '17:30:00'])
            ->and($snapshot->inputs['calculation']['justifications'][0])->toMatchArray(['start_time' => '07:30:00', 'end_time' => '07:50:00', 'treatment' => 'full_allowance', 'reason_name' => 'Atraso justificado'])
            ->and($snapshot->inputs['references']['justifications'][0])->toEqual([
                'id' => $justification->id,
                'version' => 1,
                'last_history_id' => PointJustificationHistory::query()->max('id'),
                'reason_id' => ($this->reason)('justified_delay')->id,
                'reason_code' => 'justified_delay',
            ])
            ->and($snapshot->inputs['references']['employee'])->toEqual(['id' => $this->employee->id, 'name' => 'Ana Souza', 'pis' => (string) $this->employee->pis])
            ->and($snapshot)->toMatchArray([
                'employee_id' => $this->employee->id,
                'sequence' => 1,
                'schema_version' => 1,
                'rule_version' => TimesheetSnapshotRecorder::RULE_VERSION,
                'rules_hash' => TimesheetSnapshotRecorder::rulesHash(),
                'created_by' => $this->hr->id,
                'created_by_name' => 'Analista RH',
            ])
            ->and($snapshot->source)->toBe(TimesheetSnapshotSource::ClosureBaseline)
            ->and($snapshot->date->toDateString())->toBe('2026-09-15')
            ->and($snapshot->competence->toDateString())->toBe('2026-10-01')
            ->and($snapshot->created_at)->not->toBeNull();
    });

    test('days without punches, weekends and holidays are recorded with their inputs', function () {
        Holiday::factory()->create(['date' => '2026-09-16', 'description' => 'Feriado municipal']);

        $snapshots = ($this->record)(TimesheetSnapshotSource::ClosureBaseline, ['2026-09-19', '2026-09-16', '2026-09-17'])->keyBy(fn ($snapshot) => $snapshot->date->toDateString());

        expect($snapshots->keys()->all())->toBe(['2026-09-16', '2026-09-17', '2026-09-19'])
            ->and($snapshots['2026-09-16']->inputs['calculation']['holiday'])->toBe('Feriado municipal')
            ->and($snapshots['2026-09-16']->result['day_type'])->toBe('holiday')
            ->and($snapshots['2026-09-17']->result['status'])->toBe('no_punches')
            ->and($snapshots['2026-09-17']->result['needs_review'])->toBeTrue()
            ->and($snapshots['2026-09-19']->inputs['calculation']['schedule'])->toBeNull()
            ->and($snapshots['2026-09-19']->result['day_type'])->toBe('saturday');
    });

    test('a justification of the previous day crossing midnight is an input of the next day', function () {
        ($this->justify)('22:00', '08:00', '2026-09-14', ['ends_next_day' => true]);

        $inputs = $this->recorder->capture($this->employee, ['2026-09-15'])['2026-09-15']['inputs']['calculation'];

        expect($inputs['justifications'])->toHaveCount(1)
            ->and($inputs['justifications'][0])->toMatchArray(['occurrence_date' => '2026-09-14', 'ends_next_day' => true]);
    });

    test('the alternative rule is stored with its parameters only when enabled', function (bool $enabled) {
        config(['timesheet.alternative_rule.enabled' => $enabled]);

        $snapshot = ($this->record)()->sole()->fresh();

        expect(array_key_exists('alternative', $snapshot->result))->toBe($enabled)
            ->and($snapshot->inputs['calculation']['alternative_rule']['enabled'])->toBe($enabled)
            ->and(array_key_exists('threshold_minutes', $snapshot->inputs['calculation']['alternative_rule']))->toBe($enabled);

        if ($enabled) {
            expect($snapshot->result['alternative'])->toHaveKeys(['status', 'overtime_minutes', 'delay_minutes', 'considered_times'])
                ->and($snapshot->inputs['calculation']['alternative_rule'])->toMatchArray(['threshold_minutes' => 5, 'night_from' => '22:00', 'max_span_minutes' => 840]);
        }
    })->with(['enabled' => true, 'disabled' => false]);

    test('capturing writes nothing', function () {
        ($this->justify)('07:30', '07:50');
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->recorder->capture($this->employee, ['2026-09-14', '2026-09-15']);

        expect($writes)->toBe([]);
    });
});

describe('hash', function () {
    test('the canonical hash ignores key order but not list order', function () {
        expect(TimesheetSnapshotRecorder::hash(['b' => 1, 'a' => ['y' => true, 'x' => null]]))
            ->toBe(TimesheetSnapshotRecorder::hash(['a' => ['x' => null, 'y' => true], 'b' => 1]))
            ->and(TimesheetSnapshotRecorder::hash(['list' => [1, 2]]))->not->toBe(TimesheetSnapshotRecorder::hash(['list' => [2, 1]]))
            ->and(TimesheetSnapshotRecorder::hash(['value' => 1]))->not->toBe(TimesheetSnapshotRecorder::hash(['value' => '1']))
            ->and(TimesheetSnapshotRecorder::hash(['value' => 'ç']))->toMatch('/^[0-9a-f]{64}$/');
    });

    test('equal inputs give the same hash, also when a punch is recreated with another id', function () {
        $first = ($this->inputsHash)();

        expect(($this->inputsHash)())->toBe($first);

        $point = Point::query()->where('time', '12:00:00')->sole();
        DB::table('points')->where('id', $point->id)->delete();
        ($this->punches)('2026-09-15', ['12:00']);

        expect(($this->inputsHash)())->toBe($first);
    });

    test('the hash changes when a relevant input changes', function (Closure $change) {
        $before = ($this->inputsHash)();

        $change->call($this);
        Holiday::flushCachedHolidays();

        expect(($this->inputsHash)())->not->toBe($before);
    })->with([
        'imported punch inserted by the AFD routines' => [fn () => DB::table('points')->insert(['pis' => $this->employee->pis, 'date' => '2026-09-15', 'time' => '18:00:00', 'type' => 'importado', 'created_at' => now(), 'updated_at' => now()])],
        'imported punch removed by the AFD routines' => [fn () => DB::table('points')->where('time', '17:30:00')->delete()],
        'manual punch' => [fn () => Point::factory()->manual()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => '18:00:00'])],
        'justification registered' => [fn () => ($this->justify)('07:30', '07:50')],
        'holiday' => [fn () => Holiday::factory()->create(['date' => '2026-09-15', 'description' => 'Feriado'])],
        'schedule' => [fn () => config(['timesheet.schedule.2.entry' => '08:00'])],
        'tolerance' => [fn () => config(['timesheet.entry_tolerance_minutes' => 10])],
        'alternative rule' => [fn () => config(['timesheet.alternative_rule.enabled' => true])],
    ]);

    test('editing, canceling and renaming the reason of a justification change the hash', function () {
        $justification = ($this->justify)('07:30', '07:50');
        $hashes = [($this->inputsHash)()];

        $this->service->update($justification, ['reason_id' => ($this->reason)('justified_delay')->id, 'treatment' => 'full_allowance', 'start_time' => '07:30', 'end_time' => '07:45'], 1, $this->hr);
        $hashes[] = ($this->inputsHash)();

        ($this->reason)('justified_delay')->forceFill(['name' => 'Atraso abonado'])->save();
        $hashes[] = ($this->inputsHash)();

        $this->service->cancel($justification->fresh(), 'Engano', 2, $this->hr);
        $hashes[] = ($this->inputsHash)();

        expect(array_unique($hashes))->toHaveCount(4);
    });

    test('changes on another day or another employee do not change the hash', function () {
        $before = ($this->inputsHash)();

        ($this->punches)('2026-09-16', ['07:30']);
        ($this->punches)('2026-09-15', ['18:00'], $this->other);
        Holiday::factory()->create(['date' => '2026-09-16', 'description' => 'Outro dia']);
        Holiday::flushCachedHolidays();

        expect(($this->inputsHash)())->toBe($before);
    });

    test('the rules hash covers the calculation source code', function () {
        $expected = hash('sha256', collect(TimesheetSnapshotRecorder::RULE_SOURCES)
            ->map(fn (string $class) => $class.':'.hash_file('sha256', (new ReflectionClass($class))->getFileName()))
            ->implode("\n"));

        expect(TimesheetSnapshotRecorder::rulesHash())->toBe($expected)
            ->and(TimesheetSnapshotRecorder::RULE_SOURCES)->toContain(TimesheetMirror::class);
    });
});

describe('preservation and divergence', function () {
    test('the stored result is kept after the day changes and the divergence is detected', function () {
        $snapshot = ($this->record)()->sole();
        $stored = $snapshot->fresh()->result;

        expect($this->recorder->divergence($snapshot->fresh()))->toMatchArray(['inputs_changed' => false, 'rules_changed' => false, 'result_changed' => false]);

        DB::table('points')->where('time', '07:50:00')->update(['time' => '08:20:00']);

        $fresh = $snapshot->fresh();

        expect($fresh->result)->toBe($stored)
            ->and($fresh->result['late_entry_minutes'])->toBe(15)
            ->and($this->recorder->capture($this->employee, ['2026-09-15'])['2026-09-15']['result']['late_entry_minutes'])->toBe(45)
            ->and($this->recorder->divergence($fresh))->toMatchArray(['inputs_changed' => true, 'rules_changed' => false, 'result_changed' => true]);
    });

    test('a day captured before a change keeps the previous result when persisted afterwards', function () {
        $justification = ($this->justify)('07:30', '07:50');
        $before = $this->recorder->capture($this->employee, ['2026-09-15']);
        $operation = (string) Str::uuid();

        $this->service->cancel($justification, 'Engano', 1, $this->hr);
        $history = PointJustificationHistory::query()->latest('id')->first();

        $snapshots = DB::transaction(fn () => [
            $this->recorder->persist($this->employee, $before, TimesheetSnapshotSource::BeforeChange, $this->hr, ['operation_uuid' => $operation, 'point_justification_history_id' => $history->id])->sole(),
            $this->recorder->record($this->employee, ['2026-09-15'], TimesheetSnapshotSource::AfterChange, $this->hr, ['operation_uuid' => $operation, 'point_justification_history_id' => $history->id])->sole(),
        ]);

        expect($snapshots[0]->fresh()->result['delay_minutes'])->toBe(0)
            ->and($snapshots[1]->fresh()->result['delay_minutes'])->toBe(15)
            ->and([$snapshots[0]->sequence, $snapshots[1]->sequence])->toBe([1, 2])
            ->and(TimesheetSnapshot::query()->where('operation_uuid', $operation)->count())->toBe(2)
            ->and($snapshots[1]->justificationHistory->is($history))->toBeTrue();
    });
});

describe('immutability', function () {
    test('the model refuses updates and deletes', function () {
        $snapshot = ($this->record)()->sole();

        expect(fn () => $snapshot->update(['reason' => 'alterado']))->toThrow(LogicException::class)
            ->and(fn () => $snapshot->delete())->toThrow(LogicException::class)
            ->and(fn () => TimesheetSnapshot::query()->whereKey($snapshot->id)->first()->forceFill(['result' => []])->save())->toThrow(LogicException::class);
    });

    test('mass writes through the builder are refused', function (Closure $write) {
        $snapshot = ($this->record)()->sole();
        $row = (array) DB::table('timesheet_snapshots')->find($snapshot->id);

        expect(fn () => $write($snapshot))->toThrow(LogicException::class, 'append-only')
            ->and((array) DB::table('timesheet_snapshots')->find($snapshot->id))->toBe($row);
    })->with([
        'update' => [fn (TimesheetSnapshot $snapshot) => TimesheetSnapshot::query()->whereKey($snapshot->id)->update(['reason' => 'x'])],
        'delete' => [fn (TimesheetSnapshot $snapshot) => TimesheetSnapshot::query()->whereKey($snapshot->id)->delete()],
        'force delete' => [fn (TimesheetSnapshot $snapshot) => TimesheetSnapshot::query()->whereKey($snapshot->id)->forceDelete()],
        'upsert' => [fn (TimesheetSnapshot $snapshot) => TimesheetSnapshot::query()->upsert([['id' => $snapshot->id, 'reason' => 'x']], ['id'], ['reason'])],
        'increment' => [fn (TimesheetSnapshot $snapshot) => TimesheetSnapshot::query()->whereKey($snapshot->id)->increment('sequence')],
        'decrement' => [fn (TimesheetSnapshot $snapshot) => TimesheetSnapshot::query()->whereKey($snapshot->id)->decrement('sequence')],
        'relation' => [fn (TimesheetSnapshot $snapshot) => TimesheetSnapshot::query()->where('employee_id', $snapshot->employee_id)->update(['reason' => 'x'])],
    ]);

    test('a change made directly in the database is detected by the record hashes', function (array $change, array $problems) {
        $snapshot = ($this->record)()->sole();

        expect($snapshot->fresh()->integrityProblems())->toBe([])
            ->and($snapshot->fresh()->record_hash)->toBe($snapshot->fresh()->computedRecordHash());

        DB::table('timesheet_snapshots')->where('id', $snapshot->id)->update($change);

        expect($snapshot->fresh()->integrityProblems())->toBe($problems);
    })->with([
        'source' => [['source' => 'closure_baseline_x'], ['record_hash']],
        'sequence' => [['sequence' => 9], ['record_hash']],
        'stored result' => [['result' => '{"delay_minutes": 0}'], ['result_hash']],
        'stored inputs' => [['inputs' => '{"calculation": {}, "references": {}}'], ['inputs_hash']],
        'result and its hash' => [['result' => '{}', 'result_hash' => str_repeat('a', 64)], ['record_hash', 'result_hash']],
    ]);

    test('a failure while recording several days rolls everything back', function () {
        $count = 0;
        TimesheetSnapshot::creating(function () use (&$count) {
            if (++$count === 2) {
                throw new RuntimeException('Falha simulada na gravação do snapshot.');
            }
        });

        expect(fn () => ($this->record)(TimesheetSnapshotSource::ClosureBaseline, ['2026-09-15', '2026-09-16']))->toThrow(RuntimeException::class, 'Falha simulada na gravação do snapshot.')
            ->and(TimesheetSnapshot::query()->count())->toBe(0);
    });
});

describe('relationships', function () {
    test('the snapshot is linked to employee, period, justification history and user', function () {
        $justification = ($this->justify)('07:30', '07:50');
        $history = PointJustificationHistory::query()->where('point_justification_id', $justification->id)->first();
        $period = BenefitPeriod::factory()->forCompetence('2026-10')->closed()->create();

        $snapshot = ($this->record)(TimesheetSnapshotSource::AfterChange, ['2026-09-15'], ['operation_uuid' => (string) Str::uuid(), 'point_justification_history_id' => $history->id, 'benefit_period_id' => $period->id, 'reason' => 'Teste'])->sole();

        expect($snapshot->employee->is($this->employee))->toBeTrue()
            ->and($snapshot->benefitPeriod->is($period))->toBeTrue()
            ->and($snapshot->justificationHistory->is($history))->toBeTrue()
            ->and($snapshot->creator->is($this->hr))->toBeTrue()
            ->and($snapshot->reason)->toBe('Teste');
    });

    test('a history of another employee is refused', function () {
        $foreign = $this->service->register($this->other, '2026-09-15', ['request_uuid' => (string) Str::uuid(), 'reason_id' => ($this->reason)('justified_delay')->id, 'treatment' => 'full_allowance', 'start_time' => '07:30', 'end_time' => '07:50'], $this->hr)['justification'];
        $history = PointJustificationHistory::query()->where('point_justification_id', $foreign->id)->first();

        expect(fn () => ($this->record)(TimesheetSnapshotSource::AfterChange, ['2026-09-15'], ['operation_uuid' => (string) Str::uuid(), 'point_justification_history_id' => $history->id]))
            ->toThrow(DomainException::class, 'não pertence')
            ->and(TimesheetSnapshot::query()->count())->toBe(0);
    });

    test('a capture of another employee cannot be persisted', function () {
        $captured = $this->recorder->capture($this->other, ['2026-09-15']);

        expect(fn () => $this->recorder->persist($this->employee, $captured, TimesheetSnapshotSource::ClosureBaseline, $this->hr))->toThrow(DomainException::class)
            ->and(TimesheetSnapshot::query()->count())->toBe(0);
    });

    test('the referenced employee and period cannot be deleted; a deleted user keeps the copied name', function () {
        $period = BenefitPeriod::factory()->forCompetence('2026-10')->closed()->create();
        $user = User::factory()->create(['name' => 'Usuária Removida']);
        $snapshot = $this->recorder->record($this->employee, ['2026-09-15'], TimesheetSnapshotSource::ClosureBaseline, $user, ['benefit_period_id' => $period->id])->sole();

        expect(fn () => DB::table('employees')->where('id', $this->employee->id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('benefit_periods')->where('id', $period->id)->delete())->toThrow(QueryException::class);

        $user->delete();

        expect($snapshot->fresh()->created_by)->toBeNull()
            ->and($snapshot->fresh()->created_by_name)->toBe('Usuária Removida')
            ->and($snapshot->fresh()->integrityProblems())->toBe([]);
    });

    test('without a user the snapshot is recorded as the system', function () {
        $snapshot = $this->recorder->record($this->employee, ['2026-09-15'], TimesheetSnapshotSource::ClosureBaseline, null)->sole();

        expect($snapshot->created_by)->toBeNull()->and($snapshot->created_by_name)->toBe('Sistema');
    });
});

describe('versioning', function () {
    test('versions grow per employee and day', function () {
        ($this->record)(TimesheetSnapshotSource::ClosureBaseline, ['2026-09-15', '2026-09-16']);
        ($this->record)(TimesheetSnapshotSource::ClosureBaseline, ['2026-09-15']);
        ($this->record)(TimesheetSnapshotSource::ClosureBaseline, ['2026-09-15'], [], $this->other);

        $versions = TimesheetSnapshot::query()->orderBy('id')->get()->map(fn ($snapshot) => [$snapshot->employee_id, $snapshot->date->toDateString(), $snapshot->sequence])->all();

        expect($versions)->toBe([
            [$this->employee->id, '2026-09-15', 1],
            [$this->employee->id, '2026-09-16', 1],
            [$this->employee->id, '2026-09-15', 2],
            [$this->other->id, '2026-09-15', 1],
        ]);
    });

    test('the same version cannot be stored twice', function () {
        $snapshot = ($this->record)()->sole();
        $row = collect((array) DB::table('timesheet_snapshots')->find($snapshot->id))->except('id')->all();

        expect(fn () => DB::table('timesheet_snapshots')->insert($row))->toThrow(QueryException::class, 'timesheet_snapshots_version_unique');
    });

    test('a late baseline is recorded only where no baseline exists and is never the closure', function () {
        ($this->record)(TimesheetSnapshotSource::ClosureBaseline, ['2026-09-15']);
        ($this->record)(TimesheetSnapshotSource::BeforeChange, ['2026-09-16'], ['operation_uuid' => (string) Str::uuid()]);

        $late = ($this->record)(TimesheetSnapshotSource::LateBaseline, ['2026-09-15', '2026-09-16', '2026-09-17']);
        $again = ($this->record)(TimesheetSnapshotSource::LateBaseline, ['2026-09-16', '2026-09-17']);

        expect($late->map(fn ($snapshot) => [$snapshot->date->toDateString(), $snapshot->sequence])->all())->toBe([['2026-09-16', 2], ['2026-09-17', 1]])
            ->and($again)->toBeEmpty()
            ->and($late->every(fn ($snapshot) => $snapshot->source === TimesheetSnapshotSource::LateBaseline))->toBeTrue()
            ->and(TimesheetSnapshotSource::LateBaseline->label())->toContain('não é o fechamento original');
    });

    test('a recalculation is recorded only when inputs, rules or result changed', function () {
        ($this->record)(TimesheetSnapshotSource::ClosureBaseline);

        expect(($this->record)(TimesheetSnapshotSource::Recalculation))->toBeEmpty();

        ($this->punches)('2026-09-15', ['18:30']);

        $recalculated = ($this->record)(TimesheetSnapshotSource::Recalculation)->sole();

        expect($recalculated->sequence)->toBe(2)
            ->and(($this->record)(TimesheetSnapshotSource::Recalculation))->toBeEmpty();
    });

    test('closure baselines are recorded again after a reopening, as a new version', function () {
        $first = ($this->record)(TimesheetSnapshotSource::ClosureBaseline)->sole();
        $second = ($this->record)(TimesheetSnapshotSource::ClosureBaseline)->sole();

        expect([$first->sequence, $second->sequence])->toBe([1, 2])
            ->and($second->inputs_hash)->toBe($first->inputs_hash)
            ->and(TimesheetSnapshot::query()->count())->toBe(2);
    });

    test('before and after snapshots require the operation identifier', function (TimesheetSnapshotSource $source) {
        expect(fn () => ($this->record)($source))->toThrow(DomainException::class, 'identificador da operação')
            ->and(TimesheetSnapshot::query()->count())->toBe(0);
    })->with([TimesheetSnapshotSource::BeforeChange, TimesheetSnapshotSource::AfterChange]);

    test('a non existing justification of the day is not an input', function () {
        PointJustification::factory()->for($this->employee)->canceled()->create(['occurrence_date' => '2026-09-15', 'reason_id' => ($this->reason)('medical_certificate')->id]);

        expect($this->recorder->capture($this->employee, ['2026-09-15'])['2026-09-15']['inputs']['calculation']['justifications'])->toBe([]);
    });
});
