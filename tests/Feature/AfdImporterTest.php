<?php

use App\Enums\AfdRejectionReason;
use App\Models\AfdImport;
use App\Models\AfdImportRejection;
use App\Models\AfdRep;
use App\Models\Employee;
use App\Models\ImportedLines;
use App\Models\Point;
use App\Services\AfdImporter;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AfdFileBuilder;

uses(RefreshDatabase::class);

/**
 * PIS fictícios de 12 dígitos (campo 023-034 do tipo 3). Em `points` o PIS é BIGINT,
 * então o zero à esquerda não é gravado.
 */
const AFD_PIS_A = '012345678901';
const AFD_PIS_B = '010987654321';

beforeEach(function () {
    $this->importer = app(AfdImporter::class);

    /**
     * Arquivo com marcações cuja data e horário derivam do NSR (dia 1 + NSR % 28 de
     * setembro/2026, 08:NSR % 60), precedidas de registros que não são marcações.
     */
    $this->afd = function (int $punches, ?AfdFileBuilder $builder = null): AfdFileBuilder {
        $builder ??= AfdFileBuilder::make()->company()->employee(AFD_PIS_A);

        for ($i = 0; $i < $punches; $i++) {
            $nsr = $builder->nextNsr();
            $builder->punch(AFD_PIS_A, sprintf('%02d092026', 1 + $nsr % 28), sprintf('08%02d', $nsr % 60));
        }

        return $builder;
    };

    $this->nothingPersisted = function (): void {
        expect(Point::count())->toBe(0)
            ->and(AfdRep::count())->toBe(0)
            ->and(AfdImport::count())->toBe(0)
            ->and(AfdImportRejection::count())->toBe(0);
    };

    /**
     * Reproduz o importador anterior à F13 (EmployeeIndex::importPoints até o commit
     * 19ac5d3): pula `max(imported_lines.line_number)` linhas (9 sem histórico), grava
     * qualquer linha como batida e conta as linhas consumidas, trailer incluído. O
     * trailer é contado e não é gravado, para não depender da data inválida que ele gerava.
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
});

afterEach(fn () => AfdFileBuilder::deleteWrittenFiles());

describe('records', function () {
    test('a valid file creates points only for the punches', function () {
        $path = AfdFileBuilder::make()
            ->company()
            ->employee(AFD_PIS_A)
            ->punch(AFD_PIS_A, '15092026', '0812')
            ->clockAdjustment()
            ->punch(AFD_PIS_B, '15092026', '1730')
            ->employee(AFD_PIS_B, 'A')
            ->write();

        $result = $this->importer->import($path);
        $points = Point::query()->orderBy('nsr')->get();
        $import = AfdImport::sole();

        expect($points)->toHaveCount(2)
            ->and($points->map(fn (Point $point) => [$point->getRawOriginal('pis'), $point->date->toDateString(), substr($point->getRawOriginal('time'), 0, 5), $point->type, $point->getRawOriginal('nsr'), $point->getRawOriginal('rep_serial')])->all())
            ->toEqual([
                [12345678901, '2026-09-15', '08:12', 'importado', 3, AfdFileBuilder::REP_SERIAL],
                [10987654321, '2026-09-15', '17:30', 'importado', 5, AfdFileBuilder::REP_SERIAL],
            ])
            ->and($points->pluck('afd_import_id')->unique()->all())->toBe([$import->id])
            ->and($result)->toMatchArray([
                'imported' => 2,
                'existing' => 0,
                'already_processed' => 0,
                'rejected' => 0,
                'transition' => AfdImport::TRANSITION_FRESH,
                'cursor_before' => 0,
                'cursor_after' => 6,
                'ignored' => ['header' => 1, 'company' => 1, 'clock_adjustment' => 1, 'employee' => 2, 'trailer' => 1],
            ])
            ->and([$import->company_records, $import->punch_records, $import->clock_adjustment_records, $import->employee_records])->toBe([1, 2, 1, 2])
            ->and([$import->first_nsr, $import->max_nsr, $import->max_valid_punch_nsr, $import->max_imported_nsr])->toBe([1, 6, 5, 5])
            ->and($import->file_hash)->toBe(hash_file('sha256', $path))
            ->and($import->completed_at)->not->toBeNull()
            ->and(AfdRep::sole()->processed_to_nsr)->toBe(6);
    });

    test('the last punch before the trailer is imported', function () {
        $path = ($this->afd)(3)->write();

        $this->importer->import($path);

        expect(Point::max('nsr'))->toBe(5)
            ->and(Point::count())->toBe(3);
    });

    test('lf and crlf files give the same points', function () {
        $this->importer->import(($this->afd)(2)->withLf()->write());
        $lf = Point::query()->orderBy('nsr')->get(['nsr', 'pis', 'date', 'time'])->toArray();
        DB::table('points')->delete();
        AfdImport::query()->delete();
        AfdRep::query()->delete();

        $this->importer->import(($this->afd)(2)->write());

        expect(Point::query()->orderBy('nsr')->get(['nsr', 'pis', 'date', 'time'])->toArray())->toEqual($lf);
    });

    test('two punches in the same minute with different nsr are both kept', function () {
        $this->importer->import(AfdFileBuilder::make()
            ->punch(AFD_PIS_A, '15092026', '0812')
            ->punch(AFD_PIS_A, '15092026', '0812')
            ->write());

        expect(Point::query()->orderBy('nsr')->pluck('nsr')->all())->toBe([1, 2]);
    });

    test('a punch with an unknown pis is imported and the pis never appears in the result', function () {
        Employee::factory()->create();

        $result = $this->importer->import(AfdFileBuilder::make()->punch(AFD_PIS_B, '15092026', '0812')->write());

        expect(Point::sole()->getRawOriginal('pis'))->toBe(10987654321)
            ->and(json_encode($result))->not->toContain(ltrim(AFD_PIS_B, '0'))
            ->and(json_encode(AfdImport::sole()->toArray()))->not->toContain(ltrim(AFD_PIS_B, '0'));
    });
});

describe('cumulative imports', function () {
    test('the punch that takes the place of the old trailer is imported', function () {
        $first = ($this->afd)(3);
        $this->importer->import($first->write());
        $oldTrailerLine = count($first->lines());

        $second = ($this->afd)(2, $first);
        $result = $this->importer->import($second->write());

        expect($second->lines()[$oldTrailerLine - 1])->toStartWith(sprintf('%09d3', 6))
            ->and(Point::query()->orderBy('nsr')->pluck('nsr')->all())->toBe([3, 4, 5, 6, 7])
            ->and($result)->toMatchArray(['imported' => 2, 'already_processed' => 3, 'cursor_before' => 5, 'cursor_after' => 7]);
    });

    test('reimporting the same file creates no duplicates', function () {
        $path = ($this->afd)(4)->write();

        $this->importer->import($path);
        $result = $this->importer->import($path);

        expect(Point::count())->toBe(4)
            ->and($result)->toMatchArray(['imported' => 0, 'existing' => 0, 'already_processed' => 4, 'cursor_before' => 6, 'cursor_after' => 6])
            ->and(AfdImport::count())->toBe(2);
    });

    test('a file older than the last processed nsr is refused', function () {
        AfdRep::factory()->processedTo(10)->create(['rep_serial' => AfdFileBuilder::REP_SERIAL]);

        expect(fn () => $this->importer->import(($this->afd)(3)->write()))
            ->toThrow(DomainException::class, 'antes do último NSR já processado');

        expect(Point::count())->toBe(0)
            ->and(AfdImport::count())->toBe(0)
            ->and(AfdRep::sole()->processed_to_nsr)->toBe(10);
    });

    test('a partial file that leaves a gap after the last processed nsr is refused', function () {
        AfdRep::factory()->processedTo(5)->create(['rep_serial' => AfdFileBuilder::REP_SERIAL]);

        expect(fn () => $this->importer->import(AfdFileBuilder::make()->startingAt(8)->punch(AFD_PIS_A, '15092026', '0812')->write()))
            ->toThrow(DomainException::class, 'faltariam registros');

        expect(Point::count())->toBe(0);
    });

    test('a partial file that continues the last processed nsr is accepted', function () {
        AfdRep::factory()->processedTo(7)->create(['rep_serial' => AfdFileBuilder::REP_SERIAL]);

        $result = $this->importer->import(AfdFileBuilder::make()->startingAt(6)->punch(AFD_PIS_A, '14092026', '0812')->punch(AFD_PIS_A, '15092026', '0812')->punch(AFD_PIS_A, '16092026', '0812')->write());

        expect(Point::query()->orderBy('nsr')->pluck('nsr')->all())->toBe([8])
            ->and($result)->toMatchArray(['already_processed' => 2, 'imported' => 1]);
    });

    test('each rep has its own cursor', function () {
        $this->importer->import(($this->afd)(2)->write());
        $result = $this->importer->import(($this->afd)(1, AfdFileBuilder::make('00004000000099999'))->write());

        expect(AfdRep::query()->orderBy('id')->pluck('processed_to_nsr', 'rep_serial')->all())->toBe([AfdFileBuilder::REP_SERIAL => 4, '00004000000099999' => 1])
            ->and($result)->toMatchArray(['transition' => AfdImport::TRANSITION_FRESH, 'imported' => 1])
            ->and(Point::where('rep_serial', '00004000000099999')->pluck('nsr')->all())->toBe([1]);
    });
});

describe('cursors and rejections', function () {
    test('an invalid punch is recorded by nsr and reason, without personal data, and never becomes a point', function () {
        $result = $this->importer->import(AfdFileBuilder::make()
            ->punch(AFD_PIS_A, '15092026', '0812')
            ->punch(AFD_PIS_A, '31022026', '0812')
            ->punch(AFD_PIS_A, '00000006', '0001')
            ->punch(AFD_PIS_A, '16092026', '2460')
            ->rawPunch('15092026'.'0812'.'0123')
            ->write());
        $rejections = AfdImportRejection::query()->orderBy('nsr')->get();

        expect(Point::query()->pluck('nsr')->all())->toBe([1])
            ->and($rejections->map(fn (AfdImportRejection $rejection) => [$rejection->nsr, $rejection->reason])->all())->toBe([
                [2, AfdRejectionReason::InvalidDate],
                [3, AfdRejectionReason::InvalidDate],
                [4, AfdRejectionReason::InvalidTime],
                [5, AfdRejectionReason::InvalidLength],
            ])
            ->and($rejections->pluck('afd_import_id')->unique()->all())->toBe([AfdImport::sole()->id])
            ->and($result['rejected'])->toBe(4)
            ->and($result['rejections'])->toBe([
                ['nsr' => 2, 'reason' => 'invalid_date'],
                ['nsr' => 3, 'reason' => 'invalid_date'],
                ['nsr' => 4, 'reason' => 'invalid_time'],
                ['nsr' => 5, 'reason' => 'invalid_length'],
            ])
            ->and(Schema::getColumnListing('afd_import_rejections'))->toEqualCanonicalizing(['id', 'afd_import_id', 'afd_rep_id', 'nsr', 'reason', 'created_at'])
            ->and(Point::query()->whereDate('date', '<', '2000-01-01')->count())->toBe(0);
    });

    test('the cursors separate the largest nsr, the largest valid punch and the largest imported punch', function () {
        $first = AfdFileBuilder::make()
            ->punch(AFD_PIS_A, '15092026', '0812')
            ->punch(AFD_PIS_A, '15092026', '1200')
            ->employee(AFD_PIS_A, 'A')
            ->punch(AFD_PIS_A, '31022026', '1300');
        $this->importer->import($first->write());
        $firstImport = AfdImport::sole();

        expect([$firstImport->max_nsr, $firstImport->max_valid_punch_nsr, $firstImport->max_imported_nsr, $firstImport->cursor_after, $firstImport->rejected])
            ->toBe([4, 2, 2, 4, 1]);

        $result = $this->importer->import($first->punch(AFD_PIS_A, '15092026', '1730')->write());
        $secondImport = AfdImport::query()->latest('id')->first();

        expect($result)->toMatchArray(['imported' => 1, 'rejected' => 0, 'already_processed' => 3, 'cursor_before' => 4, 'cursor_after' => 5])
            ->and([$secondImport->max_nsr, $secondImport->max_valid_punch_nsr, $secondImport->max_imported_nsr])->toBe([5, 5, 5])
            ->and(AfdImportRejection::query()->pluck('nsr')->all())->toBe([4])
            ->and(Point::query()->orderBy('nsr')->pluck('nsr')->all())->toBe([1, 2, 5]);
    });

    test('a punch already stored under the same rep and nsr is counted as existing and not duplicated', function () {
        DB::table('points')->insert(['pis' => 12345678901, 'date' => '2026-09-03', 'time' => '08:02:00', 'type' => 'importado', 'rep_serial' => AfdFileBuilder::REP_SERIAL, 'nsr' => 5, 'created_at' => now(), 'updated_at' => now()]);

        $result = $this->importer->import(($this->afd)(3)->write());
        $import = AfdImport::sole();

        expect($result)->toMatchArray(['imported' => 2, 'existing' => 1, 'cursor_after' => 5])
            ->and(Point::query()->where('nsr', 5)->count())->toBe(1)
            ->and([$import->max_valid_punch_nsr, $import->max_imported_nsr])->toBe([5, 4])
            ->and(Point::query()->where('nsr', 5)->value('afd_import_id'))->toBeNull();
    });

    test('a failure after the inserts rolls back the points, the rejections, the import and the cursor', function () {
        $path = ($this->afd)(3)->punch(AFD_PIS_A, '31022026', '0812')->write();
        $pointsBeforeFailure = null;
        AfdImport::updating(function () use (&$pointsBeforeFailure) {
            $pointsBeforeFailure = [Point::count(), AfdImportRejection::count()];

            throw new RuntimeException('Falha simulada ao concluir a importação.');
        });

        expect(fn () => $this->importer->import($path))->toThrow(RuntimeException::class, 'Falha simulada');

        expect($pointsBeforeFailure)->toBe([3, 1]);
        ($this->nothingPersisted)();
    });

    test('the rep row is locked for the whole import', function () {
        $locks = [];
        DB::listen(function ($query) use (&$locks) {
            if (str_contains($query->sql, 'afd_reps') && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $locks[] = ['level' => DB::transactionLevel(), 'for_update' => str_contains(strtolower($query->sql), 'for update')];
            }
        });
        $level = DB::transactionLevel();

        $this->importer->import(($this->afd)(1)->write());

        expect($locks[0]['level'])->toBeGreaterThan($level);

        if (DB::getDriverName() === 'mysql') {
            expect($locks[0]['for_update'])->toBeTrue();
        }
    });
});

describe('duplicates and database failures', function () {
    beforeEach(function () {
        /**
         * Executa $action uma única vez, logo depois da primeira consulta que satisfaz
         * $matches (dentro da transação da importação).
         */
        $this->afterQuery = function (Closure $matches, Closure $action): void {
            $done = false;
            DB::listen(function (QueryExecuted $query) use (&$done, $matches, $action) {
                if (! $done && $matches(strtolower($query->sql))) {
                    $done = true;
                    $action();
                }
            });
        };
        $this->isPrecheckOf = fn (string $table, string $column) => fn (string $sql) => str_starts_with(ltrim($sql), 'select')
            && (bool) preg_match('/from [`"]'.$table.'[`"] where [`"]'.$column.'[`"]/', $sql);
        $this->safeFailure = function (Closure $import, string $expectedMessage): void {
            try {
                $import();
                $this->fail('A importação deveria falhar.');
            } catch (DomainException $exception) {
                expect($exception->getMessage())->toBe($expectedMessage)
                    ->and($exception->getMessage())->not->toContain(ltrim(AFD_PIS_A, '0'))
                    ->and(strtolower($exception->getMessage()))->not->toContain('insert')
                    ->and($exception->getMessage())->not->toContain('SQLSTATE');
            }
        };
    });

    test('a nsr already stored for the rep is counted as existing and is not sent again', function () {
        DB::table('points')->insert(['pis' => 12345678901, 'date' => '2026-09-06', 'time' => '08:05:00', 'type' => 'importado', 'rep_serial' => AfdFileBuilder::REP_SERIAL, 'nsr' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $insertedNsrs = [];
        DB::listen(function (QueryExecuted $query) use (&$insertedNsrs) {
            if (preg_match('/^insert into [`"]points[`"] \(([^)]*)\)/i', $query->sql, $match)) {
                $columns = array_map(fn (string $column) => trim($column, ' `"'), explode(',', $match[1]));

                foreach (array_chunk($query->bindings, count($columns)) as $row) {
                    $insertedNsrs[] = $row[array_search('nsr', $columns, true)];
                }
            }
        });

        $result = $this->importer->import(($this->afd)(3)->write());

        expect($result)->toMatchArray(['imported' => 2, 'existing' => 1])
            ->and($insertedNsrs)->toBe([3, 4])
            ->and(Point::query()->where('nsr', 5)->count())->toBe(1);
    });

    test('a database failure that is not a duplicate while inserting points rolls everything back with a safe message', function () {
        Log::spy();
        ($this->afterQuery)(($this->isPrecheckOf)('points', 'rep_serial'), fn () => DB::table('afd_imports')->delete());

        ($this->safeFailure)(fn () => $this->importer->import(($this->afd)(3)->write()), AfdImporter::FAILURE_MESSAGE);

        ($this->nothingPersisted)();
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => array_keys($context) === ['exception', 'sqlstate', 'driver_code']
            && $context['exception'] === QueryException::class
            && ! str_contains(json_encode($context), ltrim(AFD_PIS_A, '0')));
    });

    test('a database failure that is not a duplicate while recording rejections rolls everything back', function () {
        ($this->afterQuery)(($this->isPrecheckOf)('afd_import_rejections', 'afd_rep_id'), fn () => DB::table('afd_imports')->delete());

        ($this->safeFailure)(fn () => $this->importer->import(($this->afd)(2)->punch(AFD_PIS_A, '31022026', '0812')->write()), AfdImporter::FAILURE_MESSAGE);

        ($this->nothingPersisted)();
    });

    test('a punch written by another operation between the check and the insert rolls everything back as a concurrency failure', function () {
        ($this->afterQuery)(($this->isPrecheckOf)('points', 'rep_serial'), fn () => DB::table('points')->insert([
            'pis' => 12345678901, 'date' => '2026-09-04', 'time' => '08:03:00', 'type' => 'importado',
            'rep_serial' => AfdFileBuilder::REP_SERIAL, 'nsr' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]));

        ($this->safeFailure)(fn () => $this->importer->import(($this->afd)(3)->write()), AfdImporter::CONCURRENCY_MESSAGE);

        ($this->nothingPersisted)();
    });

    test('a rejection written by another operation between the check and the insert rolls everything back as a concurrency failure', function () {
        ($this->afterQuery)(($this->isPrecheckOf)('afd_import_rejections', 'afd_rep_id'), function () {
            $import = AfdImport::query()->sole();
            DB::table('afd_import_rejections')->insert(['afd_import_id' => $import->id, 'afd_rep_id' => $import->afd_rep_id, 'nsr' => 5, 'reason' => 'invalid_date', 'created_at' => now()]);
        });

        ($this->safeFailure)(fn () => $this->importer->import(($this->afd)(2)->punch(AFD_PIS_A, '31022026', '0812')->write()), AfdImporter::CONCURRENCY_MESSAGE);

        ($this->nothingPersisted)();
    });

    test('a rejection already recorded for the rep is counted and not duplicated', function () {
        $rep = AfdRep::factory()->create(['rep_serial' => AfdFileBuilder::REP_SERIAL]);
        $previous = AfdImport::factory()->for($rep, 'rep')->create();
        AfdImportRejection::factory()->for($previous)->create(['afd_rep_id' => $rep->id, 'nsr' => 5, 'reason' => AfdRejectionReason::InvalidDate]);

        $result = $this->importer->import(($this->afd)(2)->punch(AFD_PIS_A, '31022026', '0812')->write());

        expect($result)->toMatchArray(['imported' => 2, 'rejected' => 1, 'rejections_already_recorded' => 1])
            ->and(AfdImportRejection::query()->where('nsr', 5)->count())->toBe(1)
            ->and(AfdImportRejection::query()->where('nsr', 5)->value('afd_import_id'))->toBe($previous->id);
    });

    test('a rep created by another operation is reused and never duplicated', function () {
        AfdRep::factory()->create(['rep_serial' => AfdFileBuilder::REP_SERIAL]);

        $this->importer->import(($this->afd)(2)->write());

        expect(AfdRep::count())->toBe(1)
            ->and(AfdRep::sole()->processed_to_nsr)->toBe(4);
    });

    test('a failure while creating the rep that is not a duplicate is not hidden', function () {
        ($this->afterQuery)(fn (string $sql) => str_starts_with($sql, 'insert into') && str_contains($sql, 'afd_reps'), function () {
            throw new QueryException(DB::getDefaultConnection(), 'insert into afd_reps', [], new PDOException('falha simulada'));
        });

        ($this->safeFailure)(fn () => $this->importer->import(($this->afd)(2)->write()), AfdImporter::FAILURE_MESSAGE);

        ($this->nothingPersisted)();
    });
});

describe('manual coincidences', function () {
    test('a manual punch with the same pis, date and minute as an imported punch is reported without blocking the import', function () {
        $manual = Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-15', 'time' => '08:12:00']);
        Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-15', 'time' => '12:00:00']);
        Point::factory()->manual()->create(['pis' => 10987654321, 'date' => '2026-09-15', 'time' => '08:12:00']);
        $manualBefore = DB::table('points')->where('type', 'manual')->orderBy('id')->get()->toArray();

        $result = $this->importer->import(AfdFileBuilder::make()
            ->punch(AFD_PIS_A, '15092026', '0812')
            ->punch(AFD_PIS_A, '15092026', '0812')
            ->punch(AFD_PIS_A, '15092026', '0813')
            ->punch(AFD_PIS_A, '16092026', '0812')
            ->write());

        expect($result)->toMatchArray(['imported' => 4, 'manual_coincidences' => 1])
            ->and(DB::table('points')->where('type', 'manual')->orderBy('id')->get()->toArray())->toEqual($manualBefore)
            ->and(Point::query()->where('type', 'importado')->count())->toBe(4)
            ->and($manual->fresh())->not->toBeNull();
    });

    test('each coinciding manual punch counts once', function () {
        Point::factory()->manual()->count(2)->create(['pis' => 12345678901, 'date' => '2026-09-15', 'time' => '08:12:00']);

        $result = $this->importer->import(AfdFileBuilder::make()->punch(AFD_PIS_A, '15092026', '0812')->write());

        expect($result['manual_coincidences'])->toBe(2);
    });

    test('a punch already processed is not compared again with manual punches', function () {
        $path = AfdFileBuilder::make()->punch(AFD_PIS_A, '15092026', '0812')->write();
        $this->importer->import($path);
        Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-15', 'time' => '08:12:00']);

        expect($this->importer->import($path)['manual_coincidences'])->toBe(0);
    });
});

describe('structural refusals', function () {
    test('a structurally invalid file is refused without writing anything', function (Closure $lines, string $message) {
        $builder = ($this->afd)(3);

        expect(fn () => $this->importer->import(AfdFileBuilder::writeContents(AfdFileBuilder::join($lines($builder)))))
            ->toThrow(DomainException::class, $message);

        ($this->nothingPersisted)();
    })->with([
        'trailer counts that do not match' => [fn (AfdFileBuilder $builder) => $builder->trailerCounts(['company' => 1, 'punch' => 4, 'clock_adjustment' => 0, 'employee' => 1])->lines(), 'contadores do trailer'],
        'regressive nsr' => [function (AfdFileBuilder $builder) {
            $lines = $builder->lines();
            [$lines[3], $lines[4]] = [$lines[4], $lines[3]];

            return $lines;
        }, 'NSR fora de sequência'],
        'gap in the nsr' => [function (AfdFileBuilder $builder) {
            $lines = $builder->lines();
            unset($lines[3]);

            return array_values($lines);
        }, 'NSR fora de sequência'],
        'second header of another rep' => [function (AfdFileBuilder $builder) {
            $lines = $builder->lines();
            array_splice($lines, 3, 0, [AfdFileBuilder::make('00004000000099999')->header()]);

            return $lines;
        }, 'cabeçalho repetido'],
        'missing header' => [fn (AfdFileBuilder $builder) => array_slice($builder->lines(), 1), 'a primeira linha não é o cabeçalho'],
        'missing trailer' => [fn (AfdFileBuilder $builder) => array_slice($builder->lines(), 0, -1), 'trailer ausente'],
        'record after the trailer' => [fn (AfdFileBuilder $builder) => [...$builder->lines(), $builder->lines()[2]], 'depois do trailer'],
        'blank line in the middle' => [function (AfdFileBuilder $builder) {
            $lines = $builder->lines();
            array_splice($lines, 2, 0, ['']);

            return $lines;
        }, 'linha em branco'],
        'header of another layout' => [function (AfdFileBuilder $builder) {
            $lines = $builder->lines();
            $lines[0] = str_pad($lines[0], 302, '0');

            return $lines;
        }, 'Layout de AFD não suportado'],
        'punches of another layout' => [function (AfdFileBuilder $builder) {
            return array_map(fn (string $line) => $line[9] === '3' && ! str_starts_with($line, '999999999') ? $line.str_repeat('0', 16) : $line, $builder->lines());
        }, 'nenhuma marcação tem o tamanho do layout'],
        'unknown record type' => [function (AfdFileBuilder $builder) {
            $lines = $builder->lines();
            $lines[3][9] = '7';

            return $lines;
        }, 'tipo de registro desconhecido'],
    ]);

    test('an empty file is refused', function () {
        expect(fn () => $this->importer->import(AfdFileBuilder::writeContents('')))->toThrow(DomainException::class, 'sem cabeçalho');

        ($this->nothingPersisted)();
    });
});

describe('legacy transition', function () {
    test('the first import after the legacy importer continues at the punch the legacy importer would have lost', function () {
        $first = ($this->afd)(15, AfdFileBuilder::make());
        ($this->legacyImport)($first->write());
        $legacyPoints = Point::count();

        $second = ($this->afd)(2, $first);
        $result = $this->importer->import($second->write());
        $import = AfdImport::sole();

        expect(ImportedLines::max('line_number'))->toBe(17)
            ->and($legacyPoints)->toBe(7)
            ->and($result)->toMatchArray([
                'transition' => AfdImport::TRANSITION_LEGACY,
                'legacy_line_number' => 17,
                'cursor_before' => 15,
                'cursor_after' => 17,
                'imported' => 2,
                'already_processed' => 15,
            ])
            ->and([$import->legacy_checked, $import->legacy_confirmed])->toBe([7, 7])
            ->and(Point::query()->whereNotNull('nsr')->orderBy('nsr')->pluck('nsr')->all())->toBe([16, 17])
            ->and(Point::query()->whereNull('nsr')->count())->toBe($legacyPoints)
            ->and(ImportedLines::count())->toBe(1);
    });

    test('positions lost by earlier legacy imports are expected to be missing', function () {
        $first = ($this->afd)(15, AfdFileBuilder::make());
        ($this->legacyImport)($first->write());
        $second = ($this->afd)(3, $first);
        ($this->legacyImport)($second->write());

        expect(ImportedLines::query()->orderBy('id')->pluck('line_number')->all())->toBe([17, 20]);

        $result = $this->importer->import(($this->afd)(1, $second)->write());

        expect($result)->toMatchArray(['cursor_before' => 18, 'imported' => 1])
            ->and(AfdImport::sole()->legacy_checked)->toBe(9)
            ->and(Point::query()->whereNotNull('nsr')->pluck('nsr')->all())->toBe([19]);
    });

    test('a missing legacy punch before the cursor makes the transition ambiguous', function () {
        $first = ($this->afd)(15, AfdFileBuilder::make());
        ($this->legacyImport)($first->write());
        DB::table('points')->where('id', DB::table('points')->max('id'))->delete();
        $before = DB::table('points')->count();

        expect(fn () => $this->importer->import(($this->afd)(2, $first)->write()))
            ->toThrow(DomainException::class, 'Transição do controle legado recusada: 1 de 7 registros anteriores ao NSR 16');

        expect(DB::table('points')->count())->toBe($before)
            ->and(AfdRep::count())->toBe(0)
            ->and(AfdImport::count())->toBe(0);
    });

    test('a record after the legacy cursor that already exists makes the transition ambiguous', function () {
        $first = ($this->afd)(15, AfdFileBuilder::make());
        ($this->legacyImport)($first->write());
        DB::table('points')->insert(['pis' => 12345678901, 'date' => '2026-09-17', 'time' => '08:16:00', 'type' => 'importado', 'created_at' => now(), 'updated_at' => now()]);

        expect(fn () => $this->importer->import(($this->afd)(1, $first)->write()))
            ->toThrow(DomainException::class, 'a partir do NSR 16 parecem já existir');

        expect(AfdImport::count())->toBe(0);
    });

    test('imported points without legacy history make the starting point ambiguous', function () {
        Point::factory()->create();

        expect(fn () => $this->importer->import(($this->afd)(1)->write()))
            ->toThrow(DomainException::class, 'sem histórico de importação');

        expect(Point::count())->toBe(1)
            ->and(AfdRep::count())->toBe(0);
    });

    test('manual points without legacy history do not block a fresh start', function () {
        Point::factory()->manual()->create();

        expect($this->importer->import(($this->afd)(1)->write())['transition'])->toBe(AfdImport::TRANSITION_FRESH);
    });

    test('the transition requires the complete file starting at nsr 1', function () {
        ImportedLines::create(['line_number' => 20]);

        expect(fn () => $this->importer->import(AfdFileBuilder::make()->startingAt(5)->punch(AFD_PIS_A, '15092026', '0812')->write()))
            ->toThrow(DomainException::class, 'iniciado no NSR 1');
    });

    test('a file older than the last legacy import is refused', function () {
        ImportedLines::create(['line_number' => 500]);

        expect(fn () => $this->importer->import(($this->afd)(3)->write()))
            ->toThrow(DomainException::class, 'o arquivo é anterior a ela');

        expect(AfdRep::count())->toBe(0);
    });

    test('the legacy evidence reads only the exact dates and the pis it needs, without hydrating models', function () {
        $first = ($this->afd)(15, AfdFileBuilder::make()->clockAdjustment('010120100800', '010120100805'));
        ($this->legacyImport)($first->write());
        DB::table('points')->insert(collect(range(1, 30))->map(fn (int $day) => ['pis' => 99999999999, 'date' => sprintf('2025-06-%02d', $day), 'time' => '08:00:00', 'type' => 'importado', 'created_at' => now(), 'updated_at' => now()])->all());
        $evidence = [];
        DB::listen(function (QueryExecuted $query) use (&$evidence) {
            if (str_contains(strtolower($query->sql), 'type') && str_contains(strtolower($query->sql), 'nsr') && str_contains(strtolower($query->sql), 'is null') && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $evidence[] = $query;
            }
        });

        $result = $this->importer->import(($this->afd)(2, $first)->write());
        $query = collect($evidence)->last();

        expect($result)->toMatchArray(['transition' => AfdImport::TRANSITION_LEGACY, 'cursor_before' => 16, 'imported' => 2])
            ->and(strtolower($query->sql))->not->toContain('>=')
            ->and(strtolower($query->sql))->not->toContain('<=')
            ->and($query->bindings)->not->toContain('2025-06-15')
            ->and($query->bindings)->not->toContain(99999999999);
    });

    test('the transition decisions are unchanged by the evidence query', function (Closure $tamper, ?string $refusal) {
        $first = ($this->afd)(15, AfdFileBuilder::make());
        ($this->legacyImport)($first->write());
        $tamper();

        $import = fn () => $this->importer->import(($this->afd)(2, $first)->write());

        if ($refusal === null) {
            expect($import()['cursor_before'])->toBe(15);
        } else {
            expect($import)->toThrow(DomainException::class, $refusal);
        }
    })->with([
        'complete evidence' => [fn () => null, null],
        'a legacy punch missing' => [fn () => DB::table('points')->where('id', DB::table('points')->max('id'))->delete(), 'registros anteriores ao NSR 16 não foram encontrados'],
        'a new record already present' => [fn () => DB::table('points')->insert(['pis' => 12345678901, 'date' => '2026-09-17', 'time' => '08:16:00', 'type' => 'importado', 'created_at' => now(), 'updated_at' => now()]), 'parecem já existir'],
        'a manual punch equal to a missing legacy punch does not count as evidence' => [function () {
            $last = DB::table('points')->orderByDesc('id')->first();
            DB::table('points')->where('id', $last->id)->delete();
            DB::table('points')->insert(['pis' => $last->pis, 'date' => $last->date, 'time' => $last->time, 'type' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        }, 'não foram encontrados'],
    ]);

    test('after the transition the legacy history is no longer used', function () {
        $first = ($this->afd)(15, AfdFileBuilder::make());
        ($this->legacyImport)($first->write());
        $second = ($this->afd)(2, $first);
        $this->importer->import($second->write());
        ImportedLines::create(['line_number' => 999]);

        $result = $this->importer->import(($this->afd)(1, $second)->write());

        expect($result)->toMatchArray(['transition' => null, 'cursor_before' => 17, 'imported' => 1]);
    });
});
