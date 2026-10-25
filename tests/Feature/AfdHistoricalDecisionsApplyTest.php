<?php

use App\Models\AfdCorrectionBatch;
use App\Models\AfdHistoricalNsr;
use App\Models\AfdHistoricalNsrDecision;
use App\Models\Employee;
use App\Models\Point;
use App\Services\AfdHistoricalCorrection;
use App\Services\AfdHistoricalDecisions;
use App\Services\AfdPrecheck;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AfdFileBuilder;
use Tests\Support\LegacyAfdScenario;

/**
 * Gravação das decisões do RH (F2: retain_manual, discard, defer; a recuperação, F4, tem
 * arquivo próprio). A simulação usa a
 * sessão somente leitura do MySQL, que só vale fora de uma transação; por isso este
 * arquivo usa DatabaseTruncation.
 *
 * Cenário: LegacyAfdScenario com as 7 marcações ausentes registradas como pendências:
 * NSR 3 (E2a, com o ponto manual de 2026-09-04 08:05), NSR 4 (C1), NSR 5 (E2b) e
 * NSR 6, 7, 8 e 15 (C1).
 */
uses(DatabaseTruncation::class);

beforeEach(function () {
    expect(DB::connection()->getDatabaseName())->toStartWith('testing');

    $this->scenario = LegacyAfdScenario::build();
    $this->decisions = app(AfdHistoricalDecisions::class);
    Employee::factory()->create(['pis' => '12345678901']);
    $this->manual = Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-04', 'time' => '08:05:00']);

    $classes = [3 => 'E2a', 4 => 'C1', 5 => 'E2b', 6 => 'C1', 7 => 'C1', 8 => 'C1', 15 => 'C1'];
    $registration = AfdCorrectionBatch::factory()->create(['rep_serial' => AfdFileBuilder::REP_SERIAL, 'expected_count' => 7, 'applied_count' => 7]);

    foreach ($classes as $nsr => $classification) {
        AfdHistoricalNsr::factory()->create([
            'afd_correction_batch_id' => $registration->id,
            'rep_serial' => AfdFileBuilder::REP_SERIAL,
            'nsr' => $nsr,
            'afd_line' => $nsr + 1,
            'afd_line_sha256' => hash('sha256', $this->scenario->lines()[$nsr + 1]),
            'classification' => $classification,
            'related_manual_point_id' => $nsr === 3 ? $this->manual->id : null,
        ]);
    }

    $target = app(AfdHistoricalCorrection::class)->target();
    $this->identity = ['--expect-host' => $target['hostname'], '--expect-server-uuid' => $target['server_uuid'], '--expect-database' => $target['database']];
    $this->apply = ['operator' => 'ensaio', 'expected' => ['hostname' => $target['hostname'], 'server_uuid' => $target['server_uuid'], 'database' => $target['database']]];

    $this->row = fn (int $nsr, string $decision, array $extra = []): array => [
        'nsr' => $nsr,
        'afd_line' => $nsr + 1,
        'afd_line_sha256' => hash('sha256', $this->scenario->lines()[$nsr + 1]),
        'decision' => $decision,
        'decided_by' => 'Responsável do RH',
        'decided_role' => 'Analista de RH',
        'decided_at' => '2026-10-01T10:00:00-03:00',
        'justification' => 'Análise do RH.',
        ...$extra,
    ];

    $this->write = fn (array $rows): string => LegacyAfdScenario::write([
        'kind' => 'decision',
        'afd_sha256' => hash_file('sha256', $this->scenario->path),
        'rep_serial' => AfdFileBuilder::REP_SERIAL,
        'expected_count' => count($rows),
        'rows' => $rows,
    ]);

    /**
     * Manifesto aprovado: cada linha leva o hash da consequência mostrada pela simulação.
     */
    $this->approved = function (array $rows): string {
        $preview = $this->decisions->preview(($this->write)($rows), $this->scenario->path);

        return ($this->write)(array_map(fn (array $row, array $result) => [...$row, 'expected_consequence_sha256' => $result['consequence_sha256']], $rows, $preview['results']));
    };

    $this->evidence = ['evidence_type' => 'memorando', 'evidence_reference' => 'RH-2026-001', 'evidence_sha256' => hash('sha256', 'documento')];
    $this->standard = fn (): array => [
        ($this->row)(3, 'retain_manual', ['related_manual_point_id' => $this->manual->id]),
        ($this->row)(4, 'discard', $this->evidence),
        ($this->row)(5, 'defer', ['review_due_on' => '2026-11-30', 'review_notes' => 'Consultar o gestor.']),
    ];

    $this->tables = fn (array $except = []): array => collect(Schema::getTableListing(schemaQualified: false))
        ->reject(fn (string $table) => in_array($table, $except, true))
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->get()->map(fn ($row) => (array) $row)->sortBy(fn (array $row) => json_encode($row))->values()->all()])
        ->all();
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

test('retain_manual, discard and defer are recorded in their own batch without touching points', function () {
    $manifest = ($this->approved)(($this->standard)());
    $untouched = ($this->tables)(['afd_correction_batches', 'afd_historical_nsrs', 'afd_historical_nsr_decisions']);
    $batchesBefore = AfdCorrectionBatch::query()->orderBy('id')->get()->toArray();
    $othersBefore = AfdHistoricalNsr::query()->whereNotIn('nsr', [3, 4])->orderBy('id')->get()->toArray();

    $result = $this->decisions->apply($manifest, $this->scenario->path, $this->apply);
    $batch = AfdCorrectionBatch::query()->where('kind', AfdCorrectionBatch::KIND_DECISION)->sole();
    $decisions = AfdHistoricalNsrDecision::query()->with('historicalNsr')->orderBy('id')->get();

    expect($result)->toMatchArray(['mode' => 'apply', 'status' => 'applied', 'batch_id' => $batch->id, 'rows' => 3, 'by_decision' => ['defer' => 1, 'discard' => 1, 'retain_manual' => 1]])
        ->and($batch->only(['status', 'applied_by', 'expected_count', 'applied_count', 'rep_serial', 'manifest_sha256']))->toBe(['status' => 'applied', 'applied_by' => 'ensaio', 'expected_count' => 3, 'applied_count' => 3, 'rep_serial' => AfdFileBuilder::REP_SERIAL, 'manifest_sha256' => hash_file('sha256', $manifest)])
        ->and($decisions->map(fn (AfdHistoricalNsrDecision $decision) => [$decision->historicalNsr->nsr, $decision->decision, $decision->status_before, $decision->status_after, $decision->status])->all())->toBe([
            [3, 'retain_manual', 'pending', 'manual_retained', 'active'],
            [4, 'discard', 'pending', 'discarded_by_approval', 'active'],
            [5, 'defer', 'pending', 'pending', 'active'],
        ])
        ->and($decisions[0]->only(['related_manual_point_id', 'decided_by', 'decided_role', 'justification', 'afd_correction_batch_id']))->toBe(['related_manual_point_id' => $this->manual->id, 'decided_by' => 'Responsável do RH', 'decided_role' => 'Analista de RH', 'justification' => 'Análise do RH.', 'afd_correction_batch_id' => $batch->id])
        ->and($decisions[0]->decided_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-01 13:00:00')
        ->and($decisions[1]->only(['evidence_type', 'evidence_reference', 'evidence_sha256']))->toBe(['evidence_type' => 'memorando', 'evidence_reference' => 'RH-2026-001', 'evidence_sha256' => hash('sha256', 'documento')])
        ->and($decisions[2]->review_due_on->toDateString())->toBe('2026-11-30')
        ->and($decisions->every(fn (AfdHistoricalNsrDecision $decision) => $decision->expected_consequence_sha256 === AfdHistoricalDecisions::consequenceHash($decision->expected_consequence)))->toBeTrue()
        ->and(AfdHistoricalNsr::query()->where('nsr', 3)->sole()->only(['status', 'related_manual_point_id', 'decided_by', 'decision_justification']))->toBe(['status' => 'manual_retained', 'related_manual_point_id' => $this->manual->id, 'decided_by' => 'Responsável do RH', 'decision_justification' => 'Análise do RH.'])
        ->and(AfdHistoricalNsr::query()->where('nsr', 4)->sole()->only(['status', 'decided_by']))->toBe(['status' => 'discarded_by_approval', 'decided_by' => 'Responsável do RH'])
        ->and(AfdHistoricalNsr::query()->whereNotIn('nsr', [3, 4])->orderBy('id')->get()->toArray())->toBe($othersBefore)
        ->and(AfdCorrectionBatch::query()->whereKeyNot($batch->id)->orderBy('id')->get()->toArray())->toBe($batchesBefore)
        ->and(($this->tables)(['afd_correction_batches', 'afd_historical_nsrs', 'afd_historical_nsr_decisions']))->toBe($untouched);
});

test('after the decisions the coverage accepts them and the precheck stays without inconsistencies', function () {
    $this->decisions->apply(($this->approved)(($this->standard)()), $this->scenario->path, $this->apply);

    $report = app(AfdPrecheck::class)->run($this->scenario->path);

    expect($report['verdict'])->toBe(AfdPrecheck::VERDICT_READY)
        ->and($report['coverage']['inconsistencies'])->toBe([])
        ->and($report['coverage']['nsrs']['manual_retained'])->toBe([3])
        ->and($report['coverage']['nsrs']['discarded_by_approval'])->toBe([4])
        ->and($report['coverage']['nsrs']['pending'])->toBe([5, 6, 7, 8, 15])
        ->and($report['coverage']['counts']['gap'])->toBe(0);
});

test('a final decision after a deferral supersedes it and keeps the history', function () {
    $this->decisions->apply(($this->approved)([($this->row)(5, 'defer')]), $this->scenario->path, $this->apply);
    $deferral = AfdHistoricalNsrDecision::query()->sole();

    $this->decisions->apply(($this->approved)([($this->row)(5, 'discard', [...$this->evidence, 'decided_at' => '2026-10-02T10:00:00-03:00'])]), $this->scenario->path, $this->apply);
    $history = AfdHistoricalNsrDecision::query()->orderBy('id')->get();

    expect($history->map->only(['decision', 'status', 'supersedes_decision_id'])->all())->toBe([
        ['decision' => 'defer', 'status' => 'superseded', 'supersedes_decision_id' => null],
        ['decision' => 'discard', 'status' => 'active', 'supersedes_decision_id' => $deferral->id],
    ])
        ->and($history[0]->justification)->toBe($deferral->justification)
        ->and(AfdHistoricalNsr::query()->where('nsr', 5)->sole()->status)->toBe('discarded_by_approval')
        ->and(AfdCorrectionBatch::query()->where('kind', 'decision')->count())->toBe(2);
});

test('applying the same manifest again changes nothing', function () {
    $manifest = ($this->approved)(($this->standard)());
    $first = $this->decisions->apply($manifest, $this->scenario->path, $this->apply);
    $after = ($this->tables)();

    expect($this->decisions->apply($manifest, $this->scenario->path, $this->apply))->toMatchArray(['status' => 'already_applied', 'batch_id' => $first['batch_id'], 'rows' => 3])
        ->and(($this->tables)())->toBe($after);
});

test('the whole manifest is refused and nothing is written', function (Closure $manifest, string $message) {
    $path = $manifest($this);
    $before = ($this->tables)();

    expect(fn () => $this->decisions->apply($path, $this->scenario->path, $this->apply))->toThrow(DomainException::class, $message)
        ->and(($this->tables)())->toBe($before);
})->with([
    'a recovery with an unacknowledged warning' => [fn ($test) => ($test->approved)([($test->row)(7, 'recover', $test->evidence), ...($test->standard)()]), 'NSR 7: aviso não confirmado day_becomes_odd'],
    'a revocation without a revocable decision' => [fn ($test) => ($test->approved)([($test->row)(4, 'revoke')]), 'NSR 4: não há decisão ativa revogável para a pendência'],
    'without the approved consequence' => [fn ($test) => ($test->write)(($test->standard)()), 'linha 1: sem expected_consequence_sha256 da simulação aprovada'],
    'a row with an error' => [fn ($test) => ($test->approved)([...($test->standard)(), ($test->row)(6, 'retain_manual', ['related_manual_point_id' => $test->manual->id])]), 'NSR 6: decisão retain_manual não permitida para a classe C1'],
    'an unacknowledged warning' => [function ($test) {
        $other = Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-04', 'time' => '12:00:00']);

        return ($test->approved)([($test->row)(3, 'retain_manual', ['related_manual_point_id' => $other->id])]);
    }, 'NSR 3: aviso não confirmado manual_differs_from_audit'],
    'a state changed after the simulation' => [function ($test) {
        $path = ($test->approved)(($test->standard)());
        Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-05', 'time' => '12:00:00']);

        return $path;
    }, 'NSR 4: consequência prevista diferente da aprovada na simulação (o estado mudou)'],
]);

test('the operator and the expected target database are required', function (array $apply, string $message) {
    $manifest = ($this->approved)(($this->standard)());
    $before = ($this->tables)();

    expect(fn () => $this->decisions->apply($manifest, $this->scenario->path, [...$this->apply, ...$apply]))->toThrow(DomainException::class, $message)
        ->and(($this->tables)())->toBe($before);
})->with([
    'no operator' => [['operator' => ' '], 'Informe o responsável pela operação'],
    'another server uuid' => [['expected' => ['hostname' => 'x', 'server_uuid' => 'e81bb1f7-bcd2-11f1-acb3-4a6823fe9a73', 'database' => 'laravel']], 'Banco de destino diferente do esperado'],
]);

test('a failure in the middle of the batch rolls everything back', function () {
    $manifest = ($this->approved)(($this->standard)());
    $before = ($this->tables)();
    $inserts = 0;
    DB::listen(function (QueryExecuted $query) use (&$inserts) {
        if (str_starts_with($query->sql, 'insert into `afd_historical_nsr_decisions`') && ++$inserts === 2) {
            throw new RuntimeException('falha simulada');
        }
    });

    expect(fn () => $this->decisions->apply($manifest, $this->scenario->path, $this->apply))->toThrow(RuntimeException::class, 'falha simulada')
        ->and(($this->tables)())->toBe($before);
});

test('the commands simulate and record the approved manifest', function () {
    $manifest = ($this->approved)(($this->standard)());

    $this->artisan('afd:decisions', ['action' => 'preview', '--manifest' => $manifest, '--afd' => $this->scenario->path])
        ->expectsOutputToContain('"status": "valid"')
        ->assertSuccessful();

    $this->artisan('afd:decisions', ['action' => 'apply', '--manifest' => $manifest, '--afd' => $this->scenario->path, '--operator' => 'ensaio', ...$this->identity])
        ->expectsOutputToContain('"status": "applied"')
        ->assertSuccessful();

    $this->artisan('afd:decisions', ['action' => 'apply', '--manifest' => $manifest, '--afd' => $this->scenario->path, '--operator' => 'ensaio', ...$this->identity, '--expect-database' => 'outro'])
        ->expectsOutputToContain('Banco de destino diferente do esperado (database)')
        ->assertFailed();

    expect(AfdHistoricalNsrDecision::count())->toBe(3);
});

test('a kept manual point is protected by the application and flagged by the precheck', function () {
    $this->decisions->apply(($this->approved)(($this->standard)()), $this->scenario->path, $this->apply);

    expect(fn () => $this->manual->fresh()->delete())->toThrow(DomainException::class, Point::RETAINED_BY_HR_MESSAGE)
        ->and(fn () => $this->manual->fresh()->update(['time' => '09:00:00']))->toThrow(DomainException::class, Point::RETAINED_BY_HR_MESSAGE);

    $report = app(AfdPrecheck::class)->run($this->scenario->path);

    expect($report['verdict'])->toBe(AfdPrecheck::VERDICT_READY)
        ->and(implode(' ', $report['warnings']))->toContain('1 ponto(s) manual(is) mantido(s) por decisão do RH no lugar da marcação do AFD');
});

test('a direct deletion in the database bypasses the protection and blocks the precheck', function () {
    $this->decisions->apply(($this->approved)(($this->standard)()), $this->scenario->path, $this->apply);
    DB::table('points')->where('id', $this->manual->id)->delete();

    $report = app(AfdPrecheck::class)->run($this->scenario->path);

    expect($report['verdict'])->toBe(AfdPrecheck::VERDICT_BLOCKED)
        ->and($report['coverage']['inconsistencies'])->toBe(['NSR 3: manutenção de ponto manual inexistente.']);
});
