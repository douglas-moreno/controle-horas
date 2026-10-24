<?php

use App\Models\AfdHistoricalNsr;
use App\Models\AfdImport;
use App\Models\Point;
use App\Services\AfdImporter;
use App\Services\AfdPrecheck;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AfdFileBuilder;
use Tests\Support\LegacyAfdScenario;

/**
 * A pré-validação usa a sessão somente leitura do MySQL, que só vale fora de uma
 * transação; por isso este arquivo usa DatabaseTruncation.
 */
uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->scenario = LegacyAfdScenario::build();
    $this->precheck = app(AfdPrecheck::class);
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

test('the cursor does not hide the historical gaps, which block the precheck and the import', function () {
    $report = $this->precheck->run($this->scenario->path);

    expect($report['start'])->toMatchArray(['status' => 'ok', 'cursor' => LegacyAfdScenario::CURSOR, 'legacy_checked' => 50, 'legacy_confirmed' => 50])
        ->and($report['coverage'])->toMatchArray(['cursor' => 76, 'universe' => 72, 'blocking' => true, 'inconsistencies' => []])
        ->and($report['coverage']['counts'])->toMatchArray(['legacy_verified' => 65, 'gap' => 7, 'pending' => 0, 'imported' => 0])
        ->and($report['coverage']['nsrs']['gap'])->toBe(LegacyAfdScenario::MISSING_NSRS)
        ->and($report['verdict'])->toBe(AfdPrecheck::VERDICT_BLOCKED)
        ->and(implode(' ', $report['blocking']))->toContain('7 marcação(ões) até o cursor 76 sem nenhuma cobertura');

    $points = Point::count();

    expect(fn () => app(AfdImporter::class)->import($this->scenario->path))->toThrow(DomainException::class, '7 marcação(ões) até o cursor 76 sem cobertura');
    expect(Point::count())->toBe($points)
        ->and(AfdImport::count())->toBe(0);
});

test('known historical gaps are reported as unresolved pendings and do not block', function () {
    registerHistoricalPendings($this->scenario->path, LegacyAfdScenario::MISSING_NSRS, 'C1');

    $report = $this->precheck->run($this->scenario->path);

    expect($report['verdict'])->toBe(AfdPrecheck::VERDICT_READY)
        ->and($report['coverage']['counts'])->toMatchArray(['pending' => 7, 'gap' => 0, 'manual_retained' => 0, 'discarded_by_approval' => 0])
        ->and($report['coverage']['pending_by_classification'])->toBe(['C1' => 7])
        ->and($report['coverage']['nsrs']['pending'])->toBe(LegacyAfdScenario::MISSING_NSRS)
        ->and(implode(' ', $report['warnings']))->toContain('7 pendência(s) histórica(s) conhecida(s) até o cursor, NÃO resolvida(s) (C1: 7)');
});

test('a documented decision to keep a manual point is not counted as an import', function () {
    $manual = Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-16', 'time' => '08:16:00']);
    registerHistoricalPendings($this->scenario->path, [3, 4, 5, 6, 7, 8]);
    $line = $this->scenario->lines()[16];
    AfdHistoricalNsr::factory()->manualRetained($manual->id)->create(['rep_serial' => AfdFileBuilder::REP_SERIAL, 'nsr' => 15, 'afd_line' => 16, 'afd_line_sha256' => hash('sha256', $line), 'classification' => 'E1']);

    $coverage = $this->precheck->run($this->scenario->path)['coverage'];

    expect($coverage['blocking'])->toBeFalse()
        ->and($coverage['nsrs']['manual_retained'])->toBe([15])
        ->and($coverage['counts'])->toMatchArray(['imported' => 0, 'recovered' => 0, 'manual_retained' => 1, 'pending' => 6]);
});

test('an invalid registry entry blocks instead of covering the punch', function (Closure $entry, string $message) {
    registerHistoricalPendings($this->scenario->path, [3, 4, 5, 6, 7, 8]);
    $entry($this->scenario);

    $report = $this->precheck->run($this->scenario->path);

    expect($report['verdict'])->toBe(AfdPrecheck::VERDICT_BLOCKED)
        ->and($report['coverage']['inconsistencies'])->toHaveCount(1)
        ->and($report['coverage']['inconsistencies'][0])->toContain($message);
})->with([
    'a decision without justification' => [function (LegacyAfdScenario $scenario) {
        $manual = Point::factory()->manual()->create();
        AfdHistoricalNsr::factory()->manualRetained($manual->id)->create(['rep_serial' => AfdFileBuilder::REP_SERIAL, 'nsr' => 15, 'afd_line' => 16, 'afd_line_sha256' => hash('sha256', $scenario->lines()[16]), 'decision_justification' => null]);
    }, 'NSR 15: manutenção do ponto manual sem responsável, justificativa ou data'],
    'a discard without the responsible person' => [function (LegacyAfdScenario $scenario) {
        AfdHistoricalNsr::factory()->discarded()->create(['rep_serial' => AfdFileBuilder::REP_SERIAL, 'nsr' => 15, 'afd_line' => 16, 'afd_line_sha256' => hash('sha256', $scenario->lines()[16]), 'decided_by' => null]);
    }, 'NSR 15: descarte sem responsável'],
    'a recovered entry without its point' => [function (LegacyAfdScenario $scenario) {
        AfdHistoricalNsr::factory()->create(['rep_serial' => AfdFileBuilder::REP_SERIAL, 'nsr' => 15, 'afd_line' => 16, 'afd_line_sha256' => hash('sha256', $scenario->lines()[16]), 'status' => AfdHistoricalNsr::STATUS_RECOVERED]);
    }, 'NSR 15: registrado como recuperado, mas sem ponto'],
    'a different afd line' => [function (LegacyAfdScenario $scenario) {
        AfdHistoricalNsr::factory()->create(['rep_serial' => AfdFileBuilder::REP_SERIAL, 'nsr' => 15, 'afd_line' => 16, 'afd_line_sha256' => hash('sha256', 'outra linha')]);
    }, 'NSR 15: linha do AFD diferente'],
]);

test('a pending registered for a punch proven by the legacy importer is an inconsistency', function () {
    registerHistoricalPendings($this->scenario->path, [...LegacyAfdScenario::MISSING_NSRS, 10]);

    $coverage = $this->precheck->run($this->scenario->path)['coverage'];

    expect($coverage['blocking'])->toBeTrue()
        ->and($coverage['counts']['legacy_verified'])->toBe(65)
        ->and($coverage['inconsistencies'])->toBe(['NSR 10: registrado como pendência histórica (pending), mas coberto por legacy_verified.']);
});

test('a legacy run whose points no longer match its lines proves none of the shifted punches', function () {
    registerHistoricalPendings($this->scenario->path, LegacyAfdScenario::MISSING_NSRS);
    DB::table('points')->where('id', $this->scenario->pointIdForLine(18))->delete();

    $report = $this->precheck->run($this->scenario->path);

    expect($report['start']['status'])->toBe('ok')
        ->and($report['verdict'])->toBe(AfdPrecheck::VERDICT_BLOCKED)
        ->and($report['coverage']['counts']['legacy_verified'])->toBe(5)
        ->and($report['coverage']['counts']['gap'])->toBe(60)
        ->and($report['coverage']['legacy_runs']['mismatched_punches'])->toBe(61);
});
