<?php

use App\Models\AfdImport;
use App\Models\AfdImportRejection;
use App\Models\AfdPointQuarantine;
use App\Models\AfdRep;
use App\Models\Employee;
use App\Models\ImportedLines;
use App\Models\Point;
use App\Services\AfdImporter;
use App\Services\AfdNsrCoverage;
use App\Services\AfdParser;
use App\Services\AfdPrecheck;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\AfdFileBuilder;

/**
 * A sessão somente leitura do MySQL só vale fora de uma transação; por isso este
 * arquivo usa DatabaseTruncation em vez de RefreshDatabase.
 */
uses(DatabaseTruncation::class);

/**
 * PIS fictícios de 12 dígitos (campo 023-034 do tipo 3).
 */
const PRECHECK_PIS_A = '012345678901';
const PRECHECK_PIS_B = '010987654321';

beforeEach(function () {
    $this->precheck = app(AfdPrecheck::class);
    $this->importer = app(AfdImporter::class);

    $this->afd = function (int $punches, ?AfdFileBuilder $builder = null): AfdFileBuilder {
        $builder ??= AfdFileBuilder::make()->company()->employee(PRECHECK_PIS_A);

        for ($i = 0; $i < $punches; $i++) {
            $nsr = $builder->nextNsr();
            $builder->punch(PRECHECK_PIS_A, sprintf('%02d092026', 1 + $nsr % 28), sprintf('08%02d', $nsr % 60));
        }

        return $builder;
    };

    /**
     * Reproduz o importador anterior à F13, como em AfdImporterTest.
     */
    $this->legacyImport = function (string $path): void {
        $startLine = (int) (ImportedLines::query()->max('line_number') ?? 9);
        $handle = fopen($path, 'r');

        for ($i = 0; $i < $startLine; $i++) {
            fgets($handle);
        }

        $counter = 0;
        $rows = [];

        while (($line = fgets($handle)) !== false) {
            $counter++;

            if (str_starts_with($line, '999999999')) {
                continue;
            }

            $hour = substr($line, 18, 4);
            $rows[] = [
                'date' => Carbon::createFromFormat('dmY', substr($line, 10, 8))->format('Y-m-d'),
                'time' => substr($hour, 0, 2).':'.substr($hour, 2, 2).':00',
                'pis' => trim(substr($line, 23, 12)),
                'type' => 'importado',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        fclose($handle);

        if ($rows !== []) {
            DB::table('points')->insert($rows);
        }

        ImportedLines::create(['line_number' => $startLine + $counter]);
    };

    /**
     * Estado observável de todas as tabelas tocadas pela importação.
     */
    $this->snapshot = fn (): array => [
        'points' => DB::table('points')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'afd_reps' => DB::table('afd_reps')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'afd_imports' => DB::table('afd_imports')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'afd_import_rejections' => DB::table('afd_import_rejections')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'imported_lines' => DB::table('imported_lines')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'employees' => DB::table('employees')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
});

afterEach(fn () => AfdFileBuilder::deleteWrittenFiles());

describe('report', function () {
    test('after the legacy importer the precheck reports exactly what the import then does', function () {
        Employee::factory()->create(['pis' => '12345678901']);
        $first = ($this->afd)(15, AfdFileBuilder::make());
        ($this->legacyImport)($first->write());
        $path = ($this->afd)(2, $first)->write();
        registerHistoricalPendings($path, range(1, 8));

        $report = $this->precheck->run($path);

        expect($report['verdict'])->toBe(AfdPrecheck::VERDICT_READY)
            ->and($report['blocking'])->toBe([])
            ->and($report['notice'])->toBe(AfdPrecheck::NOT_AN_AUTHORIZATION)
            ->and($report['structure'])->toMatchArray(['status' => 'ok', 'first_nsr' => 1, 'max_nsr' => 17, 'trailer_line' => 19])
            ->and($report['start'])->toMatchArray([
                'status' => 'ok',
                'rep_registered' => false,
                'imported_lines' => 1,
                'legacy_max_line_number' => 17,
                'transition' => AfdImport::TRANSITION_LEGACY,
                'cursor' => 15,
                'legacy_line_number' => 17,
                'legacy_checked' => 7,
                'legacy_confirmed' => 7,
                'cursor_after' => 17,
            ])
            ->and($report['records'])->toMatchArray([
                'punches' => 17,
                'already_processed' => 15,
                'candidates' => 2,
                'new' => 2,
                'existing' => 0,
                'rejected' => 0,
                'manual_coincidences' => 0,
                'new_nsr_range' => [16, 17],
            ])
            ->and($report['employees'])->toMatchArray(['pis_in_new_punches' => 1, 'associated' => 1, 'not_found' => []]);

        $result = $this->importer->import($path);
        $import = AfdImport::sole();

        expect($result)->toMatchArray([
            'transition' => $report['start']['transition'],
            'cursor_before' => $report['start']['cursor'],
            'cursor_after' => $report['start']['cursor_after'],
            'imported' => $report['records']['new'],
            'existing' => $report['records']['existing'],
            'already_processed' => $report['records']['already_processed'],
            'rejected' => $report['records']['rejected'],
        ])->and([$import->legacy_checked, $import->legacy_confirmed])->toBe([$report['start']['legacy_checked'], $report['start']['legacy_confirmed']]);
    });

    test('a registered rep continues from its cursor', function () {
        $first = ($this->afd)(3);
        $this->importer->import($first->write());

        $report = $this->precheck->run(($this->afd)(2, $first)->write());

        expect($report['start'])->toMatchArray(['rep_registered' => true, 'processed_to_nsr' => 5, 'transition' => null, 'cursor' => 5, 'cursor_after' => 7])
            ->and($report['records'])->toMatchArray(['already_processed' => 3, 'new' => 2]);
    });

    test('a divergence in the legacy evidence window blocks the import', function () {
        $first = ($this->afd)(15, AfdFileBuilder::make());
        ($this->legacyImport)($first->write());
        DB::table('points')->where('id', DB::table('points')->max('id'))->delete();

        $report = $this->precheck->run(($this->afd)(2, $first)->write());

        expect($report['verdict'])->toBe(AfdPrecheck::VERDICT_BLOCKED)
            ->and($report['start']['status'])->toBe('refused')
            ->and($report['start']['message'])->toContain('1 de 7 registros anteriores ao NSR 16')
            ->and($report['blocking'][0])->toStartWith('Ponto de partida: Transição do controle legado recusada')
            ->and($report['records'])->toBeNull();
    });

    test('a quarantined legacy record inside the evidence window still proves the transition', function () {
        $first = ($this->afd)(10, AfdFileBuilder::make())->employee(PRECHECK_PIS_A);
        ($this->afd)(4, $first);
        ($this->legacyImport)($first->write());
        $path = ($this->afd)(2, $first)->write();
        registerHistoricalPendings($path, range(1, 8));
        $before = $this->precheck->run($path);

        $employeeRecord = DB::table('points')->where('type', 'importado')->orderBy('id')->offset(2)->first();
        AfdPointQuarantine::factory()->create([
            'original_id' => $employeeRecord->id,
            'pis' => $employeeRecord->pis,
            'date' => $employeeRecord->date,
            'time' => $employeeRecord->time,
            'original_created_at' => $employeeRecord->created_at,
            'original_updated_at' => $employeeRecord->updated_at,
            'afd_line' => 12,
            'afd_nsr' => 11,
        ]);
        DB::table('points')->where('id', $employeeRecord->id)->delete();

        $after = $this->precheck->run($path);

        expect($before['verdict'])->toBe(AfdPrecheck::VERDICT_READY)
            ->and($after['verdict'])->toBe(AfdPrecheck::VERDICT_READY)
            ->and($after['start'])->toBe($before['start'])
            ->and($after['start'])->toMatchArray(['cursor' => 15, 'legacy_checked' => 7, 'legacy_confirmed' => 7])
            ->and($this->importer->import($path))->toMatchArray(['cursor_before' => 15, 'imported' => 2]);
    });

    test('a restored quarantine record does not prove the transition twice', function () {
        $first = ($this->afd)(10, AfdFileBuilder::make())->employee(PRECHECK_PIS_A);
        ($this->afd)(4, $first);
        ($this->legacyImport)($first->write());
        $employeeRecord = DB::table('points')->where('type', 'importado')->orderBy('id')->offset(2)->first();
        AfdPointQuarantine::factory()->create([
            'original_id' => $employeeRecord->id,
            'pis' => $employeeRecord->pis,
            'date' => $employeeRecord->date,
            'time' => $employeeRecord->time,
            'status' => AfdPointQuarantine::STATUS_RESTORED,
        ]);
        DB::table('points')->where('id', $employeeRecord->id)->delete();

        $report = $this->precheck->run(($this->afd)(2, $first)->write());

        expect($report['verdict'])->toBe(AfdPrecheck::VERDICT_BLOCKED)
            ->and($report['start']['message'])->toContain('1 de 7 registros anteriores ao NSR 16 não foram encontrados no banco (primeiro NSR ausente: 11)');
    });

    test('a partial afd reports that the coverage is limited to the nsrs in the file', function () {
        ($this->afd)(6, $complete = AfdFileBuilder::make());
        $this->importer->import($complete->write());
        $withoutStart = AfdFileBuilder::make()->startingAt(5)->punch(PRECHECK_PIS_A, '06092026', '0800')->punch(PRECHECK_PIS_A, '07092026', '0800')->punch(PRECHECK_PIS_A, '08092026', '0800');
        $endingBeforeCursor = ($this->afd)(3, AfdFileBuilder::make());

        $partial = $this->precheck->run($withoutStart->write());
        $truncated = $this->precheck->run($endingBeforeCursor->write());
        $full = $this->precheck->run(($this->afd)(1, $complete)->write());

        expect($partial['coverage'])->toMatchArray(['cursor' => 6, 'universe' => 2])
            ->and($partial['coverage']['scope'])->toBe(['first_nsr' => 5, 'last_nsr' => 7, 'partial' => true, 'unchecked' => [[1, 4]]])
            ->and($partial['warnings'])->toContain('AFD parcial: a cobertura de NSR se limita aos registros presentes no arquivo (NSR 5 a 7); os NSR 1 a 4 até o cursor não foram conferidos, e a reconciliação histórica NÃO está completa.')
            ->and(implode(' ', $partial['limitations']))->toContain('um AFD parcial')
            ->and($truncated['verdict'])->toBe(AfdPrecheck::VERDICT_BLOCKED)
            ->and($truncated['start']['message'])->toContain('o arquivo termina no NSR 3, antes do último NSR já processado deste relógio (6)')
            ->and($full['coverage']['scope'])->toBe(['first_nsr' => 1, 'last_nsr' => 7, 'partial' => false, 'unchecked' => []])
            ->and(implode(' ', $full['warnings']))->not->toContain('AFD parcial');
    });

    test('a record after the legacy cursor already present blocks the import', function () {
        $first = ($this->afd)(15, AfdFileBuilder::make());
        ($this->legacyImport)($first->write());
        DB::table('points')->insert(['pis' => 12345678901, 'date' => '2026-09-17', 'time' => '08:16:00', 'type' => 'importado', 'created_at' => now(), 'updated_at' => now()]);

        $report = $this->precheck->run(($this->afd)(1, $first)->write());

        expect($report['verdict'])->toBe(AfdPrecheck::VERDICT_BLOCKED)
            ->and($report['start']['message'])->toContain('a partir do NSR 16 parecem já existir');
    });

    test('a nsr already stored for the rep is reported as existing', function () {
        $first = ($this->afd)(2);
        $this->importer->import($first->write());
        $second = ($this->afd)(3, $first);
        DB::table('points')->insert(['pis' => 12345678901, 'date' => '2026-09-06', 'time' => '08:05:00', 'type' => 'importado', 'rep_serial' => AfdFileBuilder::REP_SERIAL, 'nsr' => 5, 'created_at' => now(), 'updated_at' => now()]);

        $report = $this->precheck->run($second->write());

        expect($report['verdict'])->toBe(AfdPrecheck::VERDICT_READY)
            ->and($report['records'])->toMatchArray(['candidates' => 3, 'new' => 2, 'existing' => 1])
            ->and($report['warnings'])->toContain('1 marcação(ões) depois do cursor já gravada(s) com o mesmo REP e NSR: a importação as contará como existentes.');
    });

    test('invalid punches are counted by reason, as the importer would reject them', function () {
        $path = AfdFileBuilder::make()
            ->punch(PRECHECK_PIS_A, '15092026', '0812')
            ->punch(PRECHECK_PIS_A, '31022026', '0812')
            ->punch(PRECHECK_PIS_A, '15092026', '2512')
            ->punch('0000000000AB', '15092026', '0812')
            ->write();

        $report = $this->precheck->run($path);

        expect($report['records'])->toMatchArray([
            'candidates' => 4,
            'new' => 1,
            'rejected' => 3,
            'rejected_by_reason' => ['invalid_date' => 1, 'invalid_time' => 1, 'invalid_pis' => 1],
            'rejections_already_recorded' => 0,
        ]);

        $result = $this->importer->import($path);

        expect($result['rejected'])->toBe($report['records']['rejected']);
    });

    test('rejections already recorded for the rep are reported', function () {
        $first = AfdFileBuilder::make()->punch(PRECHECK_PIS_A, '15092026', '0812');
        $this->importer->import($first->write());
        AfdImportRejection::query()->insert(['afd_import_id' => AfdImport::sole()->id, 'afd_rep_id' => AfdRep::sole()->id, 'nsr' => 2, 'reason' => 'invalid_date', 'created_at' => now()]);

        $report = $this->precheck->run($first->punch(PRECHECK_PIS_A, '31022026', '0812')->write());

        expect($report['records'])->toMatchArray(['rejected' => 1, 'rejections_already_recorded' => 1]);
    });

    test('a pis without employee is reported masked and never in full', function () {
        Employee::factory()->create(['pis' => '12345678901']);
        $path = AfdFileBuilder::make()
            ->punch(PRECHECK_PIS_A, '15092026', '0812')
            ->punch(PRECHECK_PIS_B, '15092026', '0813')
            ->punch(PRECHECK_PIS_B, '16092026', '0813')
            ->write();

        $report = $this->precheck->run($path);
        $json = json_encode($report);

        expect($report['employees'])->toMatchArray([
            'pis_in_new_punches' => 2,
            'associated' => 1,
            'not_found' => [['pis' => '********321', 'punches' => 2, 'first_date' => '2026-09-15', 'last_date' => '2026-09-16']],
        ])
            ->and($report['verdict'])->toBe(AfdPrecheck::VERDICT_READY)
            ->and($json)->not->toContain('10987654321')
            ->and($json)->not->toContain('12345678901');
    });

    test('a pis with more than one employee and a rescinded employee with later punches are reported', function () {
        Employee::factory()->terminated('2026-09-10')->create(['pis' => '12345678901']);
        Employee::factory()->count(2)->sequence(['pis' => '10987654321'], ['pis' => '010987654321'])->create();

        $report = $this->precheck->run(AfdFileBuilder::make()
            ->punch(PRECHECK_PIS_A, '15092026', '0812')
            ->punch(PRECHECK_PIS_B, '15092026', '0812')
            ->write());

        expect($report['employees'])->toMatchArray(['associated' => 2, 'multiple_records' => 1, 'punches_after_recision' => 1]);
    });

    test('manual punches with the same pis, date and minute are reported', function () {
        Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-15', 'time' => '08:12:00']);
        Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-15', 'time' => '12:00:00']);
        $path = AfdFileBuilder::make()->punch(PRECHECK_PIS_A, '15092026', '0812')->punch(PRECHECK_PIS_A, '16092026', '0812')->write();

        $report = $this->precheck->run($path);

        expect($report['records']['manual_coincidences'])->toBe(1)
            ->and($this->importer->import($path)['manual_coincidences'])->toBe(1);
    });

    test('dates outside the header period are reported', function () {
        $report = $this->precheck->run(AfdFileBuilder::make()->punch(PRECHECK_PIS_A, '15082026', '0812')->write());

        expect($report['records']['dates_outside_file_period'])->toBe(1);
    });

    test('a structural refusal blocks the import', function () {
        $path = ($this->afd)(2)->trailerCounts(['company' => 1, 'punch' => 9, 'clock_adjustment' => 0, 'employee' => 1])->write();

        $report = $this->precheck->run($path);

        expect($report['verdict'])->toBe(AfdPrecheck::VERDICT_BLOCKED)
            ->and($report['structure']['status'])->toBe('refused')
            ->and($report['blocking'][0])->toContain('contadores do trailer')
            ->and($report['start'])->toBeNull();
    });
});

describe('read-only isolation', function () {
    test('the precheck changes nothing and runs only reads and the session mode switch', function () {
        Employee::factory()->create(['pis' => '12345678901']);
        $first = ($this->afd)(15, AfdFileBuilder::make());
        ($this->legacyImport)($first->write());
        Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-18', 'time' => '08:17:00']);
        $path = ($this->afd)(2, ($this->afd)(0, $first)->punch('0000000000AB', '15092026', '0812'))->write();
        $before = ($this->snapshot)();
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements) {
            $statements[] = strtolower(trim($query->sql));
        });

        $report = $this->precheck->run($path);
        $listened = $statements;

        expect($report['records'])->toMatchArray(['new' => 2, 'rejected' => 1, 'manual_coincidences' => 1])
            ->and(($this->snapshot)())->toEqual($before)
            ->and(collect($listened)->reject(fn (string $sql) => str_starts_with($sql, 'select') || in_array($sql, ['set session transaction read only', 'set session transaction read write'], true))->all())->toBe([])
            ->and(collect($listened)->first())->toStartWith('select @@session.transaction_read_only')
            ->and(collect($listened)->last())->toBe('set session transaction read write')
            ->and(array_keys($report['read_only']['statements']))->toEqualCanonicalizing(['select', 'set'])
            ->and($report['read_only']['mysql_session_read_only_verified'])->toBeTrue()
            ->and((int) DB::selectOne('select @@session.transaction_read_only as v')->v)->toBe(0);
    });

    test('the mysql session itself refuses writes that bypass the guard', function () {
        $readOnly = null;

        expect(function () use (&$readOnly) {
            $this->precheck->whileReadOnly(function () use (&$readOnly) {
                $readOnly = (int) DB::selectOne('select @@session.transaction_read_only as v')->v;
                DB::connection()->getPdo()->exec('insert into imported_lines (line_number, created_at, updated_at) values (1, now(), now())');
            });
        })->toThrow(PDOException::class, 'READ ONLY');

        expect($readOnly)->toBe(1)
            ->and(ImportedLines::count())->toBe(0)
            ->and((int) DB::selectOne('select @@session.transaction_read_only as v')->v)->toBe(0);
    });

    test('the guard blocks writes and transactions before they reach the database', function (Closure $write) {
        expect(fn () => $this->precheck->whileReadOnly($write))->toThrow(LogicException::class, 'Pré-validação interrompida');

        expect(ImportedLines::count())->toBe(0)
            ->and(DB::connection()->transactionLevel())->toBe(0);

        ImportedLines::create(['line_number' => 1]);

        expect(ImportedLines::count())->toBe(1);
    })->with([
        'insert' => [fn () => DB::table('imported_lines')->insert(['line_number' => 1])],
        'update' => [fn () => DB::table('imported_lines')->update(['line_number' => 1])],
        'delete' => [fn () => DB::table('imported_lines')->delete()],
        'unprepared' => [fn () => DB::unprepared('delete from imported_lines')],
        'transaction' => [fn () => DB::transaction(fn () => null)],
    ]);

    test('the precheck refuses to run inside a transaction', function () {
        DB::beginTransaction();

        try {
            expect(fn () => $this->precheck->run(($this->afd)(1)->write()))->toThrow(DomainException::class, 'fora de uma transação');
        } finally {
            DB::rollBack();
        }
    });
});

describe('command', function () {
    test('the command reports without importing and never calls the importer', function () {
        $importer = Mockery::mock(AfdImporter::class, [app(AfdParser::class), app(AfdNsrCoverage::class)])->makePartial();
        $importer->shouldNotReceive('import');
        app()->instance(AfdImporter::class, $importer);
        $path = ($this->afd)(2)->write();

        $exitCode = Artisan::call('afd:precheck', ['path' => $path]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Resultado: sem bloqueios')
            ->and($output)->toContain(AfdPrecheck::NOT_AN_AUTHORIZATION)
            ->and($output)->toContain('Isolamento: sessão MySQL somente leitura confirmada')
            ->and(Point::count())->toBe(0)
            ->and(AfdRep::count())->toBe(0)
            ->and(AfdImport::count())->toBe(0);
    });

    test('the command outputs json', function () {
        $this->artisan('afd:precheck', ['path' => ($this->afd)(1)->write(), '--json' => true])
            ->expectsOutputToContain('"verdict": "ready_for_supervised_import"')
            ->assertSuccessful();
    });

    test('the command fails when the file is blocked', function () {
        $path = ($this->afd)(1)->trailerCounts(['company' => 0, 'punch' => 0, 'clock_adjustment' => 0, 'employee' => 0])->write();

        $this->artisan('afd:precheck', ['path' => $path])
            ->expectsOutputToContain('BLOQUEADO')
            ->assertFailed();
    });

    test('the command fails when the file does not exist', function () {
        $this->artisan('afd:precheck', ['path' => '/tmp/afd-inexistente.txt'])
            ->expectsOutputToContain('não encontrado')
            ->assertFailed();
    });
});
