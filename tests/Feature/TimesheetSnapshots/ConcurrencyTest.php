<?php

use App\Enums\BenefitPeriodStatus;
use App\Enums\Permission;
use App\Enums\TimesheetSnapshotSource;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\Point;
use App\Models\PointJustificationReason;
use App\Models\TimesheetSnapshot;
use App\Models\User;
use App\Services\BenefitPeriodWorkflow;
use App\Services\PointJustificationService;
use Database\Seeders\PointJustificationReasonSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/*
 * F6.2d: concorrência real entre fechamento e justificativas, em processos PHP separados
 * (tests/Support/timesheet-concurrency.php), cada um com a sua conexão ao MySQL de testes.
 * Os dados precisam estar gravados para o outro processo vê-los, por isso este arquivo usa
 * DatabaseTruncation, como PointJustificationConcurrencyTest. Um processo para dentro da
 * transação (bloqueios mantidos) e sinaliza; o outro só então começa.
 */
uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->database = DB::connection()->getDatabaseName();
    expect(TestCase::isTestDatabase($this->database))->toBeTrue("Banco de teste não autorizado: {$this->database}");

    $this->seed(PointJustificationReasonSeeder::class);

    $this->user = User::factory()->create(['name' => 'Analista RH']);
    $this->user->grantPermission(Permission::Hr);
    $this->user->grantPermission(Permission::HrClosedPeriod);
    $this->actingAs($this->user->refresh());

    $this->ana = Employee::factory()->create(['name' => 'Ana Souza']);
    foreach (['07:30', '12:00', '13:00', '17:30'] as $time) {
        Point::factory()->forEmployee($this->ana)->on('2026-09-15')->create(['time' => $time.':00']);
    }

    $this->period = BenefitPeriod::factory()->forCompetence('2026-10')->create();
    app(BenefitPeriodWorkflow::class)->calculate($this->period);

    /*
     * Inicia o worker; com `pause_on`, espera ele sinalizar que está parado dentro da
     * transação (bloqueios mantidos) antes de devolver.
     */
    $this->start = function (string $scenario, array $arguments): array {
        $ready = null;

        if (isset($arguments['pause_on'])) {
            $ready = sys_get_temp_dir().'/timesheet-concurrency-'.Str::uuid().'.ready';
            $arguments += ['ready_file' => $ready, 'pause_seconds' => 3];
        }

        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/timesheet-concurrency.php'), $scenario, json_encode(['user_id' => $this->user->id, ...$arguments])],
            base_path(),
            ['APP_ENV' => 'testing', 'DB_DATABASE' => $this->database],
            timeout: 90,
        );
        $process->start();

        if ($ready !== null) {
            $deadline = microtime(true) + 30;

            while (! is_file($ready) && microtime(true) < $deadline && $process->isRunning()) {
                usleep(50_000);
            }

            expect(is_file($ready))->toBeTrue($process->getErrorOutput());
            unlink($ready);
        }

        return ['process' => $process];
    };

    $this->finish = function (array $worker): array {
        $process = $worker['process'];
        $process->wait();

        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
        $result = json_decode((string) end($lines), true);

        expect($result)->toBeArray($process->getOutput().$process->getErrorOutput());

        return $result;
    };

    $this->sequences = fn (string $date) => TimesheetSnapshot::query()->where('employee_id', $this->ana->id)->where('date', $date)
        ->orderBy('sequence')->get()->map(fn (TimesheetSnapshot $snapshot) => [$snapshot->sequence, $snapshot->source])->all();
});

/**
 * Limpa também depois de cada teste: os arquivos com RefreshDatabase executados em
 * seguida no mesmo processo não truncam as tabelas.
 */
afterEach(function () {
    expect(TestCase::isTestDatabase(DB::connection()->getDatabaseName()))->toBeTrue();

    Schema::withoutForeignKeyConstraints(fn () => collect(Schema::getTableListing(schemaQualified: false))
        ->reject(fn (string $table) => $table === 'migrations')
        ->each(fn (string $table) => DB::table($table)->truncate()));
});

test('a justification waiting for a closing in progress sees the closure baseline and records its versions after it', function () {
    $close = ($this->start)('close', ['period_id' => $this->period->id, 'pause_on' => 'snapshot']);
    $justify = ($this->start)('justify', ['employee_id' => $this->ana->id, 'date' => '2026-09-15', 'start' => '07:30', 'end' => '07:40']);

    $closed = ($this->finish)($close);
    $justified = ($this->finish)($justify);

    expect($closed)->toMatchArray(['ok' => true])
        ->and($justified)->toMatchArray(['ok' => true, 'error' => null])
        ->and($justified['finished'])->toBeGreaterThan($closed['finished'] - 0.5)
        ->and($this->period->fresh()->status)->toBe(BenefitPeriodStatus::Closed)
        ->and(($this->sequences)('2026-09-15'))->toBe([
            [1, TimesheetSnapshotSource::ClosureBaseline],
            [2, TimesheetSnapshotSource::BeforeChange],
            [3, TimesheetSnapshotSource::AfterChange],
        ])
        ->and(TimesheetSnapshot::query()->where('source', TimesheetSnapshotSource::LateBaseline)->count())->toBe(0);
});

test('a closing waiting for a justification in progress includes the justification in the baseline', function () {
    $justify = ($this->start)('justify', ['employee_id' => $this->ana->id, 'date' => '2026-09-15', 'start' => '07:30', 'end' => '07:40', 'pause_on' => 'justification']);
    $close = ($this->start)('close', ['period_id' => $this->period->id]);

    $justified = ($this->finish)($justify);
    $closed = ($this->finish)($close);
    $baseline = TimesheetSnapshot::query()->where('employee_id', $this->ana->id)->where('date', '2026-09-15')->sole();

    expect($justified)->toMatchArray(['ok' => true])
        ->and($closed)->toMatchArray(['ok' => true, 'error' => null])
        ->and($closed['finished'])->toBeGreaterThan($justified['finished'])
        ->and($baseline->source)->toBe(TimesheetSnapshotSource::ClosureBaseline)
        ->and($baseline->inputs['calculation']['justifications'])->toHaveCount(1)
        ->and($baseline->result['justification_notes'])->toHaveCount(1);
});

test('a justification in the previous closed competence waits for the closing of the same employee and nothing deadlocks', function () {
    BenefitPeriod::factory()->forCompetence('2026-09')->closed()->create();

    $close = ($this->start)('close', ['period_id' => $this->period->id, 'pause_on' => 'snapshot']);
    $justify = ($this->start)('justify', ['employee_id' => $this->ana->id, 'date' => '2026-08-31', 'start' => '07:30', 'end' => '07:40']);

    $justified = ($this->finish)($justify);
    $closed = ($this->finish)($close);

    expect($justified)->toMatchArray(['ok' => true, 'error' => null])
        ->and($closed)->toMatchArray(['ok' => true, 'error' => null])
        ->and($justified['finished'])->toBeGreaterThan($closed['finished'])
        ->and(($this->sequences)('2026-08-31'))->toBe([
            [1, TimesheetSnapshotSource::LateBaseline],
            [2, TimesheetSnapshotSource::BeforeChange],
            [3, TimesheetSnapshotSource::AfterChange],
        ])
        ->and(($this->sequences)('2026-09-01'))->toBe([[1, TimesheetSnapshotSource::ClosureBaseline]]);
});

test('two justifications of the same employee in a closed day are serialized with contiguous versions', function () {
    app(BenefitPeriodWorkflow::class)->close($this->period);

    $first = ($this->start)('justify', ['employee_id' => $this->ana->id, 'date' => '2026-09-15', 'start' => '07:30', 'end' => '07:40', 'pause_on' => 'justification']);
    $second = ($this->start)('justify', ['employee_id' => $this->ana->id, 'date' => '2026-09-15', 'start' => '07:40', 'end' => '07:50']);

    $firstResult = ($this->finish)($first);
    $secondResult = ($this->finish)($second);
    $last = TimesheetSnapshot::query()->where('employee_id', $this->ana->id)->where('date', '2026-09-15')->orderByDesc('sequence')->first();

    expect($firstResult)->toMatchArray(['ok' => true])
        ->and($secondResult)->toMatchArray(['ok' => true, 'error' => null])
        ->and($secondResult['finished'])->toBeGreaterThan($firstResult['finished'])
        ->and(($this->sequences)('2026-09-15'))->toBe([
            [1, TimesheetSnapshotSource::ClosureBaseline],
            [2, TimesheetSnapshotSource::BeforeChange],
            [3, TimesheetSnapshotSource::AfterChange],
            [4, TimesheetSnapshotSource::BeforeChange],
            [5, TimesheetSnapshotSource::AfterChange],
        ])
        ->and($last->inputs['calculation']['justifications'])->toHaveCount(2);
});

test('a justification holding the employee while the same competence is closed does not deadlock', function () {
    $justify = ($this->start)('justify', ['employee_id' => $this->ana->id, 'date' => '2026-09-15', 'start' => '07:30', 'end' => '07:40', 'pause_on' => 'query', 'pause_query' => '`request_uuid`']);
    $close = ($this->start)('close', ['period_id' => $this->period->id]);

    $justified = ($this->finish)($justify);
    $closed = ($this->finish)($close);

    expect($justified)->toMatchArray(['ok' => true, 'error' => null])
        ->and($closed)->toMatchArray(['ok' => true, 'error' => null])
        ->and($this->period->fresh()->status)->toBe(BenefitPeriodStatus::Closed)
        ->and(TimesheetSnapshot::query()->where('employee_id', $this->ana->id)->where('date', '2026-09-15')->sole()->inputs['calculation']['justifications'])->toHaveCount(1);
});

test('editing or canceling a justification while the same competence is closed does not deadlock', function (string $scenario) {
    $justification = app(PointJustificationService::class)->register($this->ana, '2026-09-15', [
        'request_uuid' => (string) Str::uuid(),
        'reason_id' => PointJustificationReason::query()->where('code', 'justified_delay')->sole()->id,
        'treatment' => 'full_allowance',
        'start_time' => '07:30',
        'end_time' => '07:40',
        'ends_next_day' => false,
    ], $this->user)['justification'];
    app(BenefitPeriodWorkflow::class)->calculate($this->period->fresh());

    $change = ($this->start)($scenario, ['justification_id' => $justification->id, 'start' => '07:30', 'end' => '07:35', 'pause_on' => 'query', 'pause_query' => '`employees` where `employees`.`id` = ? limit 1 for update']);
    $close = ($this->start)('close', ['period_id' => $this->period->id]);

    $changed = ($this->finish)($change);
    $closed = ($this->finish)($close);
    $baseline = TimesheetSnapshot::query()->where('employee_id', $this->ana->id)->where('date', '2026-09-15')->sole();

    expect($changed)->toMatchArray(['ok' => true, 'error' => null])
        ->and($closed)->toMatchArray(['ok' => true, 'error' => null])
        ->and($closed['finished'])->toBeGreaterThan($changed['finished'])
        ->and($baseline->inputs['calculation']['justifications'])->toHaveCount($scenario === 'cancel' ? 0 : 1)
        ->and($baseline->inputs['calculation']['justifications'][0]['end_time'] ?? null)->toBe($scenario === 'cancel' ? null : '07:35:00');
})->with(['update', 'cancel']);

/*
 * F6.3: mudança de data entre competências concorrendo com o fechamento da competência de
 * destino ou de origem. Competência 10/2026 fechada pelo fluxo real; 11/2026 apurada.
 */
describe('moves between competences', function () {
    beforeEach(function () {
        foreach (['2026-09-16', '2026-10-15'] as $date) {
            foreach (['07:50', '12:00', '13:00', '17:30'] as $time) {
                Point::factory()->forEmployee($this->ana)->on($date)->create(['time' => $time.':00']);
            }
        }

        $this->registerOn = fn (string $date) => app(PointJustificationService::class)->register($this->ana, $date, [
            'request_uuid' => (string) Str::uuid(),
            'reason_id' => PointJustificationReason::query()->where('code', 'justified_delay')->sole()->id,
            'treatment' => 'full_allowance',
            'start_time' => '07:30',
            'end_time' => '07:50',
            'ends_next_day' => false,
        ], $this->user)['justification'];

        $this->prepareCompetences = function (): BenefitPeriod {
            app(BenefitPeriodWorkflow::class)->close($this->period->fresh());
            $november = BenefitPeriod::factory()->forCompetence('2026-11')->create();
            app(BenefitPeriodWorkflow::class)->calculate($november);

            return $november->fresh();
        };
    });

    test('a move into the competence being closed, holding the employee, does not deadlock', function () {
        $justification = ($this->registerOn)('2026-09-16');
        $november = ($this->prepareCompetences)();

        $move = ($this->start)('update', ['justification_id' => $justification->id, 'start' => '07:30', 'end' => '07:50', 'occurrence_date' => '2026-10-15', 'pause_on' => 'query', 'pause_query' => '`employees` where `employees`.`id` = ? limit 1 for update']);
        $close = ($this->start)('close', ['period_id' => $november->id]);

        $moved = ($this->finish)($move);
        $closed = ($this->finish)($close);
        $destination = TimesheetSnapshot::query()->where('employee_id', $this->ana->id)->where('date', '2026-10-15')->sole();

        expect($moved)->toMatchArray(['ok' => true, 'error' => null])
            ->and($closed)->toMatchArray(['ok' => true, 'error' => null])
            ->and($closed['finished'])->toBeGreaterThan($moved['finished'])
            ->and(($this->sequences)('2026-09-16'))->toBe([
                [1, TimesheetSnapshotSource::ClosureBaseline],
                [2, TimesheetSnapshotSource::BeforeChange],
                [3, TimesheetSnapshotSource::AfterChange],
            ])
            ->and($destination->source)->toBe(TimesheetSnapshotSource::ClosureBaseline)
            ->and($destination->inputs['calculation']['justifications'])->toHaveCount(1);
    });

    test('a move out of the competence being closed waits for the closing and records after its baseline', function () {
        $justification = ($this->registerOn)('2026-10-15');
        $november = ($this->prepareCompetences)();

        $close = ($this->start)('close', ['period_id' => $november->id, 'pause_on' => 'snapshot']);
        $move = ($this->start)('update', ['justification_id' => $justification->id, 'start' => '07:30', 'end' => '07:50', 'occurrence_date' => '2026-09-16']);

        $closed = ($this->finish)($close);
        $moved = ($this->finish)($move);

        expect($closed)->toMatchArray(['ok' => true, 'error' => null])
            ->and($moved)->toMatchArray(['ok' => true, 'error' => null])
            ->and($moved['finished'])->toBeGreaterThan($closed['finished'])
            ->and(($this->sequences)('2026-10-15'))->toBe([
                [1, TimesheetSnapshotSource::ClosureBaseline],
                [2, TimesheetSnapshotSource::BeforeChange],
                [3, TimesheetSnapshotSource::AfterChange],
            ])
            ->and(($this->sequences)('2026-09-16'))->toBe([
                [1, TimesheetSnapshotSource::ClosureBaseline],
                [2, TimesheetSnapshotSource::BeforeChange],
                [3, TimesheetSnapshotSource::AfterChange],
            ])
            ->and(TimesheetSnapshot::query()->where('source', TimesheetSnapshotSource::LateBaseline)->count())->toBe(0);
    });
});
