<?php

use App\Models\AfdCorrectionBatch;
use App\Models\AfdDecisionApproval;
use App\Models\AfdHistoricalNsr;
use App\Models\AfdHistoricalNsrDecision;
use App\Models\Employee;
use App\Models\Point;
use App\Models\User;
use App\Services\AfdDecisionMatrix;
use App\Services\AfdHistoricalCorrection;
use App\Services\AfdHistoricalDecisions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\AfdFileBuilder;
use Tests\Support\LegacyAfdScenario;

/**
 * Matriz do RH (CSV) → manifesto → simulação com relatório → manifesto aprovado → gravação
 * (F6). A conversão e a aprovação não acessam o banco; nenhuma decisão é deduzida. A
 * simulação usa a sessão somente leitura do MySQL, que só vale fora de uma transação; por
 * isso este arquivo usa DatabaseTruncation.
 *
 * Cenário: o mesmo da F4 (AfdHistoricalDecisionsRecoverTest), com NSR 3 (E1, manual no
 * mesmo minuto), NSR 4 (C1, sábado 2026-09-05), NSR 5 (E2b) e NSR 7 (C1, terça 2026-09-08).
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

    $this->matrix = app(AfdDecisionMatrix::class);
    $this->dir = sys_get_temp_dir().'/afd-matrix-'.Str::random(12);
    mkdir($this->dir, 0700);
    $this->header = ['id', 'nsr', 'linha_afd', 'classe', 'funcionario_id', 'decisao', 'responsavel', 'cargo', 'justificativa', 'data_decisao', 'evidencia_tipo', 'evidencia_referencia', 'evidencia_sha256', 'ponto_manual_mantido', 'revisao_em', 'revisao_notas', 'avisos_confirmados'];
    $this->classes = [3 => 'E1', 4 => 'C1', 5 => 'E2b', 6 => 'C1', 7 => 'C1', 8 => 'C1', 15 => 'C1'];

    /**
     * Linha da matriz: identificação como na matriz original, decisão e dados do RH.
     */
    $this->line = fn (int $nsr, string $decision = '', array $extra = []): array => [
        'id' => 'PH-'.$nsr,
        'nsr' => (string) $nsr,
        'linha_afd' => (string) ($nsr + 1),
        'classe' => $this->classes[$nsr],
        'funcionario_id' => (string) $this->employee->id,
        'decisao' => $decision,
        ...($decision === '' ? [] : ['responsavel' => 'Responsável do RH', 'cargo' => 'Analista de RH', 'justificativa' => 'Batida confirmada pelo gestor.', 'data_decisao' => '01/10/2026 10:00']),
        ...$extra,
    ];

    $this->csv = function (array $lines, ?array $header = null, string $delimiter = ';', string $prefix = ''): string {
        $header ??= $this->header;
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $header, $delimiter, '"', '');

        foreach ($lines as $line) {
            fputcsv($handle, array_map(fn (string $column) => $line[$column] ?? '', $header), $delimiter, '"', '');
        }

        rewind($handle);
        $path = $this->dir.'/matriz-'.Str::random(6).'.csv';
        file_put_contents($path, $prefix.str_replace("\n", "\r\n", (string) stream_get_contents($handle)));

        return $path;
    };

    $this->convert = fn (string $csv, array $options = []): array => $this->matrix->convert($csv, $this->scenario->path, $options);
    $this->path = fn (string $name): string => $this->dir.'/'.$name;

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
    File::deleteDirectory($this->dir);
    Schema::withoutForeignKeyConstraints(fn () => collect(Schema::getTableListing(schemaQualified: false))
        ->reject(fn (string $table) => $table === 'migrations')
        ->each(fn (string $table) => DB::table($table)->truncate()));
});

describe('conversion', function () {
    test('only the rows with a decision become manifest rows, with the AFD line and its hash', function () {
        $statements = 0;
        DB::listen(function () use (&$statements) {
            $statements++;
        });

        $converted = ($this->convert)(($this->csv)([
            ($this->line)(3, 'manter manual (registrar decisão)', ['ponto_manual_mantido' => '#'.$this->manual->id]),
            ($this->line)(4, 'Recuperar AFD', ['evidencia_tipo' => 'termo', 'evidencia_referencia' => 'RH-2026-010', 'evidencia_sha256' => str_repeat('AB', 32), 'avisos_confirmados' => 'day_becomes_odd, competence_calculated']),
            ($this->line)(5),
            ($this->line)(6, 'DESCARTAR AFD', ['evidencia_tipo' => 'memorando', 'evidencia_referencia' => 'MEM-7', 'data_decisao' => '02/10/2026']),
            ($this->line)(7, 'manter pendente', ['revisao_em' => '30/11/2026', 'revisao_notas' => 'Consultar o gestor.']),
        ]));
        $rows = $converted['manifest']['rows'];

        expect($statements)->toBe(0)
            ->and($converted)->toMatchArray(['included' => 4, 'skipped_without_decision' => ['registro 4 do CSV (PH-5)'], 'filtered_out' => 0])
            ->and($converted['manifest'])->toMatchArray(['kind' => 'decision', 'afd_sha256' => hash_file('sha256', $this->scenario->path), 'rep_serial' => AfdFileBuilder::REP_SERIAL, 'expected_count' => 4, 'timezone' => 'America/Sao_Paulo'])
            ->and(array_column($rows, 'decision'))->toBe(['retain_manual', 'recover', 'discard', 'defer'])
            ->and(array_column($rows, 'nsr'))->toBe([3, 4, 6, 7])
            ->and($rows[1])->toMatchArray([
                'matrix_ref' => ['id' => 'PH-4', 'record' => 3],
                'afd_line' => 5,
                'afd_line_sha256' => hash('sha256', $this->scenario->lines()[5]),
                'decided_by' => 'Responsável do RH',
                'decided_role' => 'Analista de RH',
                'decided_at' => '2026-10-01T10:00:00-03:00',
                'evidence_sha256' => str_repeat('ab', 32),
                'acknowledged_warnings' => ['day_becomes_odd', 'competence_calculated'],
            ])
            ->and($rows[0]['related_manual_point_id'])->toBe($this->manual->id)
            ->and($rows[2]['decided_at'])->toBe('2026-10-02T00:00:00-03:00')
            ->and($rows[3])->toMatchArray(['review_due_on' => '2026-11-30', 'review_notes' => 'Consultar o gestor.', 'acknowledged_warnings' => []]);
    });

    test('nothing is deduced: blank HR data stay blank and the audit manual point is not used', function () {
        $manifest = ($this->convert)(($this->csv)([($this->line)(3, 'manter manual', ['responsavel' => '', 'justificativa' => '', 'data_decisao' => ''])]))['manifest'];
        $rows = $manifest['rows'];
        $preview = $this->decisions->preview(LegacyAfdScenario::write($manifest), $this->scenario->path);

        expect($rows[0])->toMatchArray(['decided_by' => null, 'justification' => null, 'decided_at' => null, 'acknowledged_warnings' => []])
            ->and($rows[0])->not->toHaveKey('related_manual_point_id')
            ->and($preview['results'][0]['errors'])->toContain('sem responsável, justificativa ou data da decisão do RH', 'informe o ponto manual mantido (related_manual_point_id)');
    });

    test('a matrix with a comma separator, BOM, quoted line breaks and extra columns is read', function () {
        $header = ['id', 'nsr', 'linha_afd', "pontos_no_dia (A=AFD legado,\nM=manual)", 'decisao', 'responsavel', 'justificativa', 'data_decisao'];
        $converted = ($this->convert)(($this->csv)([[...($this->line)(7, 'adiar'), "pontos_no_dia (A=AFD legado,\nM=manual)" => "A 08:00,\nM 12:00"]], $header, ',', "\u{FEFF}"));

        expect($converted['manifest']['rows'][0])->toMatchArray(['nsr' => 7, 'decision' => 'defer', 'matrix_ref' => ['id' => 'PH-7', 'record' => 2]]);
    });

    test('an explicit offset is kept and another timezone can be chosen', function () {
        $rows = ($this->convert)(($this->csv)([
            ($this->line)(6, 'adiar', ['data_decisao' => '2026-10-01T13:00:00Z']),
            ($this->line)(7, 'adiar', ['data_decisao' => '2026-10-01 10:00']),
        ]), ['timezone' => 'America/Manaus'])['manifest']['rows'];

        expect(array_column($rows, 'decided_at'))->toBe(['2026-10-01T13:00:00+00:00', '2026-10-01T10:00:00-04:00']);
    });

    test('filters by class and employee keep only those rows', function () {
        $csv = ($this->csv)([($this->line)(3, 'adiar'), ($this->line)(4, 'adiar'), [...($this->line)(7, 'adiar'), 'funcionario_id' => '999']]);

        expect(array_column(($this->convert)($csv, ['classes' => ['C1']])['manifest']['rows'], 'nsr'))->toBe([4, 7])
            ->and(($this->convert)($csv, ['classes' => ['C1'], 'employees' => [(string) $this->employee->id]]))->toMatchArray(['included' => 1, 'filtered_out' => 2]);
    });

    test('a structural problem refuses the whole matrix', function (Closure $csv, string $message) {
        expect(fn () => ($this->convert)($csv($this)))->toThrow(DomainException::class, $message);
    })->with([
        'unknown decision' => [fn ($test) => ($test->csv)([($test->line)(4, 'substituir')]), 'registro 2 do CSV (PH-4): decisão não reconhecida ("substituir"'],
        'line of another NSR' => [fn ($test) => ($test->csv)([[...($test->line)(4, 'adiar'), 'linha_afd' => '6']]), 'a linha 6 do AFD não é a marcação do NSR 4'],
        'line missing in the AFD' => [fn ($test) => ($test->csv)([[...($test->line)(4, 'adiar'), 'linha_afd' => '9999']]), 'linha 9999 ausente no AFD'],
        'invalid NSR' => [fn ($test) => ($test->csv)([[...($test->line)(4, 'adiar'), 'nsr' => '4a']]), 'NSR ou linha do AFD inválidos'],
        'invalid decision date' => [fn ($test) => ($test->csv)([($test->line)(4, 'adiar', ['data_decisao' => '31/02/2026'])]), 'data_decisao inválida (31/02/2026'],
        'invalid review date' => [fn ($test) => ($test->csv)([($test->line)(4, 'adiar', ['revisao_em' => 'semana que vem'])]), 'revisao_em inválida'],
        'invalid manual point' => [fn ($test) => ($test->csv)([($test->line)(3, 'manter manual', ['ponto_manual_mantido' => 'o das 8h'])]), 'ponto_manual_mantido deve ser o id do ponto'],
        'unknown warning' => [fn ($test) => ($test->csv)([($test->line)(4, 'adiar', ['avisos_confirmados' => 'tudo certo'])]), 'aviso confirmado desconhecido (tudo, certo)'],
        'repeated NSR' => [fn ($test) => ($test->csv)([($test->line)(4, 'adiar'), ($test->line)(4, 'descartar')]), 'NSR 4 repetido na matriz (também em registro 2 do CSV (PH-4))'],
        'missing column' => [fn ($test) => ($test->csv)([($test->line)(4, 'adiar')], ['nsr', 'decisao']), 'Matriz sem a coluna obrigatória linha_afd'],
        'repeated column' => [fn ($test) => ($test->csv)([($test->line)(4, 'adiar')], ['nsr', 'linha_afd', 'decisao', 'decisao']), 'colunas repetidas'],
        'no decision at all' => [fn ($test) => ($test->csv)([($test->line)(4), ($test->line)(7)]), 'Nenhuma linha da matriz tem decisão preenchida'],
    ]);

    test('an invalid timezone refuses the conversion', function () {
        expect(fn () => ($this->convert)(($this->csv)([($this->line)(4, 'adiar')]), ['timezone' => 'Brasil']))->toThrow(DomainException::class, 'Fuso horário inválido (Brasil)');
    });

    test('a row with a different number of columns refuses the matrix', function () {
        $path = ($this->path)('quebrada.csv');
        file_put_contents($path, "nsr;linha_afd;decisao\r\n4;5;adiar;extra\r\n");

        expect(fn () => ($this->convert)($path))->toThrow(DomainException::class, 'quantidade de colunas diferente do cabeçalho no registro 2');
    });
});

describe('report', function () {
    test('the report explains each row to HR without personal data', function () {
        $manifest = ($this->convert)(($this->csv)([
            ($this->line)(7, 'recuperar', ['evidencia_tipo' => 'termo', 'evidencia_referencia' => 'RH-2026-010']),
            ($this->line)(4, 'manter manual', ['ponto_manual_mantido' => (string) $this->manual->id]),
        ]))['manifest'];
        $preview = $this->decisions->preview(LegacyAfdScenario::write($manifest), $this->scenario->path);

        $report = $this->matrix->report($manifest, $preview);

        expect($report)->toContain(
            'Somente simulação: nada foi gravado.',
            'Resultado geral: **há linhas recusadas**',
            '## 1. NSR 7 — AVISOS A CONFIRMAR',
            'Referência na matriz: PH-7 (registro 2 do CSV)',
            'Decisão: **Recuperar a marcação do AFD** (`recover`)',
            'Responsável: Responsável do RH (Analista de RH) · data: 2026-10-01T10:00:00-03:00',
            'Evidência: termo · RH-2026-010',
            'Funcionário (id interno): '.$this->employee->id,
            'Efeito nos pontos: grava a marcação do AFD',
            'Aviso `day_becomes_odd`: o dia passa a ter número ímpar de batidas — **não confirmado**',
            'Hash da consequência: '.$preview['results'][0]['consequence_sha256'],
            '## 2. NSR 4 — RECUSADA',
            '**Recusa:** decisão retain_manual não permitida para a classe C1',
            'Há linhas recusadas.',
        )->not->toContain('12345678901');
    });
});

describe('approval and the whole flow', function () {
    test('matrix, simulation with report, approval and recording through the command', function () {
        $csv = ($this->csv)([
            ($this->line)(3, 'manter manual', ['ponto_manual_mantido' => (string) $this->manual->id]),
            ($this->line)(7, 'recuperar', ['evidencia_tipo' => 'termo', 'evidencia_referencia' => 'RH-2026-010', 'avisos_confirmados' => 'day_becomes_odd']),
            ($this->line)(5),
        ]);
        $identity = ['--expect-host' => $this->apply['expected']['hostname'], '--expect-server-uuid' => $this->apply['expected']['server_uuid'], '--expect-database' => $this->apply['expected']['database']];

        expect(Artisan::call('afd:decisions', ['action' => 'convert', '--matrix' => $csv, '--afd' => $this->scenario->path, '--output' => ($this->path)('manifesto.json')]))->toBe(0)
            ->and(Artisan::call('afd:decisions', ['action' => 'preview', '--manifest' => ($this->path)('manifesto.json'), '--afd' => $this->scenario->path, '--output' => ($this->path)('simulacao.json'), '--report' => ($this->path)('relatorio.md')]))->toBe(0);

        $approver = User::factory()->create(['name' => 'Aprovadora do RH', 'email' => 'aprovadora@example.com']);
        $this->artisan('afd:decisions', ['action' => 'approve', '--manifest' => ($this->path)('manifesto.json'), '--preview' => ($this->path)('simulacao.json'), '--output' => ($this->path)('aprovado.json'), '--approver' => 'aprovadora@example.com', ...$identity])
            ->expectsQuestion('Senha de aprovadora@example.com (aprovador)', 'password')
            ->assertExitCode(0);
        $approval = AfdDecisionApproval::query()->sole();

        expect(Artisan::call('afd:decisions', ['action' => 'apply', '--manifest' => ($this->path)('aprovado.json'), '--afd' => $this->scenario->path, '--operator' => 'ensaio', ...$identity]))->toBe(0)
            ->and(Artisan::output())->toContain('"approved_by": "Aprovadora do RH"');

        $preview = json_decode((string) file_get_contents(($this->path)('simulacao.json')), true);
        $approved = json_decode((string) file_get_contents(($this->path)('aprovado.json')), true);

        expect(array_column($approved['rows'], 'expected_consequence_sha256'))->toBe(array_column($preview['results'], 'consequence_sha256'))
            ->and($approved['approved_from'])->toMatchArray([
                'manifest_sha256' => hash_file('sha256', ($this->path)('manifesto.json')),
                'preview_sha256' => hash_file('sha256', ($this->path)('simulacao.json')),
                'approved_by' => ['user_id' => $approver->id, 'name' => 'Aprovadora do RH', 'email' => 'aprovadora@example.com'],
            ])
            ->and($approval->only(['user_id', 'approver_name', 'approver_email', 'manifest_sha256', 'source_manifest_sha256', 'preview_sha256', 'rows']))->toBe([
                'user_id' => $approver->id,
                'approver_name' => 'Aprovadora do RH',
                'approver_email' => 'aprovadora@example.com',
                'manifest_sha256' => hash_file('sha256', ($this->path)('aprovado.json')),
                'source_manifest_sha256' => hash_file('sha256', ($this->path)('manifesto.json')),
                'preview_sha256' => hash_file('sha256', ($this->path)('simulacao.json')),
                'rows' => 2,
            ])
            ->and($approval->target)->toMatchArray($this->apply['expected'])
            ->and(AfdCorrectionBatch::query()->where('kind', AfdCorrectionBatch::KIND_DECISION)->sole()->afd_decision_approval_id)->toBe($approval->id)
            ->and(file_get_contents(($this->path)('relatorio.md')))->toContain('Resultado geral: **válida (pode ser aprovada)**')
            ->and(collect(['manifesto.json', 'simulacao.json', 'relatorio.md', 'aprovado.json'])->map(fn (string $name) => substr(sprintf('%o', fileperms(($this->path)($name))), -3))->unique()->all())->toBe(['600'])
            ->and(AfdHistoricalNsr::query()->whereIn('nsr', [3, 5, 7])->orderBy('nsr')->pluck('status')->all())->toBe(['manual_retained', 'pending', 'recovered'])
            ->and(AfdHistoricalNsrDecision::query()->pluck('decided_role')->unique()->all())->toBe(['Analista de RH']);
    });

    test('the approval is refused when the simulation does not back the manifest', function (Closure $files, string $message) {
        [$manifest, $preview] = $files($this);

        expect(fn () => $this->matrix->approve($manifest, $preview, User::factory()->create()))->toThrow(DomainException::class, $message);
    })->with([
        'warnings not acknowledged' => [function ($test) {
            $manifest = LegacyAfdScenario::write(($test->convert)(($test->csv)([($test->line)(7, 'recuperar', ['evidencia_tipo' => 'termo', 'evidencia_referencia' => 'RH-1'])]))['manifest']);

            return [$manifest, LegacyAfdScenario::write($test->decisions->preview($manifest, $test->scenario->path))];
        }, 'a simulação não está válida (needs_acknowledgement)'],
        'a refused row' => [function ($test) {
            $manifest = LegacyAfdScenario::write(($test->convert)(($test->csv)([($test->line)(4, 'manter manual', ['ponto_manual_mantido' => (string) $test->manual->id])]))['manifest']);

            return [$manifest, LegacyAfdScenario::write($test->decisions->preview($manifest, $test->scenario->path))];
        }, 'a simulação não está válida (refused)'],
        'the simulation of another manifest' => [function ($test) {
            $manifest = LegacyAfdScenario::write(($test->convert)(($test->csv)([($test->line)(7, 'adiar')]))['manifest']);
            $other = LegacyAfdScenario::write(($test->convert)(($test->csv)([($test->line)(6, 'adiar')]))['manifest']);

            return [$manifest, LegacyAfdScenario::write($test->decisions->preview($other, $test->scenario->path))];
        }, 'a simulação é de outro manifesto'],
        'an already approved manifest' => [function ($test) {
            $manifest = LegacyAfdScenario::write(($test->convert)(($test->csv)([($test->line)(7, 'adiar')]))['manifest']);
            $preview = LegacyAfdScenario::write($test->decisions->preview($manifest, $test->scenario->path));
            $approved = LegacyAfdScenario::write($test->matrix->approve($manifest, $preview, User::factory()->create()));

            return [$approved, LegacyAfdScenario::write($test->decisions->preview($approved, $test->scenario->path))];
        }, 'o manifesto já traz consequência aprovada'],
        'not a simulation' => [fn ($test) => [LegacyAfdScenario::write(($test->convert)(($test->csv)([($test->line)(7, 'adiar')]))['manifest']), LegacyAfdScenario::write(['mode' => 'apply'])], 'Simulação ausente ou ilegível'],
    ]);

    test('a state change after the HR review makes the approved manifest be refused', function () {
        $manifest = LegacyAfdScenario::write(($this->convert)(($this->csv)([($this->line)(7, 'adiar')]))['manifest']);
        $approved = LegacyAfdScenario::write($this->matrix->approve($manifest, LegacyAfdScenario::write($this->decisions->preview($manifest, $this->scenario->path)), User::factory()->create()));
        Point::factory()->create(['pis' => 12345678901, 'date' => '2026-09-08', 'time' => '12:00:00']);

        expect(fn () => $this->decisions->apply($approved, $this->scenario->path, $this->apply))->toThrow(DomainException::class, 'NSR 7: consequência prevista diferente da aprovada na simulação (o estado mudou)');
    });

    test('the approval is refused without the approver authenticated or on another database', function (array $options, ?string $password, string $message) {
        User::factory()->create(['email' => 'aprovadora@example.com']);
        $manifest = LegacyAfdScenario::write(($this->convert)(($this->csv)([($this->line)(7, 'adiar')]))['manifest']);
        $preview = LegacyAfdScenario::write($this->decisions->preview($manifest, $this->scenario->path));
        $identity = ['--expect-host' => $this->apply['expected']['hostname'], '--expect-server-uuid' => $this->apply['expected']['server_uuid'], '--expect-database' => $this->apply['expected']['database']];
        $command = $this->artisan('afd:decisions', ['action' => 'approve', '--manifest' => $manifest, '--preview' => $preview, '--output' => ($this->path)('aprovado.json'), ...$identity, ...$options]);

        if ($password !== null) {
            $command->expectsQuestion('Senha de '.($options['--approver'] ?? '').' (aprovador)', $password);
        }

        $command->expectsOutputToContain($message)->assertExitCode(1);

        expect(AfdDecisionApproval::query()->count())->toBe(0)
            ->and(file_exists(($this->path)('aprovado.json')))->toBeFalse();
    })->with([
        'wrong password' => [['--approver' => 'aprovadora@example.com'], 'errada', 'e-mail ou senha do aprovador inválidos'],
        'unknown e-mail' => [['--approver' => 'outra@example.com'], 'password', 'e-mail ou senha do aprovador inválidos'],
        'no approver' => [[], null, 'informe --approver'],
        'no interaction' => [['--approver' => 'aprovadora@example.com', '--no-interaction' => true], null, 'rode sem --no-interaction'],
        'another database' => [['--approver' => 'aprovadora@example.com', '--expect-database' => 'outro'], 'password', 'Banco de destino diferente do esperado (database)'],
    ]);

    test('recording is refused for a manifest without a registered approval', function (Closure $edit) {
        $manifest = LegacyAfdScenario::write(($this->convert)(($this->csv)([($this->line)(7, 'adiar')]))['manifest']);
        $approved = $this->matrix->approve($manifest, LegacyAfdScenario::write($this->decisions->preview($manifest, $this->scenario->path)), User::factory()->create());
        $path = approveDecisionManifest(LegacyAfdScenario::write($approved));
        $tampered = LegacyAfdScenario::write($edit($approved));

        expect(hash_file('sha256', $tampered))->not->toBe(hash_file('sha256', $path))
            ->and(fn () => $this->decisions->apply($tampered, $this->scenario->path, $this->apply))->toThrow(DomainException::class, 'o manifesto não tem aprovação registrada')
            ->and(AfdCorrectionBatch::query()->where('kind', AfdCorrectionBatch::KIND_DECISION)->count())->toBe(0)
            ->and(AfdHistoricalNsrDecision::query()->count())->toBe(0);
    })->with([
        'approver replaced in the file' => [fn (array $approved) => array_replace_recursive($approved, ['approved_from' => ['approved_by' => ['name' => 'Outra pessoa']]])],
        'approval removed from the file' => [fn (array $approved) => array_diff_key($approved, ['approved_from' => true])],
    ]);

    test('the approval record cannot be edited or deleted', function () {
        $approval = AfdDecisionApproval::factory()->create();

        expect(fn () => $approval->update(['approver_name' => 'outra']))->toThrow(LogicException::class, 'append-only')
            ->and(fn () => $approval->delete())->toThrow(LogicException::class, 'append-only');
    });

    test('no output file is ever overwritten', function (string $action, Closure $options) {
        $existing = ($this->path)('existente.json');
        file_put_contents($existing, 'conteúdo anterior');

        expect(Artisan::call('afd:decisions', ['action' => $action, ...$options($this, $existing)]))->toBe(1)
            ->and(Artisan::output())->toContain('já existente')
            ->and(file_get_contents($existing))->toBe('conteúdo anterior');
    })->with([
        'convert' => ['convert', fn ($test, string $existing) => ['--matrix' => ($test->csv)([($test->line)(7, 'adiar')]), '--afd' => $test->scenario->path, '--output' => $existing]],
        'preview' => ['preview', fn ($test, string $existing) => ['--manifest' => LegacyAfdScenario::write(($test->convert)(($test->csv)([($test->line)(7, 'adiar')]))['manifest']), '--afd' => $test->scenario->path, '--report' => $existing]],
        'approve' => ['approve', fn ($test, string $existing) => ['--manifest' => 'x', '--preview' => 'y', '--output' => $existing]],
    ]);
});
