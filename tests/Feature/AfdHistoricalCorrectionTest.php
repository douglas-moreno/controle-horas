<?php

use App\Models\AfdCorrectionBatch;
use App\Models\AfdHistoricalNsr;
use App\Models\AfdPointQuarantine;
use App\Models\AfdRecoveredPoint;
use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriodEmployee;
use App\Models\Employee;
use App\Models\Point;
use App\Services\AfdHistoricalCorrection;
use App\Services\AfdImporter;
use App\Services\AfdPrecheck;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AfdFileBuilder;
use Tests\Support\LegacyAfdScenario;

/**
 * A simulação (sem apply) usa a sessão somente leitura do MySQL, que só vale fora de uma
 * transação; por isso este arquivo usa DatabaseTruncation.
 */
uses(DatabaseTruncation::class);

const CORRECTION_BENEFIT_TABLES = ['benefit_periods', 'benefit_period_status_changes', 'benefit_period_employees', 'benefit_calculations', 'benefit_calculation_transport_items', 'benefit_adjustments', 'benefit_adjustment_impacts', 'benefit_suggestion_runs', 'employee_benefits', 'benefit_rates'];

beforeEach(function () {
    $this->scenario = LegacyAfdScenario::build();
    $this->employee = Employee::factory()->create(['pis' => '12345678901']);
    $this->manual = Point::factory()->manual()->forEmployee($this->employee)->create(['date' => '2026-09-20', 'time' => '12:00:00']);
    BenefitAdjustment::factory()->create(['employee_id' => $this->employee->id]);
    BenefitPeriodEmployee::factory()->create(['employee_id' => $this->employee->id]);

    $this->correction = app(AfdHistoricalCorrection::class);
    $target = $this->correction->target();
    $this->apply = ['operator' => 'ensaio', 'expected' => ['hostname' => $target['hostname'], 'server_uuid' => $target['server_uuid'], 'database' => $target['database']]];

    $this->manifests = [
        'registration' => LegacyAfdScenario::write($this->scenario->registrationManifest()),
        'quarantine' => LegacyAfdScenario::write($this->scenario->quarantineManifest()),
        'recovery' => LegacyAfdScenario::write($this->scenario->recoveryManifest()),
    ];

    $this->rows = fn (string $table): array => DB::table($table)->orderBy(DB::getSchemaBuilder()->hasColumn($table, 'id') ? 'id' : DB::raw('1'))->get()->map(fn ($row) => (array) $row)->all();
    $this->snapshot = fn (): array => collect(['points', 'afd_correction_batches', 'afd_point_quarantines', 'afd_recovered_points', 'afd_historical_nsrs', 'afd_reps', 'afd_imports', ...CORRECTION_BENEFIT_TABLES])
        ->mapWithKeys(fn (string $table) => [$table => ($this->rows)($table)])->all();
    $this->benefits = fn (): array => collect(CORRECTION_BENEFIT_TABLES)->mapWithKeys(fn (string $table) => [$table => ($this->rows)($table)])->all();
    $this->spuriousIds = fn (): array => collect(array_keys(LegacyAfdScenario::SPURIOUS_LINES))->map(fn (int $line) => $this->scenario->pointIdForLine($line))->all();
});

/**
 * Limpa também depois de cada teste: os arquivos com RefreshDatabase executados em
 * seguida no mesmo processo não truncam as tabelas.
 */
afterEach(function () {
    AfdFileBuilder::deleteWrittenFiles();
    Schema::withoutForeignKeyConstraints(fn () => collect(Schema::getTableListing(schemaQualified: false))
        ->reject(fn (string $table) => $table === 'migrations')
        ->each(fn (string $table) => DB::table($table)->truncate()));
});

describe('quarantine', function () {
    test('the dry run validates the manifest without writing anything', function () {
        $before = ($this->snapshot)();
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements) {
            $statements[] = strtolower((string) strtok(ltrim($query->sql), ' '));
        });

        $result = $this->correction->quarantine($this->manifests['quarantine'], $this->scenario->path);

        expect($result)->toMatchArray(['mode' => 'dry-run', 'status' => 'valid', 'rows' => 4, 'by_record_type' => ['4' => 1, '5' => 1, '9' => 2]])
            ->and(array_unique($statements))->each->toBeIn(['select', 'set'])
            ->and(($this->snapshot)())->toBe($before);
    });

    test('a single divergent manifest row aborts the whole quarantine', function (Closure $tamper, string $message) {
        $manifest = $this->scenario->quarantineManifest();
        $manifest['rows'][1] = $tamper($manifest['rows'][1], $this);
        $before = ($this->snapshot)();

        expect(fn () => $this->correction->quarantine(LegacyAfdScenario::write($manifest), $this->scenario->path, $this->apply))
            ->toThrow(DomainException::class, $message);

        expect(($this->snapshot)())->toBe($before);
    })->with([
        'a different hash' => [fn (array $row) => [...$row, 'row_hash' => str_repeat('0', 64)], 'hash diferente do manifesto'],
        'a different afd line' => [fn (array $row) => [...$row, 'afd_line' => 17], 'posição no AFD diferente do manifesto'],
        'a legitimate punch' => [fn (array $row, $test) => [...$row, 'id' => $id = $test->scenario->pointIdForLine(11), 'afd_line' => 11, 'afd_nsr' => 10, 'afd_record_type' => '3', 'row_hash' => LegacyAfdScenario::canonicalHash($id)], 'tipo de registro não removível'],
        'a manual point' => [fn (array $row, $test) => [...$row, 'id' => $test->manual->id, 'row_hash' => LegacyAfdScenario::canonicalHash($test->manual->id)], 'não é ponto do importador legado'],
        'a missing point' => [fn (array $row) => [...$row, 'id' => 999999], 'ausentes de points'],
    ]);

    test('a failure during the transaction rolls everything back', function () {
        $before = ($this->snapshot)();
        DB::listen(function (QueryExecuted $query) {
            if (str_starts_with(strtolower($query->sql), 'delete from `points`')) {
                throw new RuntimeException('falha forçada');
            }
        });

        expect(fn () => $this->correction->quarantine($this->manifests['quarantine'], $this->scenario->path, $this->apply))->toThrow(RuntimeException::class, 'falha forçada');

        expect(($this->snapshot)())->toBe($before);
    });

    test('it moves exactly the manifest rows, keeps manual and punch points, and a second run changes nothing', function () {
        $spurious = ($this->spuriousIds)();
        $kept = DB::table('points')->whereNotIn('id', $spurious)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $original = DB::table('points')->whereIn('id', $spurious)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $result = $this->correction->quarantine($this->manifests['quarantine'], $this->scenario->path, $this->apply);

        expect($result)->toMatchArray(['mode' => 'apply', 'status' => 'applied', 'rows' => 4])
            ->and($result['points_after'])->toBe($result['points_before'] - 4)
            ->and(DB::table('points')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($kept)
            ->and(AfdPointQuarantine::query()->orderBy('original_id')->get()->map(fn (AfdPointQuarantine $row) => [
                'id' => $row->original_id, 'pis' => $row->pis, 'date' => $row->date, 'time' => $row->time, 'type' => $row->type,
                'created_at' => $row->original_created_at, 'updated_at' => $row->original_updated_at,
            ])->all())->toBe(collect($original)->map(fn (array $row) => ['id' => $row['id'], 'pis' => $row['pis'], 'date' => $row['date'], 'time' => $row['time'], 'type' => $row['type'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']])->all())
            ->and(AfdCorrectionBatch::sole())->toMatchArray(['kind' => 'quarantine', 'status' => 'applied', 'expected_count' => 4, 'applied_count' => 4, 'applied_by' => 'ensaio'])
            ->and(AfdCorrectionBatch::sole()->target)->toMatchArray($this->apply['expected']);

        $state = ($this->snapshot)();
        $again = $this->correction->quarantine($this->manifests['quarantine'], $this->scenario->path, $this->apply);

        expect($again)->toMatchArray(['status' => 'already_applied', 'batch_id' => $result['batch_id']])
            ->and(($this->snapshot)())->toBe($state);
    });

    test('the precheck and the importer see the same legacy transition before and after the quarantine', function () {
        registerHistoricalPendings($this->scenario->path, LegacyAfdScenario::MISSING_NSRS);
        $precheck = app(AfdPrecheck::class);
        $before = $precheck->run($this->scenario->path);

        $this->correction->quarantine($this->manifests['quarantine'], $this->scenario->path, $this->apply);
        $after = $precheck->run($this->scenario->path);

        expect($before['verdict'])->toBe(AfdPrecheck::VERDICT_READY)
            ->and($after['verdict'])->toBe(AfdPrecheck::VERDICT_READY)
            ->and($after['start'])->toBe($before['start'])
            ->and($after['start'])->toMatchArray(['cursor' => 76, 'legacy_checked' => 50, 'legacy_confirmed' => 50])
            ->and($after['coverage'])->toBe($before['coverage'])
            ->and($after['records']['new'])->toBe(2)
            ->and(app(AfdImporter::class)->import($this->scenario->path))->toMatchArray(['cursor_before' => 76, 'imported' => 2]);
    });
});

describe('restore', function () {
    test('it returns the original ids, timestamps and content and keeps the evidence', function () {
        $before = ($this->rows)('points');
        $batch = $this->correction->quarantine($this->manifests['quarantine'], $this->scenario->path, $this->apply)['batch_id'];

        expect($this->correction->restore($batch))->toMatchArray(['mode' => 'dry-run', 'status' => 'valid', 'rows' => 4]);

        $result = $this->correction->restore($batch, $this->apply);

        expect($result)->toMatchArray(['status' => 'restored', 'rows' => 4])
            ->and(($this->rows)('points'))->toBe($before)
            ->and(AfdPointQuarantine::query()->pluck('status')->unique()->all())->toBe([AfdPointQuarantine::STATUS_RESTORED])
            ->and(AfdCorrectionBatch::sole())->toMatchArray(['status' => 'reverted', 'reverted_by' => 'ensaio']);
    });

    test('it is refused when the quarantine was changed or an id is back in points', function (Closure $tamper, string $message) {
        $batch = $this->correction->quarantine($this->manifests['quarantine'], $this->scenario->path, $this->apply)['batch_id'];
        $tamper($this);
        $state = ($this->snapshot)();

        expect(fn () => $this->correction->restore($batch, $this->apply))->toThrow(DomainException::class, $message);

        expect(($this->snapshot)())->toBe($state);
    })->with([
        'a changed quarantine row' => [fn () => DB::table('afd_point_quarantines')->where('id', DB::table('afd_point_quarantines')->min('id'))->update(['pis' => 1]), 'hash da quarentena divergente'],
        'an id reused in points' => [fn () => DB::table('points')->insert(['id' => DB::table('afd_point_quarantines')->min('original_id'), 'pis' => 1, 'type' => 'manual']), 'já existem em points'],
    ]);
});

describe('recovery', function () {
    beforeEach(function () {
        $this->correction->register($this->manifests['registration'], $this->scenario->path, $this->apply);
    });

    test('it inserts only the approved punches, with rep and nsr, and the precheck keeps validating the legacy', function () {
        $precheck = app(AfdPrecheck::class);
        $this->correction->quarantine($this->manifests['quarantine'], $this->scenario->path, $this->apply);
        $before = $precheck->run($this->scenario->path);

        $result = $this->correction->recover($this->manifests['recovery'], $this->scenario->path, $this->apply);
        $after = $precheck->run($this->scenario->path);
        $recovered = Point::query()->whereNotNull('nsr')->orderBy('nsr')->get();

        expect($result)->toMatchArray(['status' => 'applied', 'rows' => 2])
            ->and(DB::table('points')->whereNotNull('nsr')->orderBy('nsr')->get(['pis', 'date', 'time', 'type', 'rep_serial', 'nsr', 'afd_import_id'])->map(fn ($row) => (array) $row)->all())->toBe([
                ['pis' => 12345678901, 'date' => '2026-09-04', 'time' => '08:03:00', 'type' => 'importado', 'rep_serial' => AfdFileBuilder::REP_SERIAL, 'nsr' => 3, 'afd_import_id' => null],
                ['pis' => 12345678901, 'date' => '2026-09-16', 'time' => '08:15:00', 'type' => 'importado', 'rep_serial' => AfdFileBuilder::REP_SERIAL, 'nsr' => 15, 'afd_import_id' => null],
            ])
            ->and(AfdRecoveredPoint::query()->orderBy('nsr')->pluck('point_id')->all())->toBe($recovered->pluck('id')->all())
            ->and(AfdHistoricalNsr::query()->where('status', 'recovered')->orderBy('nsr')->pluck('nsr')->all())->toBe([3, 15])
            ->and($after['verdict'])->toBe(AfdPrecheck::VERDICT_READY)
            ->and($after['start'])->toBe($before['start'])
            ->and($after['records']['new'])->toBe(2)
            ->and($after['coverage']['counts'])->toMatchArray(['imported' => 2, 'recovered' => 2, 'pending' => 5, 'gap' => 0, 'legacy_verified' => 65])
            ->and(DB::table('afd_reps')->count() + DB::table('afd_imports')->count())->toBe(0)
            ->and(Point::query()->whereKey($this->manual->id)->sole()->only(['pis', 'type']))->toBe(['pis' => 12345678901, 'type' => 'manual']);
    });

    test('it refuses punches outside the approved classification or the registry', function (Closure $manifest, string $message) {
        $state = ($this->snapshot)();

        expect(fn () => $this->correction->recover(LegacyAfdScenario::write($manifest($this->scenario)), $this->scenario->path, $this->apply))
            ->toThrow(DomainException::class, $message);

        expect(($this->snapshot)())->toBe($state);
    })->with([
        'a punch that depends on the HR' => [fn (LegacyAfdScenario $scenario) => $scenario->recoveryManifest([4]), 'classificação C1 não permite recuperação automática'],
        'a punch without a registered pending' => [fn (LegacyAfdScenario $scenario) => $scenario->recoveryManifest([10]), 'sem pendência histórica registrada'],
        'a line that is not the punch' => [fn (LegacyAfdScenario $scenario) => [...$scenario->recoveryManifest([15]), 'rows' => [[...$scenario->recoveryManifest([15])['rows'][0], 'afd_line_sha256' => str_repeat('0', 64)]]], 'SHA-256 diferente'],
    ]);

    test('repeating it changes nothing and a nsr already stored or a point with the same key is refused', function () {
        $this->correction->recover($this->manifests['recovery'], $this->scenario->path, $this->apply);
        $state = ($this->snapshot)();

        expect($this->correction->recover($this->manifests['recovery'], $this->scenario->path, $this->apply))->toMatchArray(['status' => 'already_applied'])
            ->and(($this->snapshot)())->toBe($state)
            ->and(fn () => $this->correction->recover(LegacyAfdScenario::write($this->scenario->recoveryManifest([15])), $this->scenario->path, $this->apply))
            ->toThrow(DomainException::class, 'já gravados com REP e NSR');

        $this->correction->revert(AfdCorrectionBatch::query()->where('kind', 'recovery')->sole()->id, $this->apply);
        Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-16', 'time' => '08:15:00']);
        $state = ($this->snapshot)();

        expect(fn () => $this->correction->recover($this->manifests['recovery'], $this->scenario->path, $this->apply))
            ->toThrow(DomainException::class, 'NSR 15: já existe ponto com o mesmo PIS, data e minuto');
        expect(($this->snapshot)())->toBe($state)
            ->and(Point::query()->whereNotNull('nsr')->count())->toBe(0);
    });

    test('batches are reverted in the reverse order of application', function () {
        $quarantine = $this->correction->quarantine($this->manifests['quarantine'], $this->scenario->path, $this->apply)['batch_id'];
        $this->correction->recover($this->manifests['recovery'], $this->scenario->path, $this->apply);

        expect(fn () => $this->correction->restore($quarantine, $this->apply))->toThrow(DomainException::class, 'reverta antes o(s) lote(s) posterior(es)');
    });

    test('the full rollback returns points to the initial state, keeps the evidence and never touches the benefits', function () {
        $points = ($this->rows)('points');
        $benefits = ($this->benefits)();
        $registry = ($this->rows)('afd_historical_nsrs');

        $quarantine = $this->correction->quarantine($this->manifests['quarantine'], $this->scenario->path, $this->apply)['batch_id'];
        expect(($this->benefits)())->toBe($benefits);
        $recovery = $this->correction->recover($this->manifests['recovery'], $this->scenario->path, $this->apply)['batch_id'];
        expect(($this->benefits)())->toBe($benefits);

        expect($this->correction->revert($recovery, $this->apply))->toMatchArray(['status' => 'reverted', 'rows' => 2])
            ->and($this->correction->restore($quarantine, $this->apply))->toMatchArray(['status' => 'restored', 'rows' => 4])
            ->and(($this->rows)('points'))->toBe($points)
            ->and(($this->benefits)())->toBe($benefits)
            ->and(collect(($this->rows)('afd_historical_nsrs'))->map(fn (array $row) => collect($row)->except('updated_at')->all())->all())
            ->toBe(collect($registry)->map(fn (array $row) => collect($row)->except('updated_at')->all())->all())
            ->and(AfdCorrectionBatch::query()->orderBy('id')->pluck('status', 'kind')->all())->toBe(['registration' => 'applied', 'quarantine' => 'reverted', 'recovery' => 'reverted'])
            ->and(AfdPointQuarantine::count())->toBe(4)
            ->and(AfdRecoveredPoint::query()->pluck('status')->unique()->all())->toBe(['reverted']);
    });
});

describe('registration and target', function () {
    test('the registration refuses a punch proven by the legacy importer and is idempotent', function () {
        $manifest = $this->scenario->registrationManifest();
        $manifest['rows'][] = [...$this->scenario->recoveryManifest([10])['rows'][0], 'classification' => 'C1', 'evidence' => 'x'];
        $manifest['expected_count']++;

        expect(fn () => $this->correction->register(LegacyAfdScenario::write($manifest), $this->scenario->path, $this->apply))
            ->toThrow(DomainException::class, 'NSR 10: comprovado no legado');

        $first = $this->correction->register($this->manifests['registration'], $this->scenario->path, $this->apply);
        $state = ($this->snapshot)();

        expect($first)->toMatchArray(['status' => 'applied', 'rows' => 7])
            ->and($this->correction->register($this->manifests['registration'], $this->scenario->path, $this->apply))->toMatchArray(['status' => 'already_applied'])
            ->and(($this->snapshot)())->toBe($state);
    });

    test('applying requires the operator and the exact identity of the target database', function (Closure $apply, string $message) {
        $state = ($this->snapshot)();

        expect(fn () => $this->correction->quarantine($this->manifests['quarantine'], $this->scenario->path, $apply($this->apply)))
            ->toThrow(DomainException::class, $message);

        expect(($this->snapshot)())->toBe($state);
    })->with([
        'no operator' => [fn (array $apply) => [...$apply, 'operator' => ''], 'Informe o responsável'],
        'another server' => [fn (array $apply) => [...$apply, 'expected' => [...$apply['expected'], 'server_uuid' => 'outro']], 'diferente do esperado (server_uuid)'],
        'another database' => [fn (array $apply) => [...$apply, 'expected' => [...$apply['expected'], 'database' => 'laravel']], 'diferente do esperado (database)'],
        'another host' => [fn (array $apply) => [...$apply, 'expected' => [...$apply['expected'], 'hostname' => null]], 'diferente do esperado (hostname)'],
    ]);

    test('a manifest for another afd file is refused', function () {
        $manifest = $this->scenario->quarantineManifest();
        $manifest['afd_sha256'] = str_repeat('0', 64);

        expect(fn () => $this->correction->quarantine(LegacyAfdScenario::write($manifest), $this->scenario->path, $this->apply))
            ->toThrow(DomainException::class, 'SHA-256 diferente do manifesto');
    });
});
