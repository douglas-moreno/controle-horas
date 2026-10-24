<?php

use App\Models\AfdCorrectionBatch;
use App\Models\AfdHistoricalNsr;
use App\Models\AfdImport;
use App\Models\AfdImportRejection;
use App\Models\AfdPointQuarantine;
use App\Models\AfdRep;
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
 * Retenção de marcação nova por decisão do RH (classificação R): a simulação e a
 * pré-validação usam a sessão somente leitura do MySQL, que só vale fora de uma
 * transação; por isso este arquivo usa DatabaseTruncation.
 *
 * Cenário: cursor legado 76, marcações novas NSR 77 e 78; a 77 é retida.
 */
uses(DatabaseTruncation::class);

beforeEach(function () {
    expect(DB::connection()->getDatabaseName())->toStartWith('testing');

    $this->scenario = LegacyAfdScenario::build();
    $this->correction = app(AfdHistoricalCorrection::class);
    $this->importer = app(AfdImporter::class);
    $this->precheck = app(AfdPrecheck::class);

    $target = $this->correction->target();
    $this->identity = ['--expect-host' => $target['hostname'], '--expect-server-uuid' => $target['server_uuid'], '--expect-database' => $target['database']];
    $this->apply = ['operator' => 'ensaio', 'expected' => ['hostname' => $target['hostname'], 'server_uuid' => $target['server_uuid'], 'database' => $target['database']]];

    $this->registerPendings = fn () => registerHistoricalPendings($this->scenario->path, LegacyAfdScenario::MISSING_NSRS, 'C1');
    $this->withhold = fn (array $manifest, ?string $path = null): array => $this->correction->withhold(LegacyAfdScenario::write($manifest), $path ?? $this->scenario->path, $this->apply);

    $this->nothingImported = function (): void {
        expect(Point::query()->whereNotNull('nsr')->count())->toBe(0)
            ->and(AfdImport::count())->toBe(0)
            ->and(AfdImportRejection::count())->toBe(0)
            ->and(AfdRep::query()->whereNotNull('processed_to_nsr')->count())->toBe(0);
    };

    $this->nothingWithheld = function (): void {
        expect(AfdHistoricalNsr::query()->where('status', AfdHistoricalNsr::STATUS_WITHHELD)->count())->toBe(0)
            ->and(AfdCorrectionBatch::query()->where('kind', AfdCorrectionBatch::KIND_WITHHOLDING)->count())->toBe(0);
    };
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

describe('registering the withholding', function () {
    test('the dry run validates the manifest without writing anything', function () {
        ($this->registerPendings)();
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements) {
            $statements[] = strtolower((string) strtok(ltrim($query->sql), ' '));
        });

        $result = $this->correction->withhold(LegacyAfdScenario::write($this->scenario->withholdingManifest()), $this->scenario->path);

        expect($result)->toMatchArray(['mode' => 'dry-run', 'status' => 'valid', 'rows' => 1, 'cursor' => 76, 'nsrs' => [77]])
            ->and(array_unique($statements))->each->toBeIn(['select', 'set']);
        ($this->nothingWithheld)();
    });

    test('the withholding is recorded in its own batch with the formal decision of HR', function () {
        ($this->registerPendings)();
        $before = AfdHistoricalNsr::query()->orderBy('id')->get()->toArray();

        $result = ($this->withhold)($this->scenario->withholdingManifest());
        $entry = AfdHistoricalNsr::query()->where('nsr', 77)->sole();
        $batch = AfdCorrectionBatch::query()->where('kind', AfdCorrectionBatch::KIND_WITHHOLDING)->sole();

        expect($result)->toMatchArray(['mode' => 'apply', 'status' => 'applied', 'batch_id' => $batch->id, 'rows' => 1, 'cursor' => 76, 'nsrs' => [77]])
            ->and($batch->only(['status', 'applied_by', 'expected_count', 'applied_count', 'rep_serial']))->toBe(['status' => 'applied', 'applied_by' => 'ensaio', 'expected_count' => 1, 'applied_count' => 1, 'rep_serial' => AfdFileBuilder::REP_SERIAL])
            ->and($entry->only(['afd_correction_batch_id', 'classification', 'status', 'afd_line', 'afd_line_sha256', 'evidence', 'decided_by', 'decision_justification', 'recovered_point_id']))->toBe([
                'afd_correction_batch_id' => $batch->id,
                'classification' => 'R',
                'status' => 'withheld',
                'afd_line' => 78,
                'afd_line_sha256' => hash('sha256', $this->scenario->lines()[78]),
                'evidence' => 'Comunicação formal do RH',
                'decided_by' => 'Responsável do RH',
                'decision_justification' => 'Legitimidade da marcação não confirmada pelo RH.',
                'recovered_point_id' => null,
            ])
            ->and($entry->decided_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-24 18:00:00')
            ->and(AfdHistoricalNsr::query()->where('nsr', '!=', 77)->orderBy('id')->get()->toArray())->toBe($before)
            ->and(Point::query()->whereNotNull('nsr')->count())->toBe(0);
    });

    test('applying the same manifest again changes nothing', function () {
        ($this->registerPendings)();
        $manifest = $this->scenario->withholdingManifest();
        $first = ($this->withhold)($manifest);

        expect(($this->withhold)($manifest))->toMatchArray(['status' => 'already_applied', 'batch_id' => $first['batch_id'], 'rows' => 1])
            ->and(AfdHistoricalNsr::query()->where('status', 'withheld')->count())->toBe(1)
            ->and(AfdCorrectionBatch::query()->where('kind', 'withholding')->count())->toBe(1);
    });

    test('a manifest row that does not match the afd or the rules is refused', function (Closure $manifest, string $message) {
        ($this->registerPendings)();

        expect(fn () => ($this->withhold)($manifest($this->scenario)))->toThrow(DomainException::class, $message);
        ($this->nothingWithheld)();
    })->with([
        'another classification' => [fn (LegacyAfdScenario $scenario) => $scenario->withholdingManifest(overrides: ['classification' => 'C1']), 'classificação C1 não é retenção (R)'],
        'another line hash' => [fn (LegacyAfdScenario $scenario) => $scenario->withholdingManifest(overrides: ['afd_line_sha256' => hash('sha256', 'outra linha')]), 'linha do AFD ausente ou com SHA-256 diferente'],
        'another line number' => [fn (LegacyAfdScenario $scenario) => $scenario->withholdingManifest(overrides: ['afd_line' => 79]), 'linha do AFD ausente ou com SHA-256 diferente'],
        'a punch up to the cursor' => [fn (LegacyAfdScenario $scenario) => $scenario->withholdingManifest([50]), 'NSR 50: NSR até o cursor 76: não é marcação nova'],
        'a repeated nsr' => [fn (LegacyAfdScenario $scenario) => $scenario->withholdingManifest([77, 77]), 'NSR repetido no manifesto'],
    ]);

    test('a withholding without the formal decision of HR is refused', function (array $overrides, string $message) {
        ($this->registerPendings)();

        expect(fn () => ($this->withhold)($this->scenario->withholdingManifest(overrides: $overrides)))->toThrow(DomainException::class, $message);
        ($this->nothingWithheld)();
    })->with([
        'no responsible person' => [['decided_by' => ''], 'sem registro formal da decisão do RH'],
        'no justification' => [['decision_justification' => null], 'sem registro formal da decisão do RH'],
        'no evidence' => [['evidence' => ' '], 'sem registro formal da decisão do RH'],
        'no decision date' => [['decided_at' => null], 'sem registro formal da decisão do RH'],
        'an invalid decision date' => [['decided_at' => 'ontem à tarde'], 'data da decisão do RH inválida ou futura'],
        'a future decision date' => [['decided_at' => '2999-01-01'], 'data da decisão do RH inválida ou futura'],
    ]);

    test('a punch already registered, recorded or imported cannot be withheld', function () {
        ($this->registerPendings)();
        ($this->withhold)($this->scenario->withholdingManifest());

        expect(fn () => ($this->withhold)($this->scenario->withholdingManifest(overrides: ['decided_by' => 'Outra pessoa'])))->toThrow(DomainException::class, '1 NSR(s) do manifesto já registrados');

        DB::table('points')->insert(['pis' => 12345678901, 'date' => '2026-09-23', 'time' => '08:18:00', 'type' => 'importado', 'rep_serial' => AfdFileBuilder::REP_SERIAL, 'nsr' => 78, 'created_at' => now(), 'updated_at' => now()]);

        expect(fn () => ($this->withhold)($this->scenario->withholdingManifest([78])))->toThrow(DomainException::class, '1 NSR(s) do manifesto já gravados com REP e NSR');
    });

    test('the registration of historical pendings does not accept the withholding classification', function () {
        $manifest = $this->scenario->registrationManifest([3 => 'R']);

        expect(fn () => $this->correction->register(LegacyAfdScenario::write($manifest), $this->scenario->path, $this->apply))->toThrow(DomainException::class, 'NSR 3: classificação inválida');
    });

    test('the whole afd is validated: sequence, trailer and counters', function (Closure $tamper, string $message) {
        ($this->registerPendings)();
        $path = $tamper($this->scenario);
        $manifest = $this->scenario->withholdingManifest(path: $path);

        expect(fn () => ($this->withhold)($manifest, $path))->toThrow(DomainException::class, $message)
            ->and(fn () => $this->importer->import($path))->toThrow(DomainException::class, $message);
        ($this->nothingWithheld)();
        ($this->nothingImported)();
    })->with([
        'trailer counters' => [fn (LegacyAfdScenario $scenario) => $scenario->file->trailerCounts(['company' => 1, 'punch' => 1, 'clock_adjustment' => 1, 'employee' => 2])->write(), 'os contadores do trailer não correspondem'],
        'nsr sequence' => [fn (LegacyAfdScenario $scenario) => AfdFileBuilder::writeContents(AfdFileBuilder::join(array_values(array_filter($scenario->file->lines(), fn (string $line) => ! str_starts_with($line, '000000050'))))), 'NSR fora de sequência'],
    ]);
});

describe('importing with a withholding', function () {
    beforeEach(function () {
        ($this->registerPendings)();
        ($this->withhold)($this->scenario->withholdingManifest());
        $this->entry = fn (): array => AfdHistoricalNsr::query()->where('nsr', 77)->sole()->toArray();
        $this->withheldEntry = ($this->entry)();
    });

    test('the withheld punch is counted apart, not stored, and the cursor moves past it', function () {
        $result = $this->importer->import($this->scenario->path, null, 'ensaio');
        $import = AfdImport::sole();

        expect($result)->toMatchArray(['imported' => 1, 'withheld' => 1, 'withheld_nsrs' => [77], 'existing' => 0, 'rejected' => 0, 'cursor_before' => 76, 'cursor_after' => 78, 'max_imported_nsr' => 78])
            ->and(Point::query()->where('rep_serial', AfdFileBuilder::REP_SERIAL)->orderBy('nsr')->pluck('nsr')->all())->toBe([78])
            ->and(Point::query()->where('nsr', 77)->exists())->toBeFalse()
            ->and($import->only(['imported', 'withheld', 'existing', 'rejected', 'operator', 'cursor_before', 'cursor_after']))->toBe(['imported' => 1, 'withheld' => 1, 'existing' => 0, 'rejected' => 0, 'operator' => 'ensaio', 'cursor_before' => 76, 'cursor_after' => 78])
            ->and(AfdRep::sole()->processed_to_nsr)->toBe(78)
            ->and(AfdImportRejection::count())->toBe(0)
            ->and(($this->entry)())->toBe($this->withheldEntry);
    });

    test('importing the same afd again neither duplicates nor stores the withheld punch', function () {
        $this->importer->import($this->scenario->path);
        $points = Point::query()->orderBy('id')->get()->toArray();

        $again = $this->importer->import($this->scenario->path);

        expect($again)->toMatchArray(['imported' => 0, 'withheld' => 0, 'existing' => 0, 'cursor_before' => 78, 'cursor_after' => 78])
            ->and($again['already_processed'])->toBe(74)
            ->and(Point::query()->orderBy('id')->get()->toArray())->toBe($points)
            ->and(Point::query()->where('nsr', 77)->exists())->toBeFalse()
            ->and(AfdImport::query()->orderBy('id')->pluck('withheld')->all())->toBe([1, 0]);
    });

    test('the withheld punch is never stored when it reappears in a later afd', function () {
        $this->importer->import($this->scenario->path);
        $this->scenario->file->punch(LegacyAfdScenario::PIS, '25092026', '0819')->punch(LegacyAfdScenario::PIS, '25092026', '1719');
        $later = $this->scenario->file->write();

        $result = $this->importer->import($later);
        $coverage = $this->precheck->run($later)['coverage'];

        expect($result)->toMatchArray(['imported' => 2, 'withheld' => 0, 'cursor_before' => 78, 'cursor_after' => 80])
            ->and(Point::query()->where('rep_serial', AfdFileBuilder::REP_SERIAL)->orderBy('nsr')->pluck('nsr')->all())->toBe([78, 79, 80])
            ->and(Point::query()->where('nsr', 77)->exists())->toBeFalse()
            ->and($coverage['counts'])->toMatchArray(['withheld' => 1, 'gap' => 0, 'imported' => 3])
            ->and($coverage['nsrs']['withheld'])->toBe([77])
            ->and($coverage['inconsistencies'])->toBe([]);
    });

    test('a failure in the middle of the import rolls everything back', function () {
        DB::listen(function (QueryExecuted $query) {
            if (str_starts_with($query->sql, 'update `afd_reps`')) {
                throw new RuntimeException('falha simulada');
            }
        });

        expect(fn () => $this->importer->import($this->scenario->path))->toThrow(RuntimeException::class, 'falha simulada');
        ($this->nothingImported)();
        expect(AfdRep::count())->toBe(0)
            ->and(($this->entry)())->toBe($this->withheldEntry);
    });

    test('a withholding whose line or hash no longer matches the afd blocks the import', function (array $change) {
        AfdHistoricalNsr::query()->where('nsr', 77)->update($change);

        $report = $this->precheck->run($this->scenario->path);

        expect($report['verdict'])->toBe(AfdPrecheck::VERDICT_BLOCKED)
            ->and($report['coverage']['inconsistencies'])->toBe(['NSR 77: linha do AFD diferente da registrada na retenção.'])
            ->and(fn () => $this->importer->import($this->scenario->path))->toThrow(DomainException::class, '1 inconsistência(s) na cobertura');
        ($this->nothingImported)();
    })->with([
        'hash' => [['afd_line_sha256' => hash('sha256', 'outra linha')]],
        'line' => [['afd_line' => 79]],
    ]);

    test('a withholding without the documented decision blocks the import', function () {
        AfdHistoricalNsr::query()->where('nsr', 77)->update(['decided_by' => null]);

        expect($this->precheck->run($this->scenario->path)['coverage']['inconsistencies'])->toBe(['NSR 77: retenção sem responsável, justificativa, data ou evidência da decisão do RH.'])
            ->and(fn () => $this->importer->import($this->scenario->path))->toThrow(DomainException::class, '1 inconsistência(s) na cobertura');
        ($this->nothingImported)();
    });

    test('the precheck reports the withholding before and after the import', function () {
        $before = $this->precheck->run($this->scenario->path);

        expect($before['verdict'])->toBe(AfdPrecheck::VERDICT_READY)
            ->and($before['records'])->toMatchArray(['candidates' => 2, 'new' => 1, 'withheld' => 1, 'withheld_nsrs' => [77], 'rejected' => 0, 'existing' => 0])
            ->and($before['coverage']['withheld_ahead'])->toBe([77])
            ->and($before['coverage']['counts'])->toMatchArray(['withheld' => 0, 'gap' => 0, 'pending' => 7])
            ->and($before['coverage']['inconsistencies'])->toBe([])
            ->and(implode(' ', $before['warnings']))->toContain('1 marcação(ões) retida(s) por decisão do RH (NSR 77): não seriam gravadas');

        $this->importer->import($this->scenario->path);
        $after = $this->precheck->run($this->scenario->path);

        expect($after['verdict'])->toBe(AfdPrecheck::VERDICT_READY)
            ->and($after['start']['cursor'])->toBe(78)
            ->and($after['records'])->toMatchArray(['new' => 0, 'withheld' => 0])
            ->and($after['coverage']['withheld_ahead'])->toBe([])
            ->and($after['coverage']['counts'])->toMatchArray(['withheld' => 1, 'gap' => 0, 'imported' => 1, 'pending' => 7])
            ->and($after['coverage']['nsrs']['withheld'])->toBe([77])
            ->and($after['coverage']['inconsistencies'])->toBe([])
            ->and(implode(' ', $after['warnings']))->toContain('1 marcação(ões) retida(s) por decisão do RH até o cursor (NSR 77), NÃO importada(s): aguardam decisão final do RH.');
    });
});

test('imported, withheld, rejected and existing punches are counted without overlap', function () {
    ($this->registerPendings)();
    $this->scenario->file->punch(LegacyAfdScenario::PIS, '32092026', '0819')
        ->punch(LegacyAfdScenario::PIS, '25092026', '0820')
        ->punch(LegacyAfdScenario::PIS, '25092026', '0821');
    $path = $this->scenario->file->write();
    ($this->withhold)($this->scenario->withholdingManifest(path: $path), $path);
    DB::table('points')->insert(['pis' => 12345678901, 'date' => '2026-09-25', 'time' => '08:20:00', 'type' => 'importado', 'rep_serial' => AfdFileBuilder::REP_SERIAL, 'nsr' => 80, 'created_at' => now(), 'updated_at' => now()]);

    $records = $this->precheck->run($path)['records'];
    $result = $this->importer->import($path);

    expect($records)->toMatchArray(['candidates' => 5, 'new' => 2, 'existing' => 1, 'rejected' => 1, 'withheld' => 1])
        ->and($result)->toMatchArray(['imported' => 2, 'existing' => 1, 'rejected' => 1, 'withheld' => 1, 'withheld_nsrs' => [77], 'cursor_after' => 81])
        ->and($result['imported'] + $result['existing'] + $result['rejected'] + $result['withheld'])->toBe($records['candidates'])
        ->and(AfdImportRejection::query()->pluck('nsr')->all())->toBe([79])
        ->and(Point::query()->where('rep_serial', AfdFileBuilder::REP_SERIAL)->orderBy('nsr')->pluck('nsr')->all())->toBe([78, 80, 81]);
});

test('the withholding coexists with the registration, quarantine and recovery batches', function () {
    $manifests = [
        'registration' => LegacyAfdScenario::write($this->scenario->registrationManifest()),
        'quarantine' => LegacyAfdScenario::write($this->scenario->quarantineManifest()),
        'recovery' => LegacyAfdScenario::write($this->scenario->recoveryManifest()),
    ];
    $this->correction->register($manifests['registration'], $this->scenario->path, $this->apply);
    $this->correction->quarantine($manifests['quarantine'], $this->scenario->path, $this->apply);
    $this->correction->recover($manifests['recovery'], $this->scenario->path, $this->apply);
    $quarantined = AfdPointQuarantine::query()->orderBy('id')->get()->toArray();
    $recovered = Point::query()->whereIn('nsr', [3, 15])->orderBy('id')->get()->toArray();
    $historical = AfdHistoricalNsr::query()->orderBy('id')->get()->toArray();

    ($this->withhold)($this->scenario->withholdingManifest());
    $result = $this->importer->import($this->scenario->path);
    $report = $this->precheck->run($this->scenario->path);

    expect($result)->toMatchArray(['imported' => 1, 'withheld' => 1, 'cursor_before' => 76, 'cursor_after' => 78])
        ->and(AfdCorrectionBatch::query()->orderBy('id')->pluck('status', 'kind')->all())->toBe(['registration' => 'applied', 'quarantine' => 'applied', 'recovery' => 'applied', 'withholding' => 'applied'])
        ->and(AfdPointQuarantine::query()->orderBy('id')->get()->toArray())->toBe($quarantined)
        ->and(Point::query()->whereIn('id', array_column($quarantined, 'original_id'))->exists())->toBeFalse()
        ->and(Point::query()->whereIn('nsr', [3, 15])->orderBy('id')->get()->toArray())->toBe($recovered)
        ->and(AfdHistoricalNsr::query()->where('nsr', '!=', 77)->orderBy('id')->get()->toArray())->toBe($historical)
        ->and(AfdHistoricalNsr::query()->orderBy('status')->pluck('status')->countBy()->all())->toBe(['pending' => 5, 'recovered' => 2, 'withheld' => 1])
        ->and($report['verdict'])->toBe(AfdPrecheck::VERDICT_READY)
        ->and($report['coverage']['counts'])->toMatchArray(['recovered' => 2, 'pending' => 5, 'withheld' => 1, 'gap' => 0])
        ->and($report['coverage']['inconsistencies'])->toBe([]);
});

describe('commands', function () {
    beforeEach(function () {
        ($this->registerPendings)();
        $this->manifestPath = LegacyAfdScenario::write($this->scenario->withholdingManifest());
    });

    test('afd:import refuses without the operator or with another target database', function (array $options, string $message) {
        $this->artisan('afd:import', ['path' => $this->scenario->path, ...$this->identity, ...$options])
            ->expectsOutputToContain($message)
            ->assertFailed();

        ($this->nothingImported)();
    })->with([
        'no operator' => [[], 'Informe o responsável pela importação'],
        'another host' => [['--operator' => 'ensaio', '--expect-host' => 'outro-host'], 'Banco de destino diferente do esperado (hostname)'],
        'another server uuid' => [['--operator' => 'ensaio', '--expect-server-uuid' => 'e81bb1f7-bcd2-11f1-acb3-4a6823fe9a73'], 'Banco de destino diferente do esperado (server_uuid)'],
        'no database' => [['--operator' => 'ensaio', '--expect-database' => ''], 'Banco de destino diferente do esperado (database)'],
    ]);

    test('afd:correction withhold refuses another target database', function () {
        $this->artisan('afd:correction', ['action' => 'withhold', '--manifest' => $this->manifestPath, '--afd' => $this->scenario->path, '--apply' => true, '--operator' => 'ensaio', ...$this->identity, '--expect-server-uuid' => 'e81bb1f7-bcd2-11f1-acb3-4a6823fe9a73'])
            ->expectsOutputToContain('Banco de destino diferente do esperado (server_uuid)')
            ->assertFailed();

        ($this->nothingWithheld)();
    });

    test('the withholding and the import run through the commands', function () {
        $this->artisan('afd:correction', ['action' => 'withhold', '--manifest' => $this->manifestPath, '--afd' => $this->scenario->path, '--apply' => true, '--operator' => 'ensaio', ...$this->identity])
            ->expectsOutputToContain('"status": "applied"')
            ->assertSuccessful();

        $this->artisan('afd:import', ['path' => $this->scenario->path, '--operator' => 'ensaio', ...$this->identity])
            ->expectsOutputToContain('"withheld": 1')
            ->assertSuccessful();

        expect(AfdImport::sole()->only(['operator', 'withheld', 'imported']))->toBe(['operator' => 'ensaio', 'withheld' => 1, 'imported' => 1])
            ->and(Point::query()->where('nsr', 77)->exists())->toBeFalse();
    });
});
