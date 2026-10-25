<?php

use App\Models\AfdCorrectionBatch;
use App\Models\AfdHistoricalNsr;
use App\Models\AfdHistoricalNsrDecision;
use App\Models\AfdRecoveredPoint;
use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\Point;
use App\Services\AfdHistoricalCorrection;
use App\Services\AfdHistoricalDecisions;
use App\Services\AfdPrecheck;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AfdFileBuilder;
use Tests\Support\LegacyAfdScenario;

/**
 * Revogação de decisões do RH (F5): `retain_manual` e `discard` voltam a `pending` sem
 * mudar `points`; `recover` apaga só o ponto recuperado, inalterado, fora de competência
 * fechada e sem ajuste confirmado no dia. Nada é apagado do histórico de decisões. A
 * simulação usa a sessão somente leitura do MySQL, que só vale fora de uma transação; por
 * isso este arquivo usa DatabaseTruncation.
 *
 * Cenário: o mesmo da F4 (AfdHistoricalDecisionsRecoverTest), com NSR 3 (E1, manual no
 * mesmo minuto), NSR 4 (C1, sábado 2026-09-05) e NSR 7 (C1, terça 2026-09-08).
 */
uses(DatabaseTruncation::class);

beforeEach(function () {
    expect(DB::connection()->getDatabaseName())->toStartWith('testing');

    $this->scenario = LegacyAfdScenario::build();
    $this->decisions = app(AfdHistoricalDecisions::class);
    $this->correction = app(AfdHistoricalCorrection::class);
    $this->employee = Employee::factory()->create(['pis' => '12345678901']);
    $this->manual = Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-04', 'time' => '08:03:00']);

    $classes = [3 => 'E1', 4 => 'C1', 5 => 'E2b', 6 => 'C1', 7 => 'C1', 8 => 'C1', 15 => 'C1'];
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

    $target = $this->correction->target();
    $this->apply = ['operator' => 'ensaio', 'expected' => ['hostname' => $target['hostname'], 'server_uuid' => $target['server_uuid'], 'database' => $target['database']]];
    $this->evidence = ['evidence_type' => 'termo', 'evidence_reference' => 'RH-2026-010'];

    $this->row = fn (int $nsr, string $decision, array $extra = []): array => [
        'nsr' => $nsr,
        'afd_line' => $nsr + 1,
        'afd_line_sha256' => hash('sha256', $this->scenario->lines()[$nsr + 1]),
        'decision' => $decision,
        'decided_by' => 'Responsável do RH',
        'decided_at' => $decision === 'revoke' ? '2026-10-10T10:00:00-03:00' : '2026-10-01T10:00:00-03:00',
        'justification' => 'Batida confirmada pelo funcionário e pelo gestor.',
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
     * Manifesto aprovado: hash da consequência e avisos mostrados pela simulação.
     */
    $this->approved = function (array $rows, bool $acknowledge = true): string {
        $preview = $this->decisions->preview(($this->write)($rows), $this->scenario->path);

        $path = ($this->write)(array_map(fn (array $row, array $result) => [
            ...$row,
            'expected_consequence_sha256' => $result['consequence_sha256'],
            ...($acknowledge ? ['acknowledged_warnings' => $result['warnings']] : []),
        ], $rows, $preview['results']));

        return approveDecisionManifest($path);
    };

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

test('revoking a kept manual point returns the pending to open, without touching points', function () {
    $this->decisions->apply(($this->approved)([($this->row)(3, 'retain_manual', ['related_manual_point_id' => $this->manual->id])]), $this->scenario->path, $this->apply);
    $retained = AfdHistoricalNsrDecision::query()->sole();
    $points = Point::query()->orderBy('id')->get()->toArray();

    $result = $this->decisions->apply(($this->approved)([($this->row)(3, 'revoke')]), $this->scenario->path, $this->apply);
    $revoke = AfdHistoricalNsrDecision::query()->where('decision', 'revoke')->sole();

    expect($result)->toMatchArray(['status' => 'applied', 'rows' => 1, 'recovered_point_ids' => [], 'removed_point_ids' => []])
        ->and($retained->fresh()->status)->toBe('superseded')
        ->and($revoke->only(['status_before', 'status_after', 'supersedes_decision_id', 'related_manual_point_id', 'recovered_point_id', 'status']))->toBe(['status_before' => 'manual_retained', 'status_after' => 'pending', 'supersedes_decision_id' => $retained->id, 'related_manual_point_id' => null, 'recovered_point_id' => null, 'status' => 'active'])
        ->and($revoke->expected_consequence)->toMatchArray(['effect_on_points' => 'none', 'revoked_decision_id' => $retained->id])
        ->and(AfdHistoricalNsr::query()->where('nsr', 3)->sole()->only(['status', 'related_manual_point_id', 'recovered_point_id', 'decided_by', 'decision_justification', 'decided_at']))->toBe(['status' => 'pending', 'related_manual_point_id' => $this->manual->id, 'recovered_point_id' => null, 'decided_by' => null, 'decision_justification' => null, 'decided_at' => null])
        ->and(Point::query()->orderBy('id')->get()->toArray())->toBe($points)
        ->and($this->manual->fresh()->isRetainedByHrDecision())->toBeFalse();
});

test('revoking a kept manual point restores the manual point related by the audit', function () {
    $other = Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-04', 'time' => '12:00:00']);
    $this->decisions->apply(($this->approved)([($this->row)(3, 'retain_manual', ['related_manual_point_id' => $other->id])]), $this->scenario->path, $this->apply);

    expect(AfdHistoricalNsr::query()->where('nsr', 3)->value('related_manual_point_id'))->toBe($other->id);

    $this->decisions->apply(($this->approved)([($this->row)(3, 'revoke')]), $this->scenario->path, $this->apply);

    expect(AfdHistoricalNsr::query()->where('nsr', 3)->value('related_manual_point_id'))->toBe($this->manual->id)
        ->and($other->fresh()->delete())->toBeTrue();
});

test('revoking a discard returns the pending to open and the precheck covers it as pending', function () {
    $this->decisions->apply(($this->approved)([($this->row)(4, 'discard', $this->evidence)]), $this->scenario->path, $this->apply);

    $this->decisions->apply(($this->approved)([($this->row)(4, 'revoke')]), $this->scenario->path, $this->apply);
    $coverage = app(AfdPrecheck::class)->run($this->scenario->path)['coverage'];

    expect(AfdHistoricalNsr::query()->where('nsr', 4)->value('status'))->toBe('pending')
        ->and($coverage['inconsistencies'])->toBe([])
        ->and($coverage['nsrs']['pending'])->toBe([3, 4, 5, 6, 7, 8, 15])
        ->and($coverage['counts']['gap'])->toBe(0);
});

test('revoking a recovery deletes only that point and marks the link as reverted', function () {
    $recovered = $this->decisions->apply(($this->approved)([($this->row)(4, 'recover', $this->evidence)]), $this->scenario->path, $this->apply);
    $pointId = $recovered['recovered_point_ids'][0];
    $others = Point::query()->whereKeyNot($pointId)->orderBy('id')->get()->toArray();

    $result = $this->decisions->apply(($this->approved)([($this->row)(4, 'revoke')]), $this->scenario->path, $this->apply);
    $coverage = app(AfdPrecheck::class)->run($this->scenario->path)['coverage'];

    expect($result)->toMatchArray(['status' => 'applied', 'removed_point_ids' => [$pointId]])
        ->and(Point::query()->find($pointId))->toBeNull()
        ->and(Point::query()->orderBy('id')->get()->toArray())->toBe($others)
        ->and(AfdRecoveredPoint::query()->sole()->only(['point_id', 'status', 'reverted_by']))->toBe(['point_id' => $pointId, 'status' => 'reverted', 'reverted_by' => 'ensaio'])
        ->and(AfdHistoricalNsrDecision::query()->where('decision', 'recover')->sole()->only(['status', 'recovered_point_id']))->toBe(['status' => 'superseded', 'recovered_point_id' => $pointId])
        ->and(AfdHistoricalNsrDecision::query()->where('decision', 'revoke')->sole()->expected_consequence)->toMatchArray(['effect_on_points' => 'remove_point'])
        ->and(AfdHistoricalNsr::query()->where('nsr', 4)->sole()->only(['status', 'recovered_point_id', 'decided_by']))->toBe(['status' => 'pending', 'recovered_point_id' => null, 'decided_by' => null])
        ->and($coverage['inconsistencies'])->toBe([])
        ->and($coverage['nsrs']['recovered'])->toBe([])
        ->and($coverage['counts']['gap'])->toBe(0);
});

test('after a revocation the pending can be decided again and the history keeps every decision', function () {
    $this->decisions->apply(($this->approved)([($this->row)(4, 'recover', $this->evidence)]), $this->scenario->path, $this->apply);
    $this->decisions->apply(($this->approved)([($this->row)(4, 'revoke')]), $this->scenario->path, $this->apply);

    $this->decisions->apply(($this->approved)([($this->row)(4, 'recover', [...$this->evidence, 'decided_at' => '2026-10-15T10:00:00-03:00'])]), $this->scenario->path, $this->apply);
    $history = AfdHistoricalNsrDecision::query()->orderBy('id')->get();

    expect($history->map->only(['decision', 'status'])->all())->toBe([
        ['decision' => 'recover', 'status' => 'superseded'],
        ['decision' => 'revoke', 'status' => 'superseded'],
        ['decision' => 'recover', 'status' => 'active'],
    ])
        ->and($history->pluck('supersedes_decision_id')->all())->toBe([null, $history[0]->id, $history[1]->id])
        ->and(AfdRecoveredPoint::query()->orderBy('id')->pluck('status')->all())->toBe(['reverted', 'recovered'])
        ->and(Point::query()->where('nsr', 4)->count())->toBe(1)
        ->and(AfdHistoricalNsr::query()->where('nsr', 4)->value('status'))->toBe('recovered');
});

test('the first manifest applied again after its revocation is already applied, not decided again', function () {
    $recover = ($this->approved)([($this->row)(4, 'recover', $this->evidence)]);
    $first = $this->decisions->apply($recover, $this->scenario->path, $this->apply);
    $this->decisions->apply(($this->approved)([($this->row)(4, 'revoke')]), $this->scenario->path, $this->apply);
    $after = ($this->tables)();

    expect($this->decisions->apply($recover, $this->scenario->path, $this->apply))->toMatchArray(['status' => 'already_applied', 'batch_id' => $first['batch_id']])
        ->and(($this->tables)())->toBe($after)
        ->and(AfdHistoricalNsr::query()->where('nsr', 4)->value('status'))->toBe('pending');
});

test('a revocation mixed with other decisions is applied in the same batch', function () {
    $this->decisions->apply(($this->approved)([($this->row)(4, 'recover', $this->evidence)]), $this->scenario->path, $this->apply);

    $result = $this->decisions->apply(($this->approved)([
        ($this->row)(4, 'revoke'),
        ($this->row)(7, 'recover', $this->evidence),
        ($this->row)(3, 'retain_manual', ['related_manual_point_id' => $this->manual->id]),
    ]), $this->scenario->path, $this->apply);

    expect($result['by_decision'])->toBe(['recover' => 1, 'retain_manual' => 1, 'revoke' => 1])
        ->and($result['recovered_point_ids'])->toHaveCount(1)
        ->and($result['removed_point_ids'])->toHaveCount(1)
        ->and(Point::query()->whereNotNull('nsr')->pluck('nsr')->all())->toBe([7])
        ->and(AfdHistoricalNsr::query()->whereIn('nsr', [3, 4, 7])->orderBy('nsr')->pluck('status')->all())->toBe(['manual_retained', 'pending', 'recovered']);
});

test('a revocation is refused and nothing is written', function (Closure $manifest, string $message) {
    $path = $manifest($this);
    $before = ($this->tables)();

    expect(fn () => $this->decisions->apply($path, $this->scenario->path, $this->apply))->toThrow(DomainException::class, $message)
        ->and(($this->tables)())->toBe($before);
})->with([
    'nothing to revoke' => [fn ($test) => ($test->approved)([($test->row)(4, 'revoke')]), 'NSR 4: não há decisão ativa revogável para a pendência'],
    'a deferral' => [function ($test) {
        $test->decisions->apply(($test->approved)([($test->row)(4, 'defer')]), $test->scenario->path, $test->apply);

        return ($test->approved)([($test->row)(4, 'revoke')]);
    }, 'NSR 4: não há decisão ativa revogável para a pendência'],
    'a recovery by the correction batch' => [function ($test) {
        AfdHistoricalNsr::query()->where('nsr', 8)->update(['classification' => 'C2', 'status' => 'recovered']);

        return ($test->approved)([($test->row)(8, 'revoke')]);
    }, 'NSR 8: recuperação feita por lote de correção: só é desfeita por afd:correction revert'],
    'a date before the revoked decision' => [function ($test) {
        $test->decisions->apply(($test->approved)([($test->row)(4, 'discard', $test->evidence)]), $test->scenario->path, $test->apply);

        return ($test->approved)([($test->row)(4, 'revoke', ['decided_at' => '2026-09-30T10:00:00-03:00'])]);
    }, 'NSR 4: data da revogação anterior à da decisão revogada'],
    'a recovered point changed' => [function ($test) {
        $test->decisions->apply(($test->approved)([($test->row)(4, 'recover', $test->evidence)]), $test->scenario->path, $test->apply);
        $path = ($test->approved)([($test->row)(4, 'revoke')]);
        DB::table('points')->where('nsr', 4)->update(['time' => '08:09:00']);

        return $path;
    }, 'NSR 4: ponto recuperado ausente ou alterado depois da recuperação'],
    'a recovered point deleted' => [function ($test) {
        $test->decisions->apply(($test->approved)([($test->row)(4, 'recover', $test->evidence)]), $test->scenario->path, $test->apply);
        DB::table('points')->where('nsr', 4)->delete();

        return ($test->approved)([($test->row)(4, 'revoke')]);
    }, 'NSR 4: ponto recuperado ausente ou alterado depois da recuperação'],
    'a closed competence' => [function ($test) {
        $period = BenefitPeriod::factory()->forCompetence('2026-10')->calculated()->create();
        $test->decisions->apply(($test->approved)([($test->row)(7, 'recover', $test->evidence)]), $test->scenario->path, $test->apply);
        $period->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        return ($test->approved)([($test->row)(7, 'revoke')]);
    }, 'NSR 7: dia em competência de benefício fechada (2026-10)'],
    'a confirmed adjustment on the day' => [function ($test) {
        $period = BenefitPeriod::factory()->forCompetence('2026-10')->calculated()->create();
        $test->decisions->apply(($test->approved)([($test->row)(7, 'recover', $test->evidence)]), $test->scenario->path, $test->apply);
        BenefitAdjustment::factory()->confirmed()->create(['benefit_period_id' => $period->id, 'employee_id' => $test->employee->id, 'starts_on' => '2026-09-08', 'ends_on' => '2026-09-08']);

        return ($test->approved)([($test->row)(7, 'revoke')]);
    }, 'NSR 7: há ajuste de benefício confirmado no dia'],
    'another decision revoked after the approval' => [function ($test) {
        $test->decisions->apply(($test->approved)([($test->row)(4, 'discard', $test->evidence)]), $test->scenario->path, $test->apply);
        $path = ($test->approved)([($test->row)(4, 'revoke')]);
        $test->decisions->apply(($test->approved)([($test->row)(4, 'revoke', ['justification' => 'Revogação anterior.'])]), $test->scenario->path, $test->apply);
        $test->decisions->apply(($test->approved)([($test->row)(4, 'discard', [...$test->evidence, 'justification' => 'Novo descarte.'])]), $test->scenario->path, $test->apply);

        return $path;
    }, 'NSR 4: consequência prevista diferente da aprovada na simulação (o estado mudou)'],
]);

test('a failure after the point is deleted rolls everything back', function () {
    $this->decisions->apply(($this->approved)([($this->row)(4, 'recover', $this->evidence)]), $this->scenario->path, $this->apply);
    $manifest = ($this->approved)([($this->row)(4, 'revoke')]);
    $before = ($this->tables)();
    DB::listen(function (QueryExecuted $query) {
        if (str_starts_with($query->sql, 'update `afd_recovered_points`')) {
            throw new RuntimeException('falha simulada');
        }
    });

    expect(fn () => $this->decisions->apply($manifest, $this->scenario->path, $this->apply))->toThrow(RuntimeException::class, 'falha simulada')
        ->and(($this->tables)())->toBe($before);
});

test('the same revocation manifest applied again is already applied', function () {
    $this->decisions->apply(($this->approved)([($this->row)(4, 'recover', $this->evidence)]), $this->scenario->path, $this->apply);
    $manifest = ($this->approved)([($this->row)(4, 'revoke')]);
    $first = $this->decisions->apply($manifest, $this->scenario->path, $this->apply);
    $after = ($this->tables)();

    expect($this->decisions->apply($manifest, $this->scenario->path, $this->apply))->toMatchArray(['status' => 'already_applied', 'batch_id' => $first['batch_id']])
        ->and(($this->tables)())->toBe($after);
});

test('the command records an approved revocation', function () {
    $this->decisions->apply(($this->approved)([($this->row)(4, 'discard', $this->evidence)]), $this->scenario->path, $this->apply);
    $manifest = ($this->approved)([($this->row)(4, 'revoke')]);

    $code = Artisan::call('afd:decisions', [
        'action' => 'apply',
        '--manifest' => $manifest,
        '--afd' => $this->scenario->path,
        '--operator' => 'ensaio',
        '--expect-host' => $this->apply['expected']['hostname'],
        '--expect-server-uuid' => $this->apply['expected']['server_uuid'],
        '--expect-database' => $this->apply['expected']['database'],
    ]);

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('"status": "applied"')
        ->and(AfdHistoricalNsr::query()->where('nsr', 4)->value('status'))->toBe('pending');
});
