<?php

use App\Models\AfdCorrectionBatch;
use App\Models\AfdHistoricalNsr;
use App\Models\AfdPointDecision;
use App\Models\Employee;
use App\Models\Point;
use App\Services\AfdHistoricalCorrection;
use App\Services\AfdPointAnnotations;
use App\Services\AfdPrecheck;
use App\Services\TimesheetMirror;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;
use Tests\Support\AfdFileBuilder;
use Tests\Support\LegacyAfdScenario;
use Tests\Support\ProductionMode;

/**
 * Anotações do RH sobre pontos importados do AFD (F7): só registro, sem alterar `points`
 * nem os cálculos. A simulação usa a sessão somente leitura do MySQL, que só vale fora de
 * uma transação; por isso este arquivo usa DatabaseTruncation.
 *
 * Cenário (os dois casos reais em miniatura): NSR 3 e 4, duas marcações do funcionário
 * ativo em 02/10/2026 16:30 (dupla batida); NSR 5, marcação de funcionário rescindido em
 * 20/10/2026 17:28; NSR 6, marcação comum em 05/10/2026 08:00. Todas importadas com REP e
 * NSR.
 */
uses(DatabaseTruncation::class);

beforeEach(function () {
    expect(DB::connection()->getDatabaseName())->toStartWith('testing');

    $this->file = AfdFileBuilder::make()->company()->employee('012345678901')
        ->punch('012345678901', '02102026', '1630')
        ->punch('012345678901', '02102026', '1630')
        ->punch('010987654321', '20102026', '1728')
        ->punch('012345678901', '05102026', '0800');
    $this->path = $this->file->write();
    $this->lines = array_combine(range(1, count($this->file->lines())), $this->file->lines());
    $this->employee = Employee::factory()->create(['pis' => '12345678901']);
    $this->rescinded = Employee::factory()->terminated('2022-05-31')->create(['pis' => '10987654321']);

    $this->points = collect([3 => ['12345678901', '2026-10-02', '16:30:00'], 4 => ['12345678901', '2026-10-02', '16:30:00'], 5 => ['10987654321', '2026-10-20', '17:28:00'], 6 => ['12345678901', '2026-10-05', '08:00:00']])
        ->map(fn (array $punch, int $nsr) => DB::table('points')->insertGetId(['pis' => (int) $punch[0], 'date' => $punch[1], 'time' => $punch[2], 'type' => 'importado', 'rep_serial' => AfdFileBuilder::REP_SERIAL, 'nsr' => $nsr, 'created_at' => now(), 'updated_at' => now()]))
        ->all();

    $this->annotations = app(AfdPointAnnotations::class);
    $target = app(AfdHistoricalCorrection::class)->target();
    $this->apply = ['operator' => 'ensaio', 'expected' => ['hostname' => $target['hostname'], 'server_uuid' => $target['server_uuid'], 'database' => $target['database']]];
    $this->evidence = ['evidence_type' => 'memorando', 'evidence_reference' => 'RH-2026-020'];

    $this->row = fn (int $nsr, string $decision, array $extra = []): array => [
        'nsr' => $nsr,
        'afd_line' => $nsr + 1,
        'afd_line_sha256' => hash('sha256', $this->lines[$nsr + 1]),
        'point_id' => $this->points[$nsr],
        'decision' => $decision,
        'decided_by' => 'Responsável do RH',
        'decided_role' => 'Analista de RH',
        'decided_at' => $decision === 'revoke' ? '2026-10-23T10:00:00-03:00' : '2026-10-22T10:00:00-03:00',
        'justification' => 'Decisão administrativa do RH.',
        ...$extra,
    ];

    $this->write = fn (array $rows): string => LegacyAfdScenario::write([
        'kind' => 'point_annotation',
        'afd_sha256' => hash_file('sha256', $this->path),
        'rep_serial' => AfdFileBuilder::REP_SERIAL,
        'expected_count' => count($rows),
        'rows' => $rows,
    ]);

    /**
     * Manifesto aprovado: hash de cada ponto mostrado pela simulação.
     */
    $this->approved = function (array $rows): string {
        $preview = $this->annotations->preview(($this->write)($rows), $this->path);

        return ($this->write)(array_map(fn (array $row, array $result) => [...$row, 'expected_point_hash' => $result['point_hash']], $rows, $preview['results']));
    };

    $this->duplicate = fn (array $extra = []): array => ($this->row)(4, 'duplicate', ['duplicate_of_point_id' => $this->points[3], ...$extra]);

    $this->tables = fn (): array => collect(Schema::getTableListing(schemaQualified: false))
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->get()->map(fn ($row) => (array) $row)->sortBy(fn (array $row) => json_encode($row))->values()->all()])
        ->all();
});

/**
 * Limpa também depois de cada teste: os arquivos com RefreshDatabase executados em
 * seguida no mesmo processo não truncam as tabelas.
 */
afterEach(function () {
    ProductionMode::leave();
    AfdFileBuilder::deleteWrittenFiles();
    Schema::withoutForeignKeyConstraints(fn () => collect(Schema::getTableListing(schemaQualified: false))
        ->reject(fn (string $table) => $table === 'migrations')
        ->each(fn (string $table) => DB::table($table)->truncate()));
});

test('the preview validates both annotations, writes nothing and runs only selects', function () {
    $before = ($this->tables)();
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements) {
        $statements[] = strtolower((string) strtok(ltrim($query->sql), ' '));
    });

    $report = $this->annotations->preview(($this->write)([($this->duplicate)(), ($this->row)(5, 'disregard', $this->evidence)]), $this->path);

    expect($report)->toMatchArray(['mode' => 'preview', 'writes' => 'none', 'status' => 'valid', 'rows' => 2, 'by_decision' => ['disregard' => 1, 'duplicate' => 1], 'refused_rows' => 0])
        ->and($report['results'][0])->toMatchArray(['point_id' => $this->points[4], 'duplicate_of_point_id' => $this->points[3], 'employee_id' => $this->employee->id, 'date' => '2026-10-02', 'effect' => 'none', 'errors' => []])
        ->and($report['results'][0]['point_hash'])->toBe(LegacyAfdScenario::canonicalHash($this->points[4]))
        ->and($report['results'][0]['duplicate_of_point_hash'])->toBe(LegacyAfdScenario::canonicalHash($this->points[3]))
        ->and($report['results'][1]['employee_id'])->toBe($this->rescinded->id)
        ->and(json_encode($report))->not->toContain('12345678901')
        ->and(array_unique($statements))->each->toBeIn(['select', 'set'])
        ->and(($this->tables)())->toBe($before);
});

test('the annotations are recorded in their own batch without touching points', function () {
    $points = DB::table('points')->orderBy('id')->get()->map(fn ($point) => (array) $point)->all();

    $result = $this->annotations->apply(($this->approved)([($this->duplicate)(), ($this->row)(5, 'disregard', $this->evidence)]), $this->path, $this->apply);
    $duplicate = AfdPointDecision::query()->where('decision', 'duplicate')->sole();
    $disregard = AfdPointDecision::query()->where('decision', 'disregard')->sole();

    expect($result)->toMatchArray(['status' => 'applied', 'rows' => 2])
        ->and($duplicate->batch->kind)->toBe(AfdCorrectionBatch::KIND_POINT_ANNOTATION)
        ->and($duplicate->only(['point_id', 'nsr', 'afd_line', 'duplicate_of_point_id', 'decided_role', 'status']))->toBe(['point_id' => $this->points[4], 'nsr' => 4, 'afd_line' => 5, 'duplicate_of_point_id' => $this->points[3], 'decided_role' => 'Analista de RH', 'status' => 'active'])
        ->and($duplicate->point_hash)->toBe(LegacyAfdScenario::canonicalHash($this->points[4]))
        ->and($duplicate->decided_at->toIso8601String())->toBe('2026-10-22T13:00:00+00:00')
        ->and($disregard->only(['point_id', 'evidence_type', 'evidence_reference', 'duplicate_of_point_id']))->toBe(['point_id' => $this->points[5], 'evidence_type' => 'memorando', 'evidence_reference' => 'RH-2026-020', 'duplicate_of_point_id' => null])
        ->and(DB::table('points')->orderBy('id')->get()->map(fn ($point) => (array) $point)->all())->toBe($points);
});

test('the mirror only marks the annotated punches: the day keeps counting all of them', function () {
    $mirror = app(TimesheetMirror::class);
    $day = fn () => $mirror->build(Point::query()->where('pis', 12345678901)->whereDate('date', '2026-10-02')->get(), CarbonImmutable::parse('2026-10-02'), CarbonImmutable::parse('2026-10-02'))['days'][0];
    $before = $day();

    $this->annotations->apply(($this->approved)([($this->duplicate)()]), $this->path, $this->apply);
    $after = $day();

    expect($before['hr_annotations'])->toBe([])
        ->and($after['hr_annotations'])->toBe(['16:30 Duplicada (RH)'])
        ->and(collect($after)->except('hr_annotations')->all())->toBe(collect($before)->except('hr_annotations')->all())
        ->and($after['punch_count'])->toBe(2);
});

test('the precheck reports the annotations as record only', function () {
    $this->annotations->apply(($this->approved)([($this->duplicate)(), ($this->row)(5, 'disregard', $this->evidence)]), $this->path, $this->apply);

    expect(app(AfdPrecheck::class)->run($this->path)['warnings'])->toContain('2 ponto(s) importado(s) com anotação do RH (Desconsiderada (RH): 1, Duplicada (RH): 1): só registro; continuam contando no espelho e nos cálculos.');
});

test('a revocation supersedes the annotation, keeps the history and frees the point', function () {
    $this->annotations->apply(($this->approved)([($this->duplicate)()]), $this->path, $this->apply);

    $this->annotations->apply(($this->approved)([($this->row)(4, 'revoke')]), $this->path, $this->apply);
    $history = AfdPointDecision::query()->orderBy('id')->get();

    expect($history->map->only(['decision', 'status'])->all())->toBe([['decision' => 'duplicate', 'status' => 'superseded'], ['decision' => 'revoke', 'status' => 'active']])
        ->and($history[1]->supersedes_decision_id)->toBe($history[0]->id)
        ->and(Point::query()->find($this->points[3])->isAnnotatedByHr())->toBeFalse()
        ->and(Point::query()->find($this->points[4])->isAnnotatedByHr())->toBeFalse()
        ->and(AfdPointDecision::activeLabels([$this->points[4]]))->toBe([]);

    $this->annotations->apply(($this->approved)([($this->row)(4, 'disregard', [...$this->evidence, 'decided_at' => '2026-10-24T10:00:00-03:00'])]), $this->path, $this->apply);

    expect(AfdPointDecision::query()->where('status', 'active')->sole()->only(['decision', 'supersedes_decision_id']))->toBe(['decision' => 'disregard', 'supersedes_decision_id' => $history[1]->id]);
});

test('an annotation is refused and nothing is written', function (Closure $manifest, string $message) {
    $path = $manifest($this);
    $before = ($this->tables)();

    expect(fn () => $this->annotations->apply($path, $this->path, $this->apply))->toThrow(DomainException::class, $message)
        ->and(($this->tables)())->toBe($before);
})->with([
    'unknown annotation' => [fn ($test) => ($test->approved)([($test->row)(6, 'delete')]), 'NSR 6: anotação inválida (delete)'],
    'line of another NSR' => [fn ($test) => ($test->approved)([[...($test->row)(6, 'disregard', $test->evidence), 'afd_line' => 6]]), 'NSR 6: linha do AFD ausente ou com SHA-256 diferente'],
    'point of another NSR' => [fn ($test) => ($test->approved)([[...($test->row)(6, 'disregard', $test->evidence), 'point_id' => $test->points[5]]]), 'NSR 6: o ponto não é a marcação importada deste REP e NSR'],
    'point changed after the import' => [function ($test) {
        DB::table('points')->where('id', $test->points[6])->update(['time' => '08:05:00']);

        return ($test->approved)([($test->row)(6, 'disregard', $test->evidence)]);
    }, 'NSR 6: o ponto não confere com a linha do AFD'],
    'missing point' => [fn ($test) => ($test->write)([[...($test->row)(6, 'disregard', $test->evidence), 'point_id' => 999999, 'expected_point_hash' => str_repeat('0', 64)]]), 'NSR 6: ponto inexistente'],
    'historical pending' => [function ($test) {
        AfdHistoricalNsr::factory()->create(['rep_serial' => AfdFileBuilder::REP_SERIAL, 'nsr' => 6, 'afd_line' => 7, 'afd_line_sha256' => hash('sha256', $test->lines[7])]);

        return ($test->approved)([($test->row)(6, 'disregard', $test->evidence)]);
    }, 'NSR 6: NSR registrado como pendência histórica: use afd:decisions'],
    'disregard without evidence' => [fn ($test) => ($test->approved)([($test->row)(5, 'disregard')]), 'NSR 5: evidência documental obrigatória'],
    'without HR data' => [fn ($test) => ($test->approved)([($test->row)(5, 'disregard', [...$test->evidence, 'decided_by' => ''])]), 'NSR 5: sem responsável, justificativa ou data da decisão do RH'],
    'future date' => [fn ($test) => ($test->approved)([($test->row)(5, 'disregard', [...$test->evidence, 'decided_at' => now()->addDay()->toIso8601String()])]), 'NSR 5: data da decisão do RH inválida ou futura'],
    'duplicate without the original' => [fn ($test) => ($test->approved)([($test->duplicate)(['duplicate_of_point_id' => null])]), 'NSR 4: informe o ponto original'],
    'duplicate of itself' => [fn ($test) => ($test->approved)([($test->duplicate)(['duplicate_of_point_id' => $test->points[4]])]), 'NSR 4: o ponto original não pode ser o próprio ponto'],
    'duplicate of another minute' => [fn ($test) => ($test->approved)([($test->duplicate)(['duplicate_of_point_id' => $test->points[6]])]), 'NSR 4: o ponto original não é do mesmo funcionário, data e minuto'],
    'original also a duplicate in the manifest' => [fn ($test) => ($test->approved)([($test->duplicate)(), ($test->row)(3, 'duplicate', ['duplicate_of_point_id' => $test->points[4]])]), 'o ponto original também está anotado como duplicada'],
    'original of a recorded duplicate' => [function ($test) {
        $test->annotations->apply(($test->approved)([($test->duplicate)()]), $test->path, $test->apply);

        return ($test->approved)([($test->row)(3, 'duplicate', ['duplicate_of_point_id' => $test->points[4]])]);
    }, 'NSR 3: o ponto original também está anotado como duplicada'],
    'original field on disregard' => [fn ($test) => ($test->approved)([($test->row)(5, 'disregard', [...$test->evidence, 'duplicate_of_point_id' => $test->points[3]])]), 'NSR 5: duplicate_of_point_id só se aplica à anotação duplicate'],
    'already annotated' => [function ($test) {
        $test->annotations->apply(($test->approved)([($test->row)(5, 'disregard', $test->evidence)]), $test->path, $test->apply);

        return ($test->approved)([($test->row)(5, 'disregard', [...$test->evidence, 'justification' => 'De novo.'])]);
    }, 'NSR 5: o ponto já tem anotação ativa (disregard)'],
    'nothing to revoke' => [fn ($test) => ($test->approved)([($test->row)(5, 'revoke')]), 'NSR 5: não há anotação ativa revogável para o ponto'],
    'revocation dated before the annotation' => [function ($test) {
        $test->annotations->apply(($test->approved)([($test->row)(5, 'disregard', $test->evidence)]), $test->path, $test->apply);

        return ($test->approved)([($test->row)(5, 'revoke', ['decided_at' => '2026-10-01T10:00:00-03:00'])]);
    }, 'NSR 5: data da revogação anterior à da anotação revogada'],
    'repeated point' => [fn ($test) => ($test->approved)([($test->row)(5, 'disregard', $test->evidence), ($test->row)(5, 'disregard', $test->evidence)]), 'NSR 5: ponto repetido no manifesto'],
    'without the approved point hash' => [fn ($test) => ($test->write)([($test->row)(5, 'disregard', $test->evidence)]), 'linha 1: sem expected_point_hash da simulação aprovada'],
    'point changed after the simulation' => [function ($test) {
        $path = ($test->approved)([($test->row)(5, 'disregard', $test->evidence)]);
        DB::table('points')->where('id', $test->points[5])->update(['updated_at' => now()->addHour()]);

        return $path;
    }, 'NSR 5: ponto diferente do aprovado na simulação (o estado mudou)'],
]);

test('the operator and the expected target database are required', function (array $apply, string $message) {
    expect(fn () => $this->annotations->apply(($this->approved)([($this->row)(5, 'disregard', $this->evidence)]), $this->path, $apply))->toThrow(DomainException::class, $message)
        ->and(AfdPointDecision::query()->count())->toBe(0);
})->with([
    'no operator' => [['expected' => []], 'Informe o responsável pela operação'],
    'another database' => [['operator' => 'ensaio', 'expected' => ['database' => 'laravel']], 'Banco de destino diferente do esperado'],
]);

test('a failure in the middle of the batch rolls everything back', function () {
    $manifest = ($this->approved)([($this->duplicate)(), ($this->row)(5, 'disregard', $this->evidence)]);
    $before = ($this->tables)();
    $inserts = 0;
    DB::listen(function (QueryExecuted $query) use (&$inserts) {
        if (str_starts_with($query->sql, 'insert into `afd_point_decisions`') && ++$inserts === 2) {
            throw new RuntimeException('falha simulada');
        }
    });

    expect(fn () => $this->annotations->apply($manifest, $this->path, $this->apply))->toThrow(RuntimeException::class, 'falha simulada')
        ->and(($this->tables)())->toBe($before);
});

test('the same manifest applied again is already applied', function () {
    $manifest = ($this->approved)([($this->row)(5, 'disregard', $this->evidence)]);
    $first = $this->annotations->apply($manifest, $this->path, $this->apply);
    $after = ($this->tables)();

    expect($this->annotations->apply($manifest, $this->path, $this->apply))->toMatchArray(['status' => 'already_applied', 'batch_id' => $first['batch_id']])
        ->and(($this->tables)())->toBe($after);
});

test('the command simulates and records the approved manifest', function () {
    $draft = ($this->write)([($this->row)(5, 'disregard', $this->evidence)]);
    $identity = ['--expect-host' => $this->apply['expected']['hostname'], '--expect-server-uuid' => $this->apply['expected']['server_uuid'], '--expect-database' => $this->apply['expected']['database']];

    expect(Artisan::call('afd:point-annotations', ['action' => 'preview', '--manifest' => $draft, '--afd' => $this->path]))->toBe(0)
        ->and(Artisan::output())->toContain('"status": "valid"')
        ->and(Artisan::call('afd:point-annotations', ['action' => 'apply', '--manifest' => $draft, '--afd' => $this->path, '--operator' => 'ensaio', ...$identity]))->toBe(1)
        ->and(Artisan::output())->toContain('sem expected_point_hash')
        ->and(Artisan::call('afd:point-annotations', ['action' => 'apply', '--manifest' => ($this->approved)([($this->row)(5, 'disregard', $this->evidence)]), '--afd' => $this->path, '--operator' => 'ensaio', ...$identity]))->toBe(0)
        ->and(Artisan::call('afd:point-annotations', ['action' => 'delete']))->toBe(1)
        ->and(Artisan::output())->toContain('Ação desconhecida: use preview ou apply.')
        ->and(AfdPointDecision::query()->count())->toBe(1);
});

test('without the migration the mirror, the points screen protection and the precheck keep working', function () {
    Schema::rename('afd_point_decisions', 'afd_point_decisions_ausente');
    Once::flush();

    try {
        $day = app(TimesheetMirror::class)->build(Point::query()->where('pis', 12345678901)->whereDate('date', '2026-10-02')->get(), CarbonImmutable::parse('2026-10-02'), CarbonImmutable::parse('2026-10-02'))['days'][0];

        expect(AfdPointDecision::isAvailable())->toBeFalse()
            ->and($day['hr_annotations'])->toBe([])
            ->and(Point::query()->find($this->points[6])->isAnnotatedByHr())->toBeFalse()
            ->and(AfdPointDecision::activeCounts())->toBe([])
            ->and(app(AfdPrecheck::class)->run($this->path)['warnings'])->each->not->toContain('anotação do RH');
    } finally {
        Schema::rename('afd_point_decisions_ausente', 'afd_point_decisions');
        Once::flush();
    }
});

describe('production', function () {
    test('applying annotations in production requires --production and maintenance', function (bool $authorized, bool $down, string $message) {
        $manifest = ($this->approved)([($this->row)(5, 'disregard', $this->evidence)]);
        ProductionMode::enter($down);

        expect(fn () => $this->annotations->apply($manifest, $this->path, [...$this->apply, 'production' => $authorized]))->toThrow(DomainException::class, $message)
            ->and(AfdPointDecision::query()->count())->toBe(0);
    })->with([
        'no authorization, in maintenance' => [false, true, 'sem autorização explícita (--production)'],
        'no authorization, online' => [false, false, 'sem autorização explícita (--production)'],
        'authorized, online' => [true, false, 'exige a aplicação em manutenção (php artisan down)'],
    ]);

    test('the command forwards --production and records in maintenance', function () {
        $manifest = ($this->approved)([($this->row)(5, 'disregard', $this->evidence)]);
        $identity = ['--expect-host' => $this->apply['expected']['hostname'], '--expect-server-uuid' => $this->apply['expected']['server_uuid'], '--expect-database' => $this->apply['expected']['database']];
        ProductionMode::enter(true);

        expect(Artisan::call('afd:point-annotations', ['action' => 'preview', '--manifest' => $manifest, '--afd' => $this->path]))->toBe(0)
            ->and(Artisan::call('afd:point-annotations', ['action' => 'apply', '--manifest' => $manifest, '--afd' => $this->path, '--operator' => 'ensaio', ...$identity]))->toBe(1)
            ->and(Artisan::output())->toContain('sem autorização explícita (--production)')
            ->and(Artisan::call('afd:point-annotations', ['action' => 'apply', '--manifest' => $manifest, '--afd' => $this->path, '--operator' => 'ensaio', '--production' => true, ...$identity]))->toBe(0)
            ->and(AfdPointDecision::query()->count())->toBe(1);
    });
});
