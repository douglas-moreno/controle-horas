<?php

use App\Enums\AdjustmentSource;
use App\Models\AfdCorrectionBatch;
use App\Models\AfdHistoricalNsr;
use App\Models\AfdHistoricalNsrDecision;
use App\Models\AfdRecoveredPoint;
use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriod;
use App\Models\BenefitPeriodEmployee;
use App\Models\Employee;
use App\Models\Point;
use App\Services\AfdHistoricalCorrection;
use App\Services\AfdHistoricalDecisions;
use App\Services\AfdPrecheck;
use App\Services\TimesheetAdjustmentSuggester;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AfdFileBuilder;
use Tests\Support\LegacyAfdScenario;

/**
 * Recuperação por decisão do RH (F4): classes que não são C2, com evidência, avisos
 * confirmados e consequência aprovada. A simulação usa a sessão somente leitura do MySQL,
 * que só vale fora de uma transação; por isso este arquivo usa DatabaseTruncation.
 *
 * Cenário: LegacyAfdScenario com as 7 marcações ausentes registradas: NSR 3 (E1, manual no
 * mesmo minuto, 2026-09-04 08:03), NSR 4 (C1, sábado 2026-09-05 08:04), NSR 5 (E2b) e
 * NSR 6, 7 (terça 2026-09-08 08:07), 8 e 15 (C1). Os dias já têm batidas legadas.
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
        'decided_at' => '2026-10-01T10:00:00-03:00',
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

        return ($this->write)(array_map(fn (array $row, array $result) => [
            ...$row,
            'expected_consequence_sha256' => $result['consequence_sha256'],
            ...($acknowledge ? ['acknowledged_warnings' => $result['warnings']] : []),
        ], $rows, $preview['results']));
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

test('a recovery decision stores the punch with REP and NSR, linked to the batch and to the decision', function () {
    $pointsBefore = Point::query()->orderBy('id')->get()->toArray();

    $result = $this->decisions->apply(($this->approved)([($this->row)(4, 'recover', $this->evidence)]), $this->scenario->path, $this->apply);
    $point = Point::query()->where('nsr', 4)->sole();
    $decision = AfdHistoricalNsrDecision::query()->sole();
    $recovered = AfdRecoveredPoint::query()->sole();
    $batch = AfdCorrectionBatch::query()->where('kind', AfdCorrectionBatch::KIND_DECISION)->sole();

    expect($result)->toMatchArray(['status' => 'applied', 'rows' => 1, 'recovered_point_ids' => [$point->id]])
        ->and(DB::table('points')->where('id', $point->id)->first(['pis', 'date', 'time', 'type', 'rep_serial', 'nsr', 'afd_import_id']))->toEqual((object) ['pis' => 12345678901, 'date' => '2026-09-05', 'time' => '08:04:00', 'type' => 'importado', 'rep_serial' => AfdFileBuilder::REP_SERIAL, 'nsr' => 4, 'afd_import_id' => null])
        ->and($decision->only(['decision', 'status_before', 'status_after', 'recovered_point_id', 'evidence_type', 'evidence_reference', 'status']))->toBe(['decision' => 'recover', 'status_before' => 'pending', 'status_after' => 'recovered', 'recovered_point_id' => $point->id, 'evidence_type' => 'termo', 'evidence_reference' => 'RH-2026-010', 'status' => 'active'])
        ->and($recovered->only(['point_id', 'nsr', 'afd_line', 'status']))->toBe(['point_id' => $point->id, 'nsr' => 4, 'afd_line' => 5, 'status' => 'recovered'])
        ->and(DB::table('afd_recovered_points')->value('afd_historical_nsr_decision_id'))->toBe($decision->id)
        ->and($recovered->batch->is($batch))->toBeTrue()
        ->and($recovered->point_hash)->toBe(LegacyAfdScenario::canonicalHash($point->id))
        ->and(AfdHistoricalNsr::query()->where('nsr', 4)->sole()->only(['status', 'recovered_point_id', 'decided_by']))->toBe(['status' => 'recovered', 'recovered_point_id' => $point->id, 'decided_by' => 'Responsável do RH'])
        ->and(Point::query()->whereKeyNot($point->id)->orderBy('id')->get()->toArray())->toBe($pointsBefore);
});

test('after the recovery the precheck covers the NSR as recovered, without inconsistencies', function () {
    $this->decisions->apply(($this->approved)([($this->row)(4, 'recover', $this->evidence), ($this->row)(5, 'recover')]), $this->scenario->path, $this->apply);

    $coverage = app(AfdPrecheck::class)->run($this->scenario->path)['coverage'];

    expect($coverage['inconsistencies'])->toBe([])
        ->and($coverage['nsrs']['recovered'])->toBe([4, 5])
        ->and($coverage['nsrs']['pending'])->toBe([3, 6, 7, 8, 15])
        ->and($coverage['counts']['gap'])->toBe(0);
});

test('a recovery mixed with other decisions is applied in the same batch', function () {
    $result = $this->decisions->apply(($this->approved)([
        ($this->row)(4, 'recover', $this->evidence),
        ($this->row)(3, 'retain_manual', ['related_manual_point_id' => $this->manual->id]),
        ($this->row)(6, 'discard', $this->evidence),
    ]), $this->scenario->path, $this->apply);

    expect($result['by_decision'])->toBe(['discard' => 1, 'recover' => 1, 'retain_manual' => 1])
        ->and(AfdHistoricalNsr::query()->whereIn('nsr', [3, 4, 6])->orderBy('nsr')->pluck('status')->all())->toBe(['manual_retained', 'recovered', 'discarded_by_approval'])
        ->and(Point::query()->whereNotNull('nsr')->pluck('nsr')->all())->toBe([4]);
});

test('the conflict projected by the simulation is the one found after the recovery', function () {
    Point::query()->where('pis', 12345678901)->whereDate('date', '2026-09-08')->delete();
    $period = BenefitPeriod::factory()->forCompetence('2026-10')->calculated()->create();
    BenefitPeriodEmployee::factory()->create(['benefit_period_id' => $period->id, 'employee_id' => $this->employee->id]);
    $absence = BenefitAdjustment::factory()->pending()->create(['benefit_period_id' => $period->id, 'employee_id' => $this->employee->id, 'source' => AdjustmentSource::Timesheet, 'starts_on' => '2026-09-08', 'ends_on' => '2026-09-08']);
    $preview = $this->decisions->preview(($this->write)([($this->row)(7, 'recover', $this->evidence)]), $this->scenario->path)['results'][0];

    $this->decisions->apply(($this->approved)([($this->row)(7, 'recover', $this->evidence)]), $this->scenario->path, $this->apply);
    $conflicts = collect(app(TimesheetAdjustmentSuggester::class)->conflicts($period))
        ->filter(fn (array $conflict) => $conflict['employee_id'] === $this->employee->id && substr((string) $conflict['date'], 0, 10) === '2026-09-08')
        ->map(fn (array $conflict) => ['type' => $conflict['type'], 'adjustment_id' => $conflict['adjustment_id']])
        ->values()->all();

    expect($preview['warnings'])->toEqualCanonicalizing(['competence_calculated', 'new_conflict', 'day_becomes_odd'])
        ->and($preview['consequence']['conflicts_after_projected'])->toBe([['type' => 'absence_with_punch', 'adjustment_id' => $absence->id]])
        ->and($conflicts)->toBe($preview['consequence']['conflicts_after_projected'])
        ->and(BenefitAdjustment::query()->sole()->status->value)->toBe('pending');
});

test('a recovery is refused and nothing is written', function (Closure $manifest, string $message) {
    $path = $manifest($this);
    $before = ($this->tables)();

    expect(fn () => $this->decisions->apply($path, $this->scenario->path, $this->apply))->toThrow(DomainException::class, $message)
        ->and(($this->tables)())->toBe($before);
})->with([
    'warnings not acknowledged' => [fn ($test) => ($test->approved)([($test->row)(7, 'recover', $test->evidence)], acknowledge: false), 'NSR 7: aviso não confirmado day_becomes_odd'],
    'E1 with the manual point of the same minute' => [fn ($test) => ($test->approved)([($test->row)(3, 'recover')]), 'NSR 3: já existe ponto com o mesmo PIS, data e minuto'],
    'C1 without evidence' => [fn ($test) => ($test->approved)([($test->row)(4, 'recover')]), 'NSR 4: evidência documental obrigatória'],
    'X without an employee' => [function ($test) {
        AfdHistoricalNsr::query()->where('nsr', 6)->update(['classification' => 'X']);
        $test->employee->delete();

        return ($test->approved)([($test->row)(6, 'recover', $test->evidence)]);
    }, 'NSR 6: sem funcionário cadastrado com o PIS da marcação'],
    'NSR stored after the simulation' => [function ($test) {
        $path = ($test->approved)([($test->row)(4, 'recover', $test->evidence)]);
        DB::table('points')->insert(['pis' => 19999999999, 'date' => '2026-09-06', 'time' => '09:00:00', 'type' => 'importado', 'rep_serial' => AfdFileBuilder::REP_SERIAL, 'nsr' => 4, 'created_at' => now(), 'updated_at' => now()]);

        return $path;
    }, 'NSR 4: NSR já gravado com REP e NSR'],
]);

test('a failure after the punch is stored rolls everything back', function () {
    $manifest = ($this->approved)([($this->row)(4, 'recover', $this->evidence)]);
    $before = ($this->tables)();
    DB::listen(function (QueryExecuted $query) {
        if (str_starts_with($query->sql, 'insert into `afd_recovered_points`')) {
            throw new RuntimeException('falha simulada');
        }
    });

    expect(fn () => $this->decisions->apply($manifest, $this->scenario->path, $this->apply))->toThrow(RuntimeException::class, 'falha simulada')
        ->and(($this->tables)())->toBe($before);
});

test('the same recovery manifest applied again is already applied', function () {
    $manifest = ($this->approved)([($this->row)(4, 'recover', $this->evidence)]);
    $first = $this->decisions->apply($manifest, $this->scenario->path, $this->apply);
    $after = ($this->tables)();

    expect($this->decisions->apply($manifest, $this->scenario->path, $this->apply))->toMatchArray(['status' => 'already_applied', 'batch_id' => $first['batch_id']])
        ->and(($this->tables)())->toBe($after);
});

test('a recovered pending cannot receive another decision', function () {
    $this->decisions->apply(($this->approved)([($this->row)(4, 'recover', $this->evidence)]), $this->scenario->path, $this->apply);

    $result = $this->decisions->preview(($this->write)([($this->row)(4, 'discard', $this->evidence)]), $this->scenario->path)['results'][0];

    expect($result['errors'])->toContain('pendência em estado recovered: só pendências em aberto recebem decisão');
});
