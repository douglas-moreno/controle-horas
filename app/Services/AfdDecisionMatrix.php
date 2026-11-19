<?php

namespace App\Services;

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentStatus;
use App\Enums\AfdRecordType;
use App\Enums\BenefitPeriodStatus;
use App\Enums\TimesheetDayStatus;
use App\Models\AfdHistoricalNsrDecision;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Matriz do RH (CSV) ↔ manifesto de decisões (AfdHistoricalDecisions), sem acesso ao banco.
 *
 * - `convert`: lê a matriz preenchida pelo RH (separador `;`, `,` ou tabulação; UTF-8, com
 *   ou sem BOM) e gera o manifesto `kind: decision` com o SHA-256 do AFD, o REP do
 *   cabeçalho e, por linha, o SHA-256 da linha do AFD. Só entram as linhas com `decisao`
 *   preenchida: nenhuma decisão, responsável, data, evidência, ponto manual ou aviso
 *   confirmado é deduzido. Erros estruturais (NSR, linha do AFD, decisão, datas, ponto
 *   manual, avisos, NSR repetido) recusam a matriz inteira; as regras de negócio ficam
 *   com a simulação.
 * - `approve`: copia para o manifesto o `consequence_sha256` de cada linha de uma simulação
 *   salva, desde que ela seja do mesmo manifesto (SHA-256), do mesmo AFD e REP e tenha
 *   resultado `valid` (sem erros e com todos os avisos confirmados na matriz).
 * - `report`: relatório da simulação em Markdown, legível para o RH, sem PIS (funcionário
 *   pelo id interno).
 */
class AfdDecisionMatrix
{
    public const REQUIRED_COLUMNS = ['nsr', 'linha_afd', 'decisao'];

    /**
     * Valores aceitos na coluna `decisao` (sem acento, minúsculos, sem o texto entre
     * parênteses), inclusive os textos da coluna `opcoes` da matriz original.
     *
     * @var array<string, string>
     */
    public const DECISION_ALIASES = [
        'recuperar' => AfdHistoricalNsrDecision::DECISION_RECOVER,
        'recuperar afd' => AfdHistoricalNsrDecision::DECISION_RECOVER,
        'recover' => AfdHistoricalNsrDecision::DECISION_RECOVER,
        'manter manual' => AfdHistoricalNsrDecision::DECISION_RETAIN_MANUAL,
        'retain_manual' => AfdHistoricalNsrDecision::DECISION_RETAIN_MANUAL,
        'descartar' => AfdHistoricalNsrDecision::DECISION_DISCARD,
        'descartar afd' => AfdHistoricalNsrDecision::DECISION_DISCARD,
        'discard' => AfdHistoricalNsrDecision::DECISION_DISCARD,
        'manter pendente' => AfdHistoricalNsrDecision::DECISION_DEFER,
        'adiar' => AfdHistoricalNsrDecision::DECISION_DEFER,
        'defer' => AfdHistoricalNsrDecision::DECISION_DEFER,
        'revogar' => AfdHistoricalNsrDecision::DECISION_REVOKE,
        'revoke' => AfdHistoricalNsrDecision::DECISION_REVOKE,
    ];

    public const DECISION_LABELS = [
        AfdHistoricalNsrDecision::DECISION_RECOVER => 'Recuperar a marcação do AFD',
        AfdHistoricalNsrDecision::DECISION_RETAIN_MANUAL => 'Manter o ponto manual',
        AfdHistoricalNsrDecision::DECISION_DISCARD => 'Descartar a marcação do AFD (com aprovação)',
        AfdHistoricalNsrDecision::DECISION_DEFER => 'Manter pendente (adiar)',
        AfdHistoricalNsrDecision::DECISION_REVOKE => 'Revogar a decisão anterior',
    ];

    public const WARNING_LABELS = [
        'competence_closed' => 'a competência de benefício do dia está fechada: não há efeito automático; compensação é decisão separada do RH e do financeiro',
        'competence_calculated' => 'a competência de benefício do dia já foi calculada: o resultado pode mudar se for recalculada',
        'new_conflict' => 'cria conflito novo com ajuste de benefício (a decidir no módulo de benefícios)',
        'day_becomes_odd' => 'o dia passa a ter número ímpar de batidas',
        'day_becomes_excess' => 'o dia passa a ter mais de 4 batidas',
        'employee_rescinded' => 'o funcionário está desligado',
        'manual_differs_from_audit' => 'o ponto manual informado é diferente do relacionado pela auditoria',
    ];

    public const PENDING_STATUS_LABELS = [
        'pending' => 'pendente',
        'recovered' => 'recuperada',
        'manual_retained' => 'ponto manual mantido',
        'discarded_by_approval' => 'descartada com aprovação',
        'withheld' => 'retida',
    ];

    private const DATE_TIME_FORMATS = ['d/m/Y H:i:s', 'd/m/Y H:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];

    private const DATE_FORMATS = ['d/m/Y', 'Y-m-d'];

    public function __construct(
        private AfdParser $parser,
        private AfdLegacyAlignment $alignment,
    ) {}

    /**
     * Converte a matriz do RH em manifesto de decisões.
     *
     * @param  array{timezone?: string, classes?: list<string>, employees?: list<string>}  $options
     * @return array{manifest: array<string, mixed>, included: int, skipped_without_decision: list<string>, filtered_out: int}
     *
     * @throws DomainException
     */
    public function convert(string $matrixPath, string $afdPath, array $options = []): array
    {
        $timezone = $this->timezone($options['timezone'] ?? 'America/Sao_Paulo');
        [$header, $records] = $this->readMatrix($matrixPath);
        $classes = array_values(array_filter(array_map('trim', $options['classes'] ?? [])));
        $employees = array_values(array_filter(array_map('trim', $options['employees'] ?? [])));

        foreach ([...self::REQUIRED_COLUMNS, ...($classes === [] ? [] : ['classe']), ...($employees === [] ? [] : ['funcionario_id'])] as $column) {
            if (! in_array($column, $header, true)) {
                throw new DomainException('Matriz sem a coluna obrigatória '.$column.'. Nenhum manifesto foi gerado.');
            }
        }

        if (! is_file($afdPath)) {
            throw new DomainException('AFD ausente. Nenhum manifesto foi gerado.');
        }

        $lines = $this->alignment->readLines($afdPath);
        $repSerial = $this->parser->parse($lines[1] ?? '', 1)->repSerial;

        if (blank($repSerial)) {
            throw new DomainException('AFD sem cabeçalho com REP. Nenhum manifesto foi gerado.');
        }

        $problems = [];
        $rows = [];
        $skipped = [];
        $filteredOut = 0;
        $seen = [];

        foreach ($records as $csvLine => $record) {
            $reference = $this->reference($record, $csvLine);

            if (($classes !== [] && ! in_array($record['classe'] ?? '', $classes, true)) || ($employees !== [] && ! in_array($record['funcionario_id'] ?? '', $employees, true))) {
                $filteredOut++;

                continue;
            }

            if (blank($record['decisao'] ?? null)) {
                $skipped[] = $reference;

                continue;
            }

            [$row, $rowProblems] = $this->row($record, $csvLine, $lines, $timezone);

            if ($row !== null && isset($seen[$row['nsr']])) {
                $rowProblems[] = 'NSR '.$row['nsr'].' repetido na matriz (também em '.$seen[$row['nsr']].')';
            }

            if ($row !== null) {
                $seen[$row['nsr']] ??= $reference;
            }

            if ($rowProblems !== []) {
                $problems = [...$problems, ...array_map(fn (string $problem) => $reference.': '.$problem, $rowProblems)];

                continue;
            }

            $rows[] = $row;
        }

        if ($problems !== []) {
            throw new DomainException('Matriz recusada, '.count($problems).' problema(s): '.implode('; ', array_slice($problems, 0, 20)).(count($problems) > 20 ? '; …' : '').'. Nenhum manifesto foi gerado.');
        }

        if ($rows === []) {
            throw new DomainException('Nenhuma linha da matriz tem decisão preenchida'.($classes !== [] || $employees !== [] ? ' dentro do filtro' : '').'. Nenhum manifesto foi gerado.');
        }

        return [
            'manifest' => [
                'kind' => AfdHistoricalDecisions::MANIFEST_KIND,
                'afd_sha256' => hash_file('sha256', $afdPath),
                'rep_serial' => $repSerial,
                'source' => 'Matriz do RH '.basename($matrixPath),
                'matrix_sha256' => hash_file('sha256', $matrixPath),
                'timezone' => $timezone->getName(),
                'filters' => ['classes' => $classes, 'employees' => $employees],
                'expected_count' => count($rows),
                'rows' => $rows,
            ],
            'included' => count($rows),
            'skipped_without_decision' => $skipped,
            'filtered_out' => $filteredOut,
        ];
    }

    /**
     * Manifesto aprovado: o da simulação salva, com o `expected_consequence_sha256` de cada
     * linha copiado dela e, em `approved_from`, o aprovador (usuário do sistema já
     * autenticado pelo comando) e o momento da aprovação.
     *
     * @return array<string, mixed>
     *
     * @throws DomainException
     */
    public function approve(string $manifestPath, string $previewPath, User $approver): array
    {
        $contents = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
        $manifest = $contents === false ? null : json_decode($contents, true);
        $previewContents = is_file($previewPath) ? file_get_contents($previewPath) : false;
        $preview = $previewContents === false ? null : json_decode($previewContents, true);

        if (! is_array($manifest) || ($manifest['kind'] ?? null) !== AfdHistoricalDecisions::MANIFEST_KIND || ! is_array($manifest['rows'] ?? null) || ! array_is_list($manifest['rows'])) {
            throw new DomainException('Manifesto ausente, ilegível ou de outro tipo. Nada foi aprovado.');
        }

        if (! is_array($preview) || ($preview['mode'] ?? null) !== 'preview' || ! is_array($preview['results'] ?? null)) {
            throw new DomainException('Simulação ausente ou ilegível (use a saída de afd:decisions preview --output). Nada foi aprovado.');
        }

        $refusal = match (true) {
            ($preview['manifest_sha256'] ?? null) !== hash('sha256', $contents) => 'a simulação é de outro manifesto (SHA-256 diferente)',
            ($preview['afd_sha256'] ?? null) !== ($manifest['afd_sha256'] ?? null) || ($preview['rep_serial'] ?? null) !== ($manifest['rep_serial'] ?? null) => 'a simulação é de outro AFD ou REP',
            ($preview['status'] ?? null) !== AfdHistoricalDecisions::STATUS_VALID => 'a simulação não está válida ('.($preview['status'] ?? 'sem resultado').'): corrija a matriz ou confirme os avisos em avisos_confirmados e simule de novo',
            count($preview['results']) !== count($manifest['rows']) => 'quantidade de linhas da simulação diferente do manifesto',
            collect($manifest['rows'])->contains(fn ($row) => filled($row['expected_consequence_sha256'] ?? null)) => 'o manifesto já traz consequência aprovada',
            default => null,
        };

        if ($refusal === null) {
            foreach ($manifest['rows'] as $index => $row) {
                $result = $preview['results'][$index];

                if ((int) ($row['nsr'] ?? 0) !== (int) ($result['nsr'] ?? -1) || ! is_string($result['consequence_sha256'] ?? null)) {
                    $refusal = 'linha '.($index + 1).' da simulação não corresponde ao manifesto';

                    break;
                }

                $manifest['rows'][$index]['expected_consequence_sha256'] = $result['consequence_sha256'];
            }
        }

        if ($refusal !== null) {
            throw new DomainException('Aprovação recusada: '.$refusal.'. Nada foi aprovado.');
        }

        $manifest['approved_from'] = [
            'manifest_sha256' => hash('sha256', $contents),
            'preview_sha256' => hash('sha256', $previewContents),
            'approved_by' => ['user_id' => $approver->id, 'name' => $approver->name, 'email' => $approver->email],
            'approved_at' => now()->toIso8601String(),
        ];

        return $manifest;
    }

    /**
     * Relatório da simulação para o RH (Markdown).
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $preview
     */
    public function report(array $manifest, array $preview): string
    {
        $status = (string) ($preview['status'] ?? '');
        $results = $preview['results'] ?? [];
        $pendingWarnings = collect($results)->sum(fn (array $result) => count($result['unacknowledged_warnings'] ?? []));
        $out = [
            '# Simulação das decisões do RH sobre pendências do AFD',
            '',
            '**Somente simulação: nada foi gravado.** A gravação só acontece depois da aprovação deste relatório, com o manifesto aprovado.',
            '',
            '- Gerado em: '.now()->toIso8601String(),
            '- Origem: '.($manifest['source'] ?? '—').(isset($manifest['matrix_sha256']) ? ' (SHA-256 '.$manifest['matrix_sha256'].')' : ''),
            '- Manifesto: SHA-256 '.($preview['manifest_sha256'] ?? '—'),
            '- AFD: SHA-256 '.($preview['afd_sha256'] ?? '—').' · REP '.($preview['rep_serial'] ?? '—'),
            '- Resultado geral: **'.$this->statusLabel($status).'**',
            '- Linhas: '.count($results).' · recusadas: '.($preview['refused_rows'] ?? 0).' · avisos ainda não confirmados: '.$pendingWarnings,
            '- Por decisão: '.$this->counts($preview['by_decision'] ?? [], fn (string $decision) => self::DECISION_LABELS[$decision] ?? $decision),
            '- Por classe: '.$this->counts($preview['by_classification'] ?? []),
            '',
            '## Como ler',
            '',
            '- Cada seção é uma linha da matriz, identificada pelo **NSR**. A referência da matriz aparece só para conferência.',
            '- **Recusada:** a decisão não pode ser gravada como está; o motivo aparece na linha.',
            '- **Avisos:** consequências que exigem confirmação explícita. Para confirmar, o RH escreve o código do aviso na coluna `avisos_confirmados` da matriz, e a matriz é convertida e simulada de novo.',
            '- **Espelho do dia:** situação do dia antes e depois da decisão. **Conflitos:** ajustes de benefício que passam a conflitar com as batidas; a decisão não altera ajustes nem competências.',
            '',
        ];

        foreach ($results as $index => $result) {
            $out = [...$out, ...$this->rowReport($manifest['rows'][$index] ?? [], $result)];
        }

        $out[] = '## Próximo passo';
        $out[] = '';
        $out[] = match ($status) {
            AfdHistoricalDecisions::STATUS_VALID => 'Simulação válida. Se o RH aprovar este relatório, o operador gera o manifesto aprovado (`afd:decisions approve`) a partir desta simulação e, com autorização, grava (`afd:decisions apply`). Se o estado mudar até lá, a gravação é recusada.',
            AfdHistoricalDecisions::STATUS_NEEDS_ACKNOWLEDGEMENT => 'Há avisos não confirmados. Se o RH concordar com as consequências, preencha `avisos_confirmados` na matriz, converta e simule de novo.',
            default => 'Há linhas recusadas. Corrija a matriz (ou retire as linhas) e converta e simule de novo. Nada pode ser gravado enquanto houver recusa.',
        };
        $out[] = '';

        return implode("\n", $out);
    }

    /**
     * Grava um arquivo novo (recusa sobrescrever), legível só pelo dono, e devolve o SHA-256.
     *
     * @throws DomainException
     */
    public function writeNew(string $path, string $contents): string
    {
        if ($path === '' || file_exists($path)) {
            throw new DomainException('Arquivo de saída não informado ou já existente ('.$path.'): nada foi sobrescrito.');
        }

        if (! is_dir(dirname($path)) || @file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new DomainException('Não foi possível gravar o arquivo de saída ('.$path.').');
        }

        chmod($path, 0600);

        return hash('sha256', $contents);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function rowReport(array $row, array $result): array
    {
        $decision = $result['decision'] ?? null;
        $errors = $result['errors'] ?? [];
        $pending = $result['unacknowledged_warnings'] ?? [];
        $situation = match (true) {
            $errors !== [] => 'RECUSADA',
            $pending !== [] => 'AVISOS A CONFIRMAR',
            default => 'OK',
        };
        $reference = $row['matrix_ref'] ?? null;
        $out = [
            '## '.$result['row'].'. NSR '.$result['nsr'].' — '.$situation,
            '',
            '- Referência na matriz: '.($reference === null ? '—' : trim(($reference['id'] ?? '').' (registro '.($reference['record'] ?? '?').' do CSV)')),
            '- Classe: '.($result['classification'] ?? '—').' · situação atual: '.(self::PENDING_STATUS_LABELS[$result['current_status'] ?? ''] ?? ($result['current_status'] ?? 'sem pendência registrada'))
                .($result['active_decision'] === null ? '' : ' · decisão ativa: '.(self::DECISION_LABELS[$result['active_decision']['decision']] ?? $result['active_decision']['decision']).' (#'.$result['active_decision']['id'].')'),
            '- Decisão: **'.($decision === null ? 'inválida' : self::DECISION_LABELS[$decision]).'**'.($decision === null ? '' : ' (`'.$decision.'`)'),
            '- Responsável: '.$this->text($row['decided_by'] ?? null).(filled($row['decided_role'] ?? null) ? ' ('.$this->text($row['decided_role']).')' : '').' · data: '.$this->text($row['decided_at'] ?? null),
            '- Justificativa: '.$this->text($row['justification'] ?? null),
        ];

        if (filled($row['evidence_type'] ?? null) || filled($row['evidence_reference'] ?? null)) {
            $out[] = '- Evidência: '.$this->text($row['evidence_type'] ?? null).' · '.$this->text($row['evidence_reference'] ?? null).(filled($row['evidence_sha256'] ?? null) ? ' · SHA-256 '.$row['evidence_sha256'] : '');
        }

        if (filled($row['related_manual_point_id'] ?? null)) {
            $out[] = '- Ponto manual mantido: #'.$row['related_manual_point_id'];
        }

        if (filled($row['review_due_on'] ?? null) || filled($row['review_notes'] ?? null)) {
            $out[] = '- Revisão prevista: '.$this->text($row['review_due_on'] ?? null).' · '.$this->text($row['review_notes'] ?? null);
        }

        foreach ($errors as $error) {
            $out[] = '- **Recusa:** '.$error;
        }

        $consequence = $result['consequence'] ?? null;

        if (is_array($consequence)) {
            $period = $consequence['benefit_period'] ?? null;
            $out[] = '- Funcionário (id interno): '.($consequence['employee_id'] ?? 'sem cadastro único').($consequence['employee_rescinded'] ? ' (desligado)' : '').' · marcação de '.$consequence['date'].' '.substr((string) $consequence['time'], 0, 5);
            $out[] = '- Efeito nos pontos: '.match ($consequence['effect_on_points']) {
                'add_point' => 'grava a marcação do AFD',
                'remove_point' => 'apaga a marcação recuperada',
                default => 'nenhum',
            };
            $out[] = '- Espelho do dia: '.$this->day($consequence['day_before']).' → '.$this->day($consequence['day_after']);
            $out[] = '- Competência de benefício: '.($period === null ? 'nenhuma' : $period['competence'].' ('.$this->periodStatus($period['status']).'), participante: '.($period['participant'] ? 'sim' : 'não'));
            $out[] = '- Ajustes de benefício no dia: '.($consequence['adjustments_on_day'] === [] ? 'nenhum' : implode('; ', array_map(fn (array $adjustment) => '#'.$adjustment['id'].' '.(AdjustmentReason::tryFrom($adjustment['reason'])?->label() ?? $adjustment['reason']).' ('.(AdjustmentStatus::tryFrom($adjustment['status'])?->label() ?? $adjustment['status']).')', $consequence['adjustments_on_day'])));
            $out[] = '- Conflitos antes: '.$this->conflicts($consequence['conflicts_before']).' · depois (projeção): '.$this->conflicts($consequence['conflicts_after_projected']);
        }

        foreach ($result['warnings'] ?? [] as $warning) {
            $out[] = '- Aviso `'.$warning.'`: '.(self::WARNING_LABELS[$warning] ?? $warning).' — '.(in_array($warning, $pending, true) ? '**não confirmado**' : 'confirmado');
        }

        $out[] = '- Hash da consequência: '.($result['consequence_sha256'] ?? '—');
        $out[] = '';

        return $out;
    }

    /**
     * Cabeçalho (minúsculo) e registros indexados pelo número do registro no CSV (o
     * cabeçalho é o registro 1; um campo entre aspas pode ocupar mais de uma linha física).
     *
     * @return array{0: list<string>, 1: array<int, array<string, string>>}
     */
    private function readMatrix(string $path): array
    {
        $handle = is_file($path) ? @fopen($path, 'rb') : false;

        if ($handle === false) {
            throw new DomainException('Matriz ausente ou ilegível. Nenhum manifesto foi gerado.');
        }

        try {
            $first = (string) fgets($handle);
            $first = str_starts_with($first, "\u{FEFF}") ? substr($first, 3) : $first;
            $delimiter = collect([';', ',', "\t"])->sortByDesc(fn (string $candidate) => substr_count($first, $candidate))->first();
            rewind($handle);

            if (str_starts_with((string) fread($handle, 3), "\u{FEFF}") === false) {
                rewind($handle);
            }

            $header = array_map(fn ($column) => Str::lower(trim((string) $column)), fgetcsv($handle, null, $delimiter, '"', '') ?: []);

            if ($header === [] || count(array_unique($header)) !== count($header)) {
                throw new DomainException('Matriz sem cabeçalho ou com colunas repetidas. Nenhum manifesto foi gerado.');
            }

            $records = [];
            $record = 1;

            while (($values = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $record++;

                if ($values === [null] || collect($values)->every(fn ($value) => blank($value))) {
                    continue;
                }

                if (count($values) !== count($header)) {
                    throw new DomainException('Matriz com quantidade de colunas diferente do cabeçalho no registro '.$record.' do CSV. Nenhum manifesto foi gerado.');
                }

                $records[$record] = array_map(fn ($value) => trim((string) $value), array_combine($header, $values));
            }

            return [$header, $records];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, string>  $record
     * @param  array<int, string>  $lines
     * @return array{0: ?array<string, mixed>, 1: list<string>}
     */
    private function row(array $record, int $csvLine, array $lines, DateTimeZone $timezone): array
    {
        $problems = [];
        $nsr = ctype_digit($record['nsr']) ? (int) $record['nsr'] : null;
        $afdLine = ctype_digit($record['linha_afd']) ? (int) $record['linha_afd'] : null;
        $decision = self::DECISION_ALIASES[$this->normalize($record['decisao'])] ?? null;

        if ($nsr === null || $afdLine === null) {
            $problems[] = 'NSR ou linha do AFD inválidos';
        } elseif (! isset($lines[$afdLine])) {
            $problems[] = 'linha '.$afdLine.' ausente no AFD';
        } else {
            $parsed = $this->parser->parse($lines[$afdLine], $afdLine);

            if ($parsed->type !== AfdRecordType::Punch || $parsed->nsr !== $nsr) {
                $problems[] = 'a linha '.$afdLine.' do AFD não é a marcação do NSR '.$nsr;
            }
        }

        if ($decision === null) {
            $problems[] = 'decisão não reconhecida ("'.$record['decisao'].'"; use recuperar, manter manual, descartar, manter pendente ou revogar)';
        }

        [$decidedAt, $dateProblem] = $this->dateTime($record['data_decisao'] ?? '', $timezone);
        [$reviewOn, $reviewProblem] = $this->date($record['revisao_em'] ?? '');
        $manual = $record['ponto_manual_mantido'] ?? '';
        $manualId = $manual === '' ? null : (ctype_digit(ltrim($manual, '#')) ? (int) ltrim($manual, '#') : false);
        $warnings = array_values(array_filter(preg_split('/[\s,;|]+/', $record['avisos_confirmados'] ?? '') ?: []));
        $unknown = array_diff($warnings, AfdHistoricalDecisions::WARNINGS);

        $problems = [...$problems, ...array_filter([
            $dateProblem,
            $reviewProblem,
            $manualId === false ? 'ponto_manual_mantido deve ser o id do ponto (número)' : null,
            $unknown === [] ? null : 'aviso confirmado desconhecido ('.implode(', ', $unknown).')',
        ])];

        if ($problems !== []) {
            return [$nsr === null ? null : ['nsr' => $nsr], $problems];
        }

        return [array_filter([
            'matrix_ref' => array_filter(['id' => $record['id'] ?? null, 'record' => $csvLine], fn ($value) => filled($value)),
            'nsr' => $nsr,
            'afd_line' => $afdLine,
            'afd_line_sha256' => hash('sha256', $lines[$afdLine]),
            'decision' => $decision,
            'decided_by' => $this->optional($record['responsavel'] ?? null),
            'decided_role' => $this->optional($record['cargo'] ?? null),
            'decided_at' => $decidedAt,
            'justification' => $this->optional($record['justificativa'] ?? null),
            'evidence_type' => $this->optional($record['evidencia_tipo'] ?? null),
            'evidence_reference' => $this->optional($record['evidencia_referencia'] ?? null),
            'evidence_sha256' => filled($record['evidencia_sha256'] ?? null) ? Str::lower($record['evidencia_sha256']) : null,
            'related_manual_point_id' => $manualId,
            'review_due_on' => $reviewOn,
            'review_notes' => $this->optional($record['revisao_notas'] ?? null),
            'acknowledged_warnings' => array_values(array_unique($warnings)),
        ], fn ($value, string $key) => $value !== null || in_array($key, ['decided_by', 'decided_at', 'justification'], true), ARRAY_FILTER_USE_BOTH), []];
    }

    /**
     * Data e hora da decisão: `dd/mm/aaaa` ou `aaaa-mm-dd`, com hora opcional (`hh:mm` ou `hh:mm:ss`), no fuso
     * informado (só a data vale 00:00) ou ISO 8601 com fuso explícito.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function dateTime(string $value, DateTimeZone $timezone): array
    {
        if ($value === '') {
            return [null, null];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})$/', $value) === 1) {
            try {
                return [CarbonImmutable::parse($value)->toIso8601String(), null];
            } catch (Throwable) {
                return [null, 'data_decisao inválida ('.$value.')'];
            }
        }

        foreach ([...self::DATE_TIME_FORMATS, ...self::DATE_FORMATS] as $format) {
            $parsed = $this->exact($format, $value, $timezone);

            if ($parsed !== null) {
                return [(in_array($format, self::DATE_FORMATS, true) ? $parsed->startOfDay() : $parsed)->toIso8601String(), null];
            }
        }

        return [null, 'data_decisao inválida ('.$value.'; use dd/mm/aaaa hh:mm)'];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function date(string $value): array
    {
        if ($value === '') {
            return [null, null];
        }

        foreach (self::DATE_FORMATS as $format) {
            $parsed = $this->exact($format, $value, new DateTimeZone('UTC'));

            if ($parsed !== null) {
                return [$parsed->toDateString(), null];
            }
        }

        return [null, 'revisao_em inválida ('.$value.'; use dd/mm/aaaa)'];
    }

    private function exact(string $format, string $value, DateTimeZone $timezone): ?CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!'.$format, $value, $timezone);
        } catch (Throwable) {
            return null;
        }

        return $parsed !== null && $parsed->format($format) === $value ? $parsed : null;
    }

    private function timezone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            throw new DomainException('Fuso horário inválido ('.$name.'). Nenhum manifesto foi gerado.');
        }
    }

    /**
     * @param  array<string, string>  $record
     */
    private function reference(array $record, int $csvLine): string
    {
        return 'registro '.$csvLine.' do CSV'.(filled($record['id'] ?? null) ? ' ('.$record['id'].')' : '');
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/\(.*?\)/', '', Str::lower(Str::ascii($value)))));
    }

    private function optional(?string $value): ?string
    {
        return blank($value) ? null : $value;
    }

    private function text(mixed $value): string
    {
        return blank($value) ? '—' : str_replace(["\r\n", "\n", "\r"], ' ', (string) $value);
    }

    /**
     * @param  array{status: string, punch_count: int}  $day
     */
    private function day(array $day): string
    {
        $status = TimesheetDayStatus::tryFrom($day['status']);
        $label = match ($status) {
            TimesheetDayStatus::Regular => 'Regular',
            TimesheetDayStatus::NonWorkingDay => 'Dia sem jornada',
            null => $day['status'],
            default => $status->label(),
        };

        return $label.' ('.$day['punch_count'].' batida'.($day['punch_count'] === 1 ? '' : 's').')';
    }

    private function periodStatus(string $status): string
    {
        return BenefitPeriodStatus::tryFrom($status)?->label() ?? $status;
    }

    /**
     * @param  list<array{type: string, adjustment_id: int}>  $conflicts
     */
    private function conflicts(array $conflicts): string
    {
        return $conflicts === [] ? 'nenhum' : implode('; ', array_map(fn (array $conflict) => match ($conflict['type']) {
            'absence_with_punch' => 'ausência com batida',
            'work_on_absence' => 'trabalho em dia de ausência',
            default => $conflict['type'],
        }.' (ajuste #'.$conflict['adjustment_id'].')', $conflicts));
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            AfdHistoricalDecisions::STATUS_VALID => 'válida (pode ser aprovada)',
            AfdHistoricalDecisions::STATUS_NEEDS_ACKNOWLEDGEMENT => 'há avisos a confirmar',
            AfdHistoricalDecisions::STATUS_REFUSED => 'há linhas recusadas',
            default => $status,
        };
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function counts(array $counts, ?callable $label = null): string
    {
        return $counts === [] ? '—' : implode(' · ', array_map(fn (string $key, int $count) => ($label === null ? $key : $label($key)).': '.$count, array_keys($counts), $counts));
    }
}
