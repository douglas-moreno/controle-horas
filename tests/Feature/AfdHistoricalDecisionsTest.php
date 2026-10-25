<?php

use App\Enums\AdjustmentSource;
use App\Models\AfdCorrectionBatch;
use App\Models\AfdHistoricalNsr;
use App\Models\AfdHistoricalNsrDecision;
use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriod;
use App\Models\BenefitPeriodEmployee;
use App\Models\Employee;
use App\Models\Point;
use App\Services\AfdHistoricalDecisions;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AfdFileBuilder;
use Tests\Support\LegacyAfdScenario;

/**
 * Simulação das decisões do RH sobre pendências históricas (F1). A simulação usa a sessão
 * somente leitura do MySQL, que só vale fora de uma transação; por isso este arquivo usa
 * DatabaseTruncation.
 *
 * Cenário: LegacyAfdScenario (cursor 76). A pendência do NSR 3 é a marcação de
 * 2026-09-04 08:03 do PIS do cenário.
 */
uses(DatabaseTruncation::class);

beforeEach(function () {
    expect(DB::connection()->getDatabaseName())->toStartWith('testing');

    $this->scenario = LegacyAfdScenario::build();
    $this->decisions = app(AfdHistoricalDecisions::class);

    $this->pending = function (int $nsr, string $classification, array $attributes = []): AfdHistoricalNsr {
        return AfdHistoricalNsr::factory()->create([
            'rep_serial' => AfdFileBuilder::REP_SERIAL,
            'nsr' => $nsr,
            'afd_line' => $nsr + 1,
            'afd_line_sha256' => hash('sha256', $this->scenario->lines()[$nsr + 1]),
            'classification' => $classification,
            ...$attributes,
        ]);
    };

    $this->row = fn (int $nsr, string $decision, array $extra = []): array => [
        'nsr' => $nsr,
        'afd_line' => $nsr + 1,
        'afd_line_sha256' => hash('sha256', $this->scenario->lines()[$nsr + 1]),
        'decision' => $decision,
        'decided_by' => 'Responsável do RH',
        'decided_at' => '2026-10-01T10:00:00-03:00',
        'justification' => 'Análise do RH.',
        ...$extra,
    ];

    $this->manifest = fn (array $rows, array $overrides = []): string => LegacyAfdScenario::write([
        'kind' => 'decision',
        'afd_sha256' => hash_file('sha256', $this->scenario->path),
        'rep_serial' => AfdFileBuilder::REP_SERIAL,
        'expected_count' => count($rows),
        'rows' => $rows,
        ...$overrides,
    ]);

    $this->preview = fn (array $rows): array => $this->decisions->preview(($this->manifest)($rows), $this->scenario->path);
    $this->result = fn (array $row): array => ($this->preview)([$row])['results'][0];
    $this->evidence = ['evidence_type' => 'memorando', 'evidence_reference' => 'RH-2026-001'];

    $this->tables = fn (): array => collect(Schema::getTableListing(schemaQualified: false))
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

describe('permission matrix', function () {
    test('each decision is allowed only for the classifications of the matrix', function (string $classification, string $decision, bool $allowed) {
        ($this->pending)(3, $classification);

        $errors = ($this->result)(($this->row)(3, $decision))['errors'];
        $message = 'decisão '.$decision.' não permitida para a classe '.$classification;

        $allowed ? expect($errors)->not->toContain($message) : expect($errors)->toContain($message);
    })->with(function () {
        /**
         * Matriz da especificação (seção 2.2), independente da constante do model:
         * recover, retain_manual, discard, defer.
         */
        $matrix = [
            'C1' => [true, false, true, true],
            'C3' => [true, false, true, true],
            'E1' => [true, true, true, true],
            'E2a' => [true, true, true, true],
            'E2b' => [true, true, true, true],
            'E2c' => [true, true, true, true],
            'X' => [true, false, true, true],
        ];

        foreach ($matrix as $classification => $allowed) {
            foreach (['recover', 'retain_manual', 'discard', 'defer'] as $index => $decision) {
                yield $classification.' '.$decision => [$classification, $decision, $allowed[$index]];
            }
        }
    });

    test('C2 and the withholding classification are not decided by this flow', function (string $classification, string $message) {
        ($this->pending)(3, $classification);

        expect(($this->result)(($this->row)(3, 'discard', $this->evidence))['errors'])->toContain($message);
    })->with([
        'C2' => ['C2', 'classificação C2: tratada pelo lote de recuperação (afd:correction recover), não por decisão do RH'],
        'R' => ['R', 'classificação R: retenção de marcação nova, não é pendência histórica'],
    ]);

    test('E1 cannot be recovered while the manual point of the same minute exists, but can keep it', function () {
        Employee::factory()->create(['pis' => '12345678901']);
        $manual = Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-04', 'time' => '08:03:00']);
        ($this->pending)(3, 'E1', ['related_manual_point_id' => $manual->id]);

        expect(($this->result)(($this->row)(3, 'recover'))['errors'])->toContain('já existe ponto com o mesmo PIS, data e minuto')
            ->and(($this->result)(($this->row)(3, 'retain_manual', ['related_manual_point_id' => $manual->id])))->toMatchArray(['errors' => [], 'warnings' => [], 'status_after' => 'manual_retained']);
    });

    test('a punch whose pis has no employee cannot be recovered', function () {
        ($this->pending)(3, 'X');

        expect(($this->result)(($this->row)(3, 'recover', $this->evidence))['errors'])->toContain('sem funcionário cadastrado com o PIS da marcação');

        Employee::factory()->create(['pis' => '12345678901']);

        expect(($this->result)(($this->row)(3, 'recover', $this->evidence))['errors'])->not->toContain('sem funcionário cadastrado com o PIS da marcação');
    });
});

describe('state of the pending', function () {
    test('a pending recovered by a correction batch is neither decided nor revoked here', function () {
        ($this->pending)(3, 'C1', ['status' => AfdHistoricalNsr::STATUS_RECOVERED]);

        expect(($this->result)(($this->row)(3, 'discard', $this->evidence))['errors'])->toContain('pendência em estado recovered: só pendências em aberto recebem decisão')
            ->and(($this->result)(($this->row)(3, 'revoke'))['errors'])->toContain('recuperação feita por lote de correção: só é desfeita por afd:correction revert');
    });

    test('an active final decision blocks a new one and only it can be revoked', function () {
        $entry = ($this->pending)(3, 'C1', ['status' => AfdHistoricalNsr::STATUS_DISCARDED, 'decided_by' => 'RH', 'decision_justification' => 'x', 'decided_at' => now()]);
        AfdHistoricalNsrDecision::factory()->create(['afd_historical_nsr_id' => $entry->id, 'decision' => 'discard', 'status_after' => 'discarded_by_approval', 'decided_at' => '2026-09-30 10:00:00']);

        expect(($this->result)(($this->row)(3, 'defer'))['errors'])->toContain('pendência em estado discarded_by_approval: só pendências em aberto recebem decisão')
            ->and(($this->result)(($this->row)(3, 'revoke')))->toMatchArray(['errors' => [], 'status_after' => 'pending', 'active_decision' => ['id' => $entry->activeDecision->id, 'decision' => 'discard']]);
    });

    test('a revocation cannot be dated before the revoked decision', function () {
        $entry = ($this->pending)(3, 'C1', ['status' => AfdHistoricalNsr::STATUS_DISCARDED, 'decided_by' => 'RH', 'decision_justification' => 'x', 'decided_at' => now()]);
        AfdHistoricalNsrDecision::factory()->create(['afd_historical_nsr_id' => $entry->id, 'decision' => 'discard', 'status_after' => 'discarded_by_approval', 'decided_at' => '2026-10-02 10:00:00']);

        expect(($this->result)(($this->row)(3, 'revoke'))['errors'])->toBe(['data da revogação anterior à da decisão revogada']);
    });

    test('a pending with an active revocation is open for a new decision', function () {
        $entry = ($this->pending)(3, 'C1');
        AfdHistoricalNsrDecision::factory()->create(['afd_historical_nsr_id' => $entry->id, 'decision' => 'revoke', 'status_after' => 'pending']);

        expect(($this->result)(($this->row)(3, 'discard', $this->evidence))['errors'])->toBe([])
            ->and(($this->result)(($this->row)(3, 'revoke'))['errors'])->toContain('não há decisão ativa revogável para a pendência');
    });

    test('a deferred pending can receive a final decision, but has nothing to revoke', function () {
        $entry = ($this->pending)(3, 'C1');
        AfdHistoricalNsrDecision::factory()->create(['afd_historical_nsr_id' => $entry->id]);

        expect(($this->result)(($this->row)(3, 'discard', $this->evidence))['errors'])->toBe([])
            ->and(($this->result)(($this->row)(3, 'revoke'))['errors'])->toContain('não há decisão ativa revogável para a pendência');
    });

    test('a manifest row without a registered pending or with another afd line is refused', function () {
        ($this->pending)(3, 'C1');

        expect(($this->result)(($this->row)(4, 'defer'))['errors'])->toContain('sem pendência histórica registrada')
            ->and(($this->result)([...($this->row)(3, 'defer'), 'afd_line_sha256' => hash('sha256', 'outra')])['errors'])->toContain('linha do AFD ausente ou com SHA-256 diferente')
            ->and(($this->result)([...($this->row)(3, 'defer'), 'decision' => 'apagar'])['errors'])->toContain('decisão inválida (apagar)');
    });
});

describe('duplicates', function () {
    test('the same pending twice in the manifest is refused', function () {
        ($this->pending)(3, 'C1');

        $report = ($this->preview)([($this->row)(3, 'defer'), ($this->row)(3, 'discard', $this->evidence)]);

        expect($report['status'])->toBe('refused')
            ->and($report['results'][0]['errors'])->toContain('NSR repetido no manifesto')
            ->and($report['results'][1]['errors'])->toContain('NSR repetido no manifesto');
    });

    test('a manual point cannot be kept for two pendings', function () {
        $manual = Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-04', 'time' => '08:05:00']);
        ($this->pending)(3, 'E2a');
        ($this->pending)(4, 'E2a', ['status' => AfdHistoricalNsr::STATUS_MANUAL_RETAINED, 'related_manual_point_id' => $manual->id, 'decided_by' => 'RH', 'decision_justification' => 'x', 'decided_at' => now()]);

        expect(($this->result)(($this->row)(3, 'retain_manual', ['related_manual_point_id' => $manual->id]))['errors'])->toContain('ponto manual já vinculado a outra pendência');
    });
});

describe('decision data', function () {
    test('responsible person, date and justification are required, and the date cannot be in the future', function (array $overrides, string $message) {
        ($this->pending)(3, 'C1');

        expect(($this->result)([...($this->row)(3, 'defer'), ...$overrides])['errors'])->toContain($message);
    })->with([
        'no responsible person' => [['decided_by' => ''], 'sem responsável, justificativa ou data da decisão do RH'],
        'no justification' => [['justification' => null], 'sem responsável, justificativa ou data da decisão do RH'],
        'no date' => [['decided_at' => null], 'sem responsável, justificativa ou data da decisão do RH'],
        'invalid date' => [['decided_at' => 'ontem'], 'data da decisão do RH inválida ou futura'],
        'future date' => [['decided_at' => '2999-01-01'], 'data da decisão do RH inválida ou futura'],
        'invalid evidence hash' => [['evidence_sha256' => 'abc'], 'SHA-256 da evidência inválido'],
        'review before the decision' => [['review_due_on' => '2026-09-01'], 'data de revisão inválida ou anterior à decisão'],
        'not a list of warnings' => [['acknowledged_warnings' => 'todos'], 'acknowledged_warnings deve ser uma lista'],
        'unknown warning' => [['acknowledged_warnings' => ['qualquer']], 'aviso confirmado desconhecido (qualquer)'],
    ]);

    test('documentary evidence is required to discard and to recover C1, C3 and X', function (string $classification, string $decision, bool $required) {
        Employee::factory()->create(['pis' => '12345678901']);
        ($this->pending)(3, $classification);

        $errors = ($this->result)(($this->row)(3, $decision))['errors'];

        $required ? expect($errors)->toContain('evidência documental obrigatória (tipo e referência)') : expect($errors)->not->toContain('evidência documental obrigatória (tipo e referência)');
        expect(($this->result)(($this->row)(3, $decision, $this->evidence))['errors'])->not->toContain('evidência documental obrigatória (tipo e referência)');
    })->with([
        'discard E2a' => ['E2a', 'discard', true],
        'recover C1' => ['C1', 'recover', true],
        'recover C3' => ['C3', 'recover', true],
        'recover X' => ['X', 'recover', true],
        'recover E2b' => ['E2b', 'recover', false],
        'defer C1' => ['C1', 'defer', false],
    ]);

    test('the review date applies only to a deferral', function () {
        ($this->pending)(3, 'C1');

        expect(($this->result)(($this->row)(3, 'defer', ['review_due_on' => '2026-11-30', 'review_notes' => 'Consultar o gestor.']))['errors'])->toBe([])
            ->and(($this->result)(($this->row)(3, 'discard', [...$this->evidence, 'review_due_on' => '2026-11-30']))['errors'])->toContain('data de revisão só se aplica ao adiamento (defer)');
    });

    test('the manual point kept must exist, be manual and belong to the same employee and day', function (Closure $point, string $message) {
        ($this->pending)(3, 'E2a');
        $id = $point();

        expect(($this->result)(($this->row)(3, 'retain_manual', ['related_manual_point_id' => $id]))['errors'])->toContain($message);
    })->with([
        'missing' => [fn () => 0, 'informe o ponto manual mantido (related_manual_point_id)'],
        'nonexistent' => [fn () => 999999, 'ponto manual inexistente'],
        'imported' => [fn () => Point::factory()->create(['pis' => 12345678901, 'date' => '2026-09-04', 'time' => '08:04:00', 'type' => 'importado'])->id, 'o ponto informado não é manual'],
        'another day' => [fn () => Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-05', 'time' => '08:04:00'])->id, 'ponto manual de outro funcionário ou de outro dia'],
        'another employee' => [fn () => Point::factory()->manual()->create(['pis' => 19999999999, 'date' => '2026-09-04', 'time' => '08:04:00'])->id, 'ponto manual de outro funcionário ou de outro dia'],
    ]);

    test('keeping another manual point than the one of the audit is a warning to acknowledge', function () {
        $audited = Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-04', 'time' => '08:05:00']);
        $other = Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-04', 'time' => '12:00:00']);
        ($this->pending)(3, 'E2a', ['related_manual_point_id' => $audited->id]);

        $result = ($this->result)(($this->row)(3, 'retain_manual', ['related_manual_point_id' => $other->id]));

        expect($result)->toMatchArray(['errors' => [], 'warnings' => ['manual_differs_from_audit'], 'unacknowledged_warnings' => ['manual_differs_from_audit']])
            ->and(($this->preview)([($this->row)(3, 'retain_manual', ['related_manual_point_id' => $other->id])])['status'])->toBe('needs_acknowledgement')
            ->and(($this->preview)([($this->row)(3, 'retain_manual', ['related_manual_point_id' => $other->id, 'acknowledged_warnings' => ['manual_differs_from_audit']])])['status'])->toBe('valid');
    });
});

describe('expected consequence', function () {
    beforeEach(function () {
        $this->employee = Employee::factory()->create(['pis' => '12345678901']);
        Point::query()->where('pis', 12345678901)->whereDate('date', '2026-09-04')->delete();
        ($this->pending)(3, 'C1');
    });

    test('recovering a punch on a day with a pending absence projects the conflict and the warnings', function () {
        $period = BenefitPeriod::factory()->forCompetence('2026-10')->calculated()->create();
        BenefitPeriodEmployee::factory()->create(['benefit_period_id' => $period->id, 'employee_id' => $this->employee->id]);
        $absence = BenefitAdjustment::factory()->pending()->create(['benefit_period_id' => $period->id, 'employee_id' => $this->employee->id, 'source' => AdjustmentSource::Timesheet, 'starts_on' => '2026-09-04', 'ends_on' => '2026-09-04']);

        $result = ($this->result)(($this->row)(3, 'recover', $this->evidence));

        expect($result['errors'])->toBe([])
            ->and($result['consequence'])->toMatchArray([
                'employee_id' => $this->employee->id,
                'date' => '2026-09-04',
                'effect_on_points' => 'add_point',
                'day_before' => ['status' => 'no_punches', 'punch_count' => 0, 'needs_review' => true],
                'day_after' => ['status' => 'odd_punches', 'punch_count' => 1, 'needs_review' => true],
                'benefit_period' => ['id' => $period->id, 'competence' => '2026-10', 'status' => 'calculated', 'participant' => true],
                'conflicts_after_projected' => [['type' => 'absence_with_punch', 'adjustment_id' => $absence->id]],
            ])
            ->and($result['consequence']['adjustments_on_day'])->toBe([['id' => $absence->id, 'benefit_period_id' => $period->id, 'reason' => 'unjustified_absence', 'status' => 'pending', 'source' => 'timesheet']])
            ->and($result['warnings'])->toEqualCanonicalizing(['competence_calculated', 'new_conflict', 'day_becomes_odd'])
            ->and($result['consequence_sha256'])->toBe(AfdHistoricalDecisions::consequenceHash($result['consequence']));

        $acknowledged = ($this->preview)([($this->row)(3, 'recover', [...$this->evidence, 'acknowledged_warnings' => ['competence_calculated', 'new_conflict', 'day_becomes_odd']])]);

        expect(($this->preview)([($this->row)(3, 'recover', $this->evidence)])['status'])->toBe('needs_acknowledgement')
            ->and($acknowledged['status'])->toBe('valid')
            ->and($acknowledged['results'][0]['unacknowledged_warnings'])->toBe([]);
    });

    test('a day in a closed competence is flagged', function () {
        BenefitPeriod::factory()->forCompetence('2026-10')->closed()->create();

        expect(($this->result)(($this->row)(3, 'recover', $this->evidence))['warnings'])->toContain('competence_closed');
    });

    test('decisions that do not touch points have no warnings and keep the day', function (string $decision) {
        BenefitPeriod::factory()->forCompetence('2026-10')->closed()->create();

        $result = ($this->result)(($this->row)(3, $decision, $this->evidence));

        expect($result['warnings'])->toBe([])
            ->and($result['consequence']['effect_on_points'])->toBe('none')
            ->and($result['consequence']['day_after'])->toBe($result['consequence']['day_before']);
    })->with(['discard', 'defer']);
});

describe('read only', function () {
    test('the preview writes nothing in any table and runs only selects', function () {
        $employee = Employee::factory()->create(['pis' => '12345678901']);
        $period = BenefitPeriod::factory()->forCompetence('2026-10')->calculated()->create();
        BenefitAdjustment::factory()->pending()->create(['benefit_period_id' => $period->id, 'employee_id' => $employee->id, 'starts_on' => '2026-09-04', 'ends_on' => '2026-09-04']);
        $manual = Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-05', 'time' => '08:05:00']);
        $deferred = ($this->pending)(3, 'C1');
        AfdHistoricalNsrDecision::factory()->create(['afd_historical_nsr_id' => $deferred->id]);
        ($this->pending)(4, 'E2a', ['related_manual_point_id' => $manual->id]);
        ($this->pending)(5, 'X');
        ($this->pending)(15, 'C2', ['status' => AfdHistoricalNsr::STATUS_RECOVERED]);
        $before = ($this->tables)();
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements) {
            $statements[] = strtolower((string) strtok(ltrim($query->sql), ' '));
        });

        $report = ($this->preview)([
            ($this->row)(3, 'recover', $this->evidence),
            ($this->row)(4, 'retain_manual', ['related_manual_point_id' => $manual->id]),
            ($this->row)(5, 'discard', $this->evidence),
            ($this->row)(15, 'revoke'),
        ]);

        expect($report)->toMatchArray(['mode' => 'preview', 'writes' => 'none', 'status' => 'refused', 'rows' => 4, 'refused_rows' => 1])
            ->and(array_unique($statements))->each->toBeIn(['select', 'set'])
            ->and(($this->tables)())->toBe($before);
    });

    test('the preview refuses to run inside a transaction', function () {
        ($this->pending)(3, 'C1');

        DB::transaction(fn () => expect(fn () => ($this->preview)([($this->row)(3, 'defer')]))->toThrow(DomainException::class, 'fora de uma transação'));
    });

    test('an invalid manifest is refused before any analysis', function (array $overrides, string $message) {
        expect(fn () => $this->decisions->preview(($this->manifest)([['nsr' => 3]], $overrides), $this->scenario->path))->toThrow(DomainException::class, $message);
    })->with([
        'another kind' => [['kind' => 'recovery'], 'esperado: decision'],
        'expected count' => [['expected_count' => 2], 'expected_count diferente'],
        'another afd' => [['afd_sha256' => hash('sha256', 'outro')], 'AFD ausente ou com SHA-256 diferente'],
        'another rep' => [['rep_serial' => '00004000000099999'], 'REP do manifesto diferente'],
        'no rep' => [['rep_serial' => ''], 'Manifesto sem REP'],
    ]);
});

describe('append-only history', function () {
    beforeEach(function () {
        $this->decision = AfdHistoricalNsrDecision::factory()->create();
    });

    test('a decision cannot be edited or deleted', function () {
        expect(fn () => $this->decision->update(['justification' => 'outra']))->toThrow(LogicException::class, 'append-only')
            ->and(fn () => $this->decision->delete())->toThrow(LogicException::class, 'append-only')
            ->and($this->decision->fresh()->justification)->toBe('Análise do RH.');
    });

    test('the only change allowed is from active to superseded', function () {
        $this->decision->update(['status' => AfdHistoricalNsrDecision::STATUS_SUPERSEDED]);

        expect($this->decision->fresh()->status)->toBe('superseded')
            ->and(fn () => $this->decision->update(['status' => AfdHistoricalNsrDecision::STATUS_ACTIVE]))->toThrow(LogicException::class, 'append-only');
    });

    test('a pending has at most one active decision', function () {
        $pendingId = $this->decision->afd_historical_nsr_id;

        expect(fn () => AfdHistoricalNsrDecision::factory()->create(['afd_historical_nsr_id' => $pendingId]))->toThrow(QueryException::class);

        $this->decision->update(['status' => AfdHistoricalNsrDecision::STATUS_SUPERSEDED]);
        AfdHistoricalNsrDecision::factory()->create(['afd_historical_nsr_id' => $pendingId, 'supersedes_decision_id' => $this->decision->id]);

        expect(AfdHistoricalNsrDecision::query()->where('afd_historical_nsr_id', $pendingId)->pluck('status')->sort()->values()->all())->toBe(['active', 'superseded']);
    });

    test('a decision is superseded at most once', function () {
        $pendingId = $this->decision->afd_historical_nsr_id;
        $this->decision->update(['status' => AfdHistoricalNsrDecision::STATUS_SUPERSEDED]);
        $next = AfdHistoricalNsrDecision::factory()->create(['afd_historical_nsr_id' => $pendingId, 'supersedes_decision_id' => $this->decision->id, 'status' => AfdHistoricalNsrDecision::STATUS_SUPERSEDED]);

        expect(fn () => AfdHistoricalNsrDecision::factory()->create(['afd_historical_nsr_id' => $pendingId, 'supersedes_decision_id' => $this->decision->id]))->toThrow(QueryException::class)
            ->and($next->supersedes->is($this->decision))->toBeTrue();
    });

    test('the pending and the batch of a decision cannot be deleted', function () {
        expect(fn () => DB::table('afd_historical_nsrs')->where('id', $this->decision->afd_historical_nsr_id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('afd_correction_batches')->where('id', $this->decision->afd_correction_batch_id)->delete())->toThrow(QueryException::class)
            ->and($this->decision->historicalNsr->activeDecision->is($this->decision))->toBeTrue()
            ->and($this->decision->batch->kind)->toBe(AfdCorrectionBatch::KIND_DECISION);
    });

    test('the recovered points can reference the decision that authorized them', function () {
        expect(Schema::hasColumn('afd_recovered_points', 'afd_historical_nsr_decision_id'))->toBeTrue()
            ->and(collect(Schema::getForeignKeys('afd_recovered_points'))->pluck('foreign_table')->all())->toContain('afd_historical_nsr_decisions')
            ->and(collect(Schema::getColumns('afd_recovered_points'))->firstWhere('name', 'afd_historical_nsr_decision_id')['nullable'])->toBeTrue();
    });
});

describe('command', function () {
    beforeEach(function () {
        ($this->pending)(3, 'C1');
    });

    test('a valid manifest exits successfully and an invalid one fails, both without writing', function () {
        $before = ($this->tables)();

        $this->artisan('afd:decisions', ['action' => 'preview', '--manifest' => ($this->manifest)([($this->row)(3, 'defer')]), '--afd' => $this->scenario->path])
            ->expectsOutputToContain('"status": "valid"')
            ->assertSuccessful();

        $this->artisan('afd:decisions', ['action' => 'preview', '--manifest' => ($this->manifest)([($this->row)(3, 'retain_manual')]), '--afd' => $this->scenario->path])
            ->expectsOutputToContain('"status": "refused"')
            ->assertFailed();

        expect(($this->tables)())->toBe($before);
    });

    test('an unknown action is refused', function () {
        $this->artisan('afd:decisions', ['action' => 'delete', '--manifest' => ($this->manifest)([($this->row)(3, 'defer')]), '--afd' => $this->scenario->path])
            ->expectsOutputToContain('Ação desconhecida: use convert, preview, approve ou apply.')
            ->assertFailed();
    });
});
