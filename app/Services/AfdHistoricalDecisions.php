<?php

namespace App\Services;

use App\Enums\AdjustmentStatus;
use App\Enums\AfdRecordType;
use App\Enums\BenefitPeriodStatus;
use App\Enums\CalendarDayType;
use App\Enums\TimesheetDayStatus;
use App\Models\AfdCorrectionBatch;
use App\Models\AfdDecisionApproval;
use App\Models\AfdHistoricalNsr;
use App\Models\AfdHistoricalNsrDecision;
use App\Models\AfdRecoveredPoint;
use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriod;
use App\Models\BenefitPeriodEmployee;
use App\Models\Employee;
use App\Models\Point;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decisões do RH sobre pendências históricas do AFD (afd_historical_nsr_decisions), a
 * partir de um manifesto JSON fixo (`kind: decision`, com o SHA-256 do AFD e o REP).
 *
 * Simulação (`preview`): executada inteira sob AfdPrecheck::whileReadOnly()
 * (sessão MySQL somente leitura e bloqueio de qualquer comando que não seja SELECT):
 * nada é gravado. Para cada linha, valida:
 * - a linha do AFD (SHA-256, tipo 3, NSR, marcação válida) e a pendência registrada (mesma
 *   linha e SHA-256);
 * - a matriz de permissões por classificação (AfdHistoricalNsrDecision::PERMISSIONS); C2 e
 *   R ficam fora (lote de recuperação e retenção);
 * - o estado: recover/retain_manual/discard/defer só para `pending` (sem decisão ativa ou
 *   com `defer` ou `revoke` ativo); revoke só para decisão ativa deste fluxo
 *   (recover, retain_manual ou discard), nunca para os lotes de correção, com data não
 *   anterior à da decisão revogada;
 * - os dados da decisão: responsável, data (não futura) e justificativa; evidência
 *   documental (tipo e referência) no descarte e na recuperação de C1, C3 e X;
 * - recover: sem ponto com o REP e o NSR, não comprovada pelo legado, sem ponto com o
 *   mesmo PIS, data e minuto, com um único funcionário cadastrado para o PIS;
 * - retain_manual: ponto manual existente, do mesmo PIS e dia, não vinculado a outra
 *   pendência nem repetido no manifesto;
 * - revoke de recover: ponto recuperado inalterado (hash igual ao gravado), dia fora de
 *   competência de benefício `closed` e sem ajuste de benefício `confirmed` no dia.
 *
 * Calcula a consequência prevista: espelho do dia antes e depois (TimesheetMirror::build
 * com as marcações do dia mais ou menos a marcação em questão), competência de benefício
 * cuja janela contém o dia, participação, ajustes do dia, conflitos atuais
 * (TimesheetAdjustmentSuggester::conflicts) e conflitos projetados (ausência não
 * rejeitada em dia com batida), com os avisos que exigem confirmação explícita
 * (`acknowledged_warnings`). A projeção de conflitos é uma regra simplificada; a
 * conferência completa continua sendo a sonda de conflitos depois da gravação.
 *
 * Gravação (`apply`): `retain_manual`, `discard` e `defer` (F2, sem gravação em `points`) e
 * `recover` (F4: grava a marcação do AFD como `importado`, com REP e NSR e `afd_import_id`
 * nulo, ligada ao lote e à decisão por `afd_recovered_points`) e `revoke` (F5: a decisão
 * revogada passa a `superseded` e a pendência volta a `pending`, sem os campos `decided_*`
 * e com o ponto manual relacionado da auditoria; na revogação de `recover`, apaga só
 * aquele ponto e marca o vínculo em `afd_recovered_points` como `reverted`). Exige o
 * responsável e a identidade esperada do banco, recusa produção e
 * executa uma única transação: trava as pendências e os pontos manuais do manifesto,
 * reavalia tudo como na simulação e só grava se o resultado for `valid` e se cada linha
 * trouxer `expected_consequence_sha256` igual ao recalculado (a consequência aprovada na
 * simulação não mudou). Cria um lote `decision`, uma decisão ativa por linha (a decisão
 * `defer` anterior passa a `superseded`) e atualiza o estado atual da pendência
 * (`manual_retained` ou `discarded_by_approval`, com os campos `decided_*`; `defer` mantém
 * `pending`; `recover` → `recovered` com `recovered_point_id`). Confere que `points` só
 * ganhou as marcações recuperadas e só perdeu os pontos das recuperações revogadas, e que
 * os demais pontos não mudaram (impressão digital de AfdHistoricalCorrection). Repetir o mesmo manifesto devolve `already_applied`. Os lotes de
 * correção existentes não são alterados.
 *
 * O relatório não traz PIS: o funcionário aparece pelo id interno.
 */
class AfdHistoricalDecisions
{
    public const MANIFEST_KIND = 'decision';

    public const STATUS_VALID = 'valid';

    public const STATUS_NEEDS_ACKNOWLEDGEMENT = 'needs_acknowledgement';

    public const STATUS_REFUSED = 'refused';

    /**
     * Decisões que podem ser gravadas (F2, F4 e F5).
     */
    public const APPLICABLE = [AfdHistoricalNsrDecision::DECISION_RECOVER, AfdHistoricalNsrDecision::DECISION_RETAIN_MANUAL, AfdHistoricalNsrDecision::DECISION_DISCARD, AfdHistoricalNsrDecision::DECISION_DEFER, AfdHistoricalNsrDecision::DECISION_REVOKE];

    /**
     * Decisões ativas que ainda deixam a pendência em aberto para uma nova decisão.
     */
    public const OPEN_DECISIONS = [AfdHistoricalNsrDecision::DECISION_DEFER, AfdHistoricalNsrDecision::DECISION_REVOKE];

    public const WARNINGS = ['competence_closed', 'competence_calculated', 'new_conflict', 'day_becomes_odd', 'day_becomes_excess', 'employee_rescinded', 'manual_differs_from_audit'];

    /**
     * @var array<int, list<array<string, mixed>>>
     */
    private array $periodConflicts = [];

    public function __construct(
        private AfdParser $parser,
        private AfdLegacyAlignment $alignment,
        private AfdPrecheck $precheck,
        private TimesheetMirror $mirror,
        private TimesheetAdjustmentSuggester $suggester,
        private AfdHistoricalCorrection $correction,
    ) {}

    /**
     * Simulação somente leitura do manifesto de decisões.
     *
     * @return array<string, mixed>
     *
     * @throws DomainException
     */
    public function preview(string $manifestPath, string $afdPath): array
    {
        [$manifest, $manifestHash, $lines] = $this->load($manifestPath, $afdPath);
        $this->periodConflicts = [];

        $report = $this->precheck->whileReadOnly(fn () => $this->evaluate($manifest, $lines));

        return [
            'mode' => 'preview',
            'writes' => 'none',
            'manifest_sha256' => $manifestHash,
            'afd_sha256' => $manifest['afd_sha256'],
            'rep_serial' => $manifest['rep_serial'],
            ...$report,
        ];
    }

    /**
     * SHA-256 da consequência em JSON canônico (chaves dos objetos em ordem, recursivamente):
     * o hash é reproduzível a partir do valor gravado na coluna JSON, que o MySQL normaliza
     * (reordena as chaves).
     *
     * @param  array<mixed>  $consequence
     */
    public static function consequenceHash(array $consequence): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }

            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', (string) json_encode($canonical($consequence), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Registra a aprovação do manifesto aprovado (`$contents`, exatamente o conteúdo do
     * arquivo que será gerado) pelo usuário já autenticado. Confere a identidade do banco
     * antes de gravar.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, ?string>  $expected
     *
     * @throws DomainException
     */
    public function recordApproval(array $manifest, string $contents, User $approver, array $expected): AfdDecisionApproval
    {
        $target = $this->correction->assertTarget($expected);
        $approvedFrom = $manifest['approved_from'] ?? [];

        if (! AfdDecisionApproval::isAvailable()) {
            throw new DomainException('Aprovação recusada: a tabela afd_decision_approvals não existe (migration pendente). Nada foi aprovado.');
        }

        if (($approvedFrom['approved_by']['user_id'] ?? null) !== $approver->id) {
            throw new DomainException('Aprovação recusada: o manifesto não traz o aprovador autenticado. Nada foi aprovado.');
        }

        try {
            return AfdDecisionApproval::query()->create([
                'user_id' => $approver->id,
                'approver_name' => $approver->name,
                'approver_email' => $approver->email,
                'manifest_sha256' => hash('sha256', $contents),
                'source_manifest_sha256' => $approvedFrom['manifest_sha256'],
                'preview_sha256' => $approvedFrom['preview_sha256'],
                'rows' => count($manifest['rows']),
                'target' => $target,
                'approved_at' => $approvedFrom['approved_at'],
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('Aprovação recusada: este manifesto aprovado já tem aprovação registrada. Nada foi aprovado.');
        }
    }

    /**
     * Gravação das decisões do manifesto (recover, retain_manual, discard, defer e revoke).
     * Exige aprovação registrada (recordApproval) para o SHA-256 exato do manifesto.
     *
     * @param  array{operator?: string, expected?: array<string, ?string>}  $apply
     * @return array<string, mixed>
     *
     * @throws DomainException
     */
    public function apply(string $manifestPath, string $afdPath, array $apply): array
    {
        [$manifest, $manifestHash, $lines] = $this->load($manifestPath, $afdPath);
        $operator = trim((string) ($apply['operator'] ?? ''));

        if ($operator === '') {
            throw new DomainException('Informe o responsável pela operação. Nada foi gravado.');
        }

        $target = $this->correction->assertTarget($apply['expected'] ?? []);
        $this->periodConflicts = [];

        if (! AfdDecisionApproval::isAvailable()) {
            throw new DomainException('Decisões recusadas: a tabela afd_decision_approvals não existe (migration pendente). Nada foi gravado.');
        }

        return ['mode' => 'apply', 'target' => $target, ...DB::transaction(fn () => $this->persist($manifest, $manifestHash, $lines, $target, $operator))];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<int, string>  $lines
     * @param  array<string, string>  $target
     * @return array<string, mixed>
     */
    private function persist(array $manifest, string $manifestHash, array $lines, array $target, string $operator): array
    {
        $existing = AfdCorrectionBatch::query()->where('kind', AfdCorrectionBatch::KIND_DECISION)->where('manifest_sha256', $manifestHash)->where('status', AfdCorrectionBatch::STATUS_APPLIED)->first();

        if ($existing !== null) {
            $recorded = AfdHistoricalNsrDecision::query()->where('afd_correction_batch_id', $existing->id)->count();
            $this->refuseUnless($recorded === $existing->applied_count, 'o lote '.$existing->id.' deste manifesto já foi aplicado, mas o estado atual não confere com ele');

            return ['status' => 'already_applied', 'batch_id' => $existing->id, 'rows' => $existing->applied_count];
        }

        $rows = $manifest['rows'];

        foreach ($rows as $index => $row) {
            $decision = $row['decision'] ?? null;
            $this->refuseUnless(in_array($decision, self::APPLICABLE, true), 'linha '.($index + 1).': a decisão '.(is_string($decision) ? $decision : '(vazia)').' ainda não pode ser gravada (nesta fase: '.implode(', ', self::APPLICABLE).')');
            $this->refuseUnless(filled($row['expected_consequence_sha256'] ?? null), 'linha '.($index + 1).': sem expected_consequence_sha256 da simulação aprovada');
        }

        $nsrs = array_map(fn (array $row) => (int) ($row['nsr'] ?? 0), $rows);
        $entries = AfdHistoricalNsr::query()->with('activeDecision')->where('rep_serial', $manifest['rep_serial'])->whereIn('nsr', $nsrs)->lockForUpdate()->get()->keyBy('nsr');
        $revokedRecoveries = collect($rows)
            ->filter(fn (array $row) => $row['decision'] === AfdHistoricalNsrDecision::DECISION_REVOKE)
            ->map(fn (array $row) => $entries->get((int) ($row['nsr'] ?? 0))?->activeDecision)
            ->filter(fn (?AfdHistoricalNsrDecision $active) => $active?->decision === AfdHistoricalNsrDecision::DECISION_RECOVER && $active->recovered_point_id !== null)
            ->values();
        AfdRecoveredPoint::query()->whereIn('afd_historical_nsr_decision_id', $revokedRecoveries->pluck('id'))->lockForUpdate()->get();
        Point::query()->whereIn('id', [
            ...array_values(array_filter(array_map(fn (array $row) => (int) ($row['related_manual_point_id'] ?? 0), $rows))),
            ...$revokedRecoveries->pluck('recovered_point_id')->all(),
        ])->lockForUpdate()->get();

        $report = $this->evaluate($manifest, $lines);
        $problems = collect($report['results'])->flatMap(fn (array $result) => [
            ...array_map(fn (string $error) => 'NSR '.$result['nsr'].': '.$error, $result['errors']),
            ...array_map(fn (string $warning) => 'NSR '.$result['nsr'].': aviso não confirmado '.$warning, $result['unacknowledged_warnings']),
        ]);
        $this->refuseUnless($report['status'] === self::STATUS_VALID, $problems->count().' problema(s): '.$problems->take(5)->implode('; '));

        $approval = AfdDecisionApproval::query()->where('manifest_sha256', $manifestHash)->lockForUpdate()->first();
        $this->refuseUnless($approval !== null, 'o manifesto não tem aprovação registrada (gere-o com afd:decisions approve, com o aprovador autenticado)');
        $this->refuseUnless(AfdCorrectionBatch::query()->where('afd_decision_approval_id', $approval->id)->doesntExist(), 'a aprovação '.$approval->id.' já foi usada por outro lote');

        $removedIds = $revokedRecoveries->pluck('recovered_point_id')->map(fn ($id) => (int) $id)->values()->all();
        $before = $this->correction->pointsFingerprint($removedIds);
        $batch = AfdCorrectionBatch::query()->create([
            'kind' => AfdCorrectionBatch::KIND_DECISION,
            'status' => AfdCorrectionBatch::STATUS_APPLIED,
            'manifest_sha256' => $manifestHash,
            'afd_sha256' => $manifest['afd_sha256'],
            'rep_serial' => $manifest['rep_serial'],
            'expected_count' => $manifest['expected_count'],
            'target' => $target,
            'afd_decision_approval_id' => $approval->id,
            'applied_by' => $operator,
            'applied_at' => now(),
        ]);

        $recoveredIds = [];

        foreach ($report['results'] as $index => $result) {
            $pointId = $this->record($batch, $manifest['rep_serial'], $rows[$index], $result, $lines, $operator);

            if ($pointId !== null) {
                $recoveredIds[] = $pointId;
            }
        }

        $after = $this->correction->pointsFingerprint($recoveredIds);
        $this->ensure(AfdHistoricalNsrDecision::query()->where('afd_correction_batch_id', $batch->id)->where('status', AfdHistoricalNsrDecision::STATUS_ACTIVE)->count() === count($rows), 'quantidade de decisões gravadas diferente do manifesto');
        $this->ensure(Point::query()->whereIn('id', $removedIds === [] ? [0] : $removedIds)->doesntExist(), 'ponto de recuperação revogada ainda existe');
        $this->ensure(
            $before['listed'] === count($removedIds) && $after['others'] === $before['others'] && $after['listed'] === count($recoveredIds) && $after['total'] === $before['total'] + count($recoveredIds) - count($removedIds),
            'pontos fora das recuperações e revogações foram alterados ou contagem inconsistente',
        );
        $batch->update(['applied_count' => count($rows)]);

        return ['status' => 'applied', 'batch_id' => $batch->id, 'approval_id' => $approval->id, 'approved_by' => $approval->approver_name, 'rows' => count($rows), 'by_decision' => $report['by_decision'], 'recovered_point_ids' => $recoveredIds, 'removed_point_ids' => $removedIds];
    }

    /**
     * Grava a decisão de uma linha e atualiza o estado atual da pendência. Em `recover`,
     * grava antes o ponto e devolve o id dele; em `revoke`, desfaz o efeito da decisão
     * revogada (revokeEffect).
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $result
     * @param  array<int, string>  $lines
     */
    private function record(AfdCorrectionBatch $batch, string $repSerial, array $row, array $result, array $lines, string $operator): ?int
    {
        $entry = AfdHistoricalNsr::query()->with('activeDecision')->where('rep_serial', $repSerial)->where('nsr', $result['nsr'])->firstOrFail();
        $previous = $entry->activeDecision;
        $decision = $result['decision'];
        $decidedAt = CarbonImmutable::parse($row['decided_at'])->utc();
        $manualId = $decision === AfdHistoricalNsrDecision::DECISION_RETAIN_MANUAL ? (int) $row['related_manual_point_id'] : null;

        if ($decision === AfdHistoricalNsrDecision::DECISION_REVOKE) {
            $this->ensure($previous !== null && ! in_array($previous->decision, self::OPEN_DECISIONS, true), 'NSR '.$result['nsr'].' sem decisão ativa revogável');
        } elseif ($previous !== null) {
            $this->ensure(in_array($previous->decision, self::OPEN_DECISIONS, true), 'decisão ativa anterior do NSR '.$result['nsr'].' não é adiamento nem revogação');
        }

        $previous?->update(['status' => AfdHistoricalNsrDecision::STATUS_SUPERSEDED]);

        $point = $decision === AfdHistoricalNsrDecision::DECISION_RECOVER ? $this->recoverPoint($entry, $lines) : null;

        $recorded = AfdHistoricalNsrDecision::query()->create([
            'afd_historical_nsr_id' => $entry->id,
            'afd_correction_batch_id' => $batch->id,
            'decision' => $decision,
            'status_before' => $entry->status,
            'status_after' => $result['status_after'],
            'related_manual_point_id' => $manualId,
            'recovered_point_id' => $point?->id,
            'decided_by' => trim((string) $row['decided_by']),
            'decided_role' => filled($row['decided_role'] ?? null) ? trim((string) $row['decided_role']) : null,
            'decided_at' => $decidedAt,
            'justification' => trim((string) $row['justification']),
            'evidence_type' => filled($row['evidence_type'] ?? null) ? trim((string) $row['evidence_type']) : null,
            'evidence_reference' => filled($row['evidence_reference'] ?? null) ? trim((string) $row['evidence_reference']) : null,
            'evidence_sha256' => filled($row['evidence_sha256'] ?? null) ? (string) $row['evidence_sha256'] : null,
            'expected_consequence' => $result['consequence'],
            'expected_consequence_sha256' => $result['consequence_sha256'],
            'acknowledged_warnings' => ($row['acknowledged_warnings'] ?? []) === [] ? null : array_values($row['acknowledged_warnings']),
            'review_due_on' => filled($row['review_due_on'] ?? null) ? CarbonImmutable::parse($row['review_due_on'])->toDateString() : null,
            'review_notes' => filled($row['review_notes'] ?? null) ? trim((string) $row['review_notes']) : null,
            'supersedes_decision_id' => $previous?->id,
            'status' => AfdHistoricalNsrDecision::STATUS_ACTIVE,
        ]);

        if ($point !== null) {
            AfdRecoveredPoint::query()->insert([
                'afd_correction_batch_id' => $batch->id,
                'afd_historical_nsr_decision_id' => $recorded->id,
                'point_id' => $point->id,
                'rep_serial' => $repSerial,
                'nsr' => $entry->nsr,
                'afd_line' => $entry->afd_line,
                'afd_line_sha256' => $entry->afd_line_sha256,
                'point_hash' => $this->correction->pointHash($point),
                'status' => AfdRecoveredPoint::STATUS_RECOVERED,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($decision === AfdHistoricalNsrDecision::DECISION_DEFER) {
            return null;
        }

        if ($decision === AfdHistoricalNsrDecision::DECISION_REVOKE) {
            $this->revokeEffect($entry, $previous, $operator);

            return null;
        }

        $updated = AfdHistoricalNsr::query()->whereKey($entry->id)->where('status', AfdHistoricalNsr::STATUS_PENDING)->update([
            'status' => $result['status_after'],
            'recovered_point_id' => $point?->id,
            'related_manual_point_id' => $manualId ?? $entry->related_manual_point_id,
            'decided_by' => trim((string) $row['decided_by']),
            'decision_justification' => trim((string) $row['justification']),
            'decided_at' => $decidedAt,
            'updated_at' => now(),
        ]);
        $this->ensure($updated === 1, 'pendência do NSR '.$result['nsr'].' não pôde receber a decisão');

        return $point?->id;
    }

    /**
     * Desfaz o efeito da decisão revogada: na recuperação, apaga só o ponto recuperado
     * (conferido pelo hash gravado) e marca o vínculo como `reverted`; em todos os casos,
     * a pendência volta a `pending`, sem ponto recuperado nem `decided_*`, com o ponto
     * manual relacionado pela auditoria (registrado na consequência da decisão revogada).
     */
    private function revokeEffect(AfdHistoricalNsr $entry, AfdHistoricalNsrDecision $revoked, string $operator): void
    {
        if ($revoked->decision === AfdHistoricalNsrDecision::DECISION_RECOVER) {
            $recovered = AfdRecoveredPoint::query()->where('afd_historical_nsr_decision_id', $revoked->id)->where('status', AfdRecoveredPoint::STATUS_RECOVERED)->get();
            $point = $recovered->count() === 1 ? $this->correction->canonicalPoints([$recovered->first()->point_id], false)->get($recovered->first()->point_id) : null;
            $this->ensure(
                $point !== null && (int) $point->id === $revoked->recovered_point_id && hash_equals($recovered->first()->point_hash, $this->correction->pointHash($point)),
                'ponto recuperado do NSR '.$entry->nsr.' ausente ou alterado',
            );
            $this->ensure(Point::query()->whereKey($point->id)->delete() === 1, 'ponto recuperado do NSR '.$entry->nsr.' não pôde ser removido');
            AfdRecoveredPoint::query()->whereKey($recovered->first()->id)->update(['status' => AfdRecoveredPoint::STATUS_REVERTED, 'reverted_by' => $operator, 'reverted_at' => now(), 'updated_at' => now()]);
        }

        $consequence = $revoked->expected_consequence ?? [];
        $updated = AfdHistoricalNsr::query()->whereKey($entry->id)->where('status', AfdHistoricalNsrDecision::RESULTING_STATUS[$revoked->decision])->update([
            'status' => AfdHistoricalNsr::STATUS_PENDING,
            'recovered_point_id' => null,
            'related_manual_point_id' => array_key_exists('audit_related_manual_point_id', $consequence) ? $consequence['audit_related_manual_point_id'] : $entry->related_manual_point_id,
            'decided_by' => null,
            'decision_justification' => null,
            'decided_at' => null,
            'updated_at' => now(),
        ]);
        $this->ensure($updated === 1, 'pendência do NSR '.$entry->nsr.' não pôde voltar a pendente');
    }

    /**
     * Grava a marcação da linha do AFD da pendência como `importado`, com REP e NSR e sem
     * importação, e devolve o ponto na forma canônica (AfdHistoricalCorrection::pointHash).
     *
     * @param  array<int, string>  $lines
     */
    private function recoverPoint(AfdHistoricalNsr $entry, array $lines): object
    {
        $record = $this->parser->parse($lines[$entry->afd_line], $entry->afd_line);
        $this->ensure($record->nsr === $entry->nsr && hash_equals($entry->afd_line_sha256, hash('sha256', $lines[$entry->afd_line])), 'linha do AFD do NSR '.$entry->nsr.' diferente da pendência');

        try {
            $id = Point::query()->insertGetId([
                'pis' => (int) $record->pis,
                'date' => $record->date->toDateString(),
                'time' => $record->time,
                'type' => 'importado',
                'rep_serial' => $entry->rep_serial,
                'nsr' => $entry->nsr,
                'afd_import_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('Decisões recusadas: NSR '.$entry->nsr.' já gravado para o REP (gravação concorrente). Nada foi gravado.');
        }

        $point = $this->correction->canonicalPoints([$id], false)->get($id);
        $this->ensure($point !== null && $point->rep_serial === $entry->rep_serial && (int) $point->nsr === $entry->nsr && $point->afd_import_id === null, 'ponto recuperado do NSR '.$entry->nsr.' sem REP e NSR');

        return $point;
    }

    private function refuseUnless(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new DomainException('Decisões recusadas: '.$reason.'. Nada foi gravado.');
        }
    }

    private function ensure(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new DomainException('Decisões desfeitas: '.$reason.'. Nenhuma alteração foi confirmada.');
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<int, string>  $lines
     * @return array<string, mixed>
     */
    private function evaluate(array $manifest, array $lines): array
    {
        $rows = $manifest['rows'];
        $nsrs = array_map(fn (array $row) => (int) ($row['nsr'] ?? 0), $rows);
        $manualIds = array_values(array_filter(array_map(fn (array $row) => (int) ($row['related_manual_point_id'] ?? 0), $rows)));
        $context = [
            'rep_serial' => $manifest['rep_serial'],
            'lines' => $lines,
            'repeated_nsrs' => array_keys(array_filter(array_count_values($nsrs), fn (int $count) => $count > 1)),
            'repeated_manuals' => array_keys(array_filter(array_count_values($manualIds), fn (int $count) => $count > 1)),
            'entries' => AfdHistoricalNsr::query()->with('activeDecision')->where('rep_serial', $manifest['rep_serial'])->whereIn('nsr', $nsrs)->get()->keyBy('nsr'),
            'verified' => $this->alignment->align($lines)['verified'],
            'employees' => Employee::query()->toBase()->get(['id', 'pis', 'recision_date'])->groupBy(fn (object $employee) => ltrim((string) preg_replace('/\D/', '', (string) $employee->pis), '0')),
            'periods' => BenefitPeriod::query()->orderBy('competence')->get(),
        ];

        $results = [];

        foreach ($rows as $index => $row) {
            $results[] = $this->evaluateRow($index + 1, $row, $context);
        }

        $status = match (true) {
            collect($results)->contains(fn (array $result) => $result['errors'] !== []) => self::STATUS_REFUSED,
            collect($results)->contains(fn (array $result) => $result['unacknowledged_warnings'] !== []) => self::STATUS_NEEDS_ACKNOWLEDGEMENT,
            default => self::STATUS_VALID,
        };

        return [
            'status' => $status,
            'rows' => count($results),
            'by_decision' => collect($results)->countBy('decision')->sortKeys()->all(),
            'by_classification' => collect($results)->countBy(fn (array $result) => $result['classification'] ?? '(sem pendência)')->sortKeys()->all(),
            'refused_rows' => collect($results)->filter(fn (array $result) => $result['errors'] !== [])->count(),
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function evaluateRow(int $position, array $row, array $context): array
    {
        $nsr = (int) ($row['nsr'] ?? 0);
        $decision = $row['decision'] ?? null;
        /** @var ?AfdHistoricalNsr $entry */
        $entry = $context['entries']->get($nsr);
        $active = $entry?->activeDecision;
        $errors = [];
        $warnings = [];

        if (in_array($nsr, $context['repeated_nsrs'], true)) {
            $errors[] = 'NSR repetido no manifesto';
        }

        if (! in_array($decision, AfdHistoricalNsrDecision::DECISIONS, true)) {
            $errors[] = 'decisão inválida ('.(is_string($decision) && $decision !== '' ? $decision : 'vazia').')';
            $decision = null;
        }

        $lineProblem = $this->lineProblem($row, $context['lines']);
        $record = $lineProblem === null ? $this->parser->parse($context['lines'][(int) $row['afd_line']], (int) $row['afd_line']) : null;

        if ($lineProblem !== null) {
            $errors[] = $lineProblem;
        }

        if ($entry === null) {
            $errors[] = 'sem pendência histórica registrada';
        } else {
            $errors = [...$errors, ...$this->entryProblems($entry, $active, $row, $decision)];
        }

        $errors = [...$errors, ...$this->decisionDataProblems($row, $entry, $decision)];
        $employee = null;

        if ($record !== null) {
            $matches = $context['employees']->get(ltrim((string) $record->pis, '0'));
            $employee = $matches !== null && $matches->count() === 1 ? $matches->first() : null;

            if ($decision === AfdHistoricalNsrDecision::DECISION_RECOVER) {
                $errors = [...$errors, ...$this->recoveryProblems($record, $nsr, $matches, $context)];
            }

            if ($decision === AfdHistoricalNsrDecision::DECISION_RETAIN_MANUAL && $entry !== null) {
                [$manualErrors, $manualWarnings] = $this->manualProblems($row, $record, $entry, $context);
                $errors = [...$errors, ...$manualErrors];
                $warnings = [...$warnings, ...$manualWarnings];
            }

            if ($decision === AfdHistoricalNsrDecision::DECISION_REVOKE && $active?->decision === AfdHistoricalNsrDecision::DECISION_RECOVER) {
                $errors = [...$errors, ...$this->revocationProblems($active, $record, $employee, $context)];
            }
        }

        $consequence = null;

        if ($record !== null && $entry !== null && $decision !== null) {
            [$consequence, $consequenceWarnings] = $this->consequence($record, $employee, $decision, $active, $context);
            $consequence['audit_related_manual_point_id'] = $entry->related_manual_point_id;

            if ($decision === AfdHistoricalNsrDecision::DECISION_REVOKE) {
                $consequence['revoked_decision_id'] = $active?->id;
            }
            $warnings = [...$warnings, ...$consequenceWarnings];
        }

        $consequenceHash = $consequence === null ? null : self::consequenceHash($consequence);

        if (filled($row['expected_consequence_sha256'] ?? null) && $row['expected_consequence_sha256'] !== $consequenceHash) {
            $errors[] = 'consequência prevista diferente da aprovada na simulação (o estado mudou)';
        }

        $acknowledged = is_array($row['acknowledged_warnings'] ?? []) ? array_map('strval', $row['acknowledged_warnings'] ?? []) : [];

        if (! is_array($row['acknowledged_warnings'] ?? [])) {
            $errors[] = 'acknowledged_warnings deve ser uma lista';
        }

        $unknown = array_values(array_diff($acknowledged, self::WARNINGS));

        if ($unknown !== []) {
            $errors[] = 'aviso confirmado desconhecido ('.implode(', ', $unknown).')';
        }

        $warnings = array_values(array_unique($warnings));

        return [
            'row' => $position,
            'nsr' => $nsr,
            'afd_line' => (int) ($row['afd_line'] ?? 0),
            'classification' => $entry?->classification,
            'current_status' => $entry?->status,
            'active_decision' => $active === null ? null : ['id' => $active->id, 'decision' => $active->decision],
            'decision' => $decision,
            'status_after' => $decision === null ? null : AfdHistoricalNsrDecision::RESULTING_STATUS[$decision],
            'errors' => array_values(array_unique($errors)),
            'warnings' => $warnings,
            'unacknowledged_warnings' => array_values(array_diff($warnings, $acknowledged)),
            'consequence' => $consequence,
            'consequence_sha256' => $consequenceHash,
        ];
    }

    /**
     * Linha do manifesto confere com uma marcação válida do AFD com o mesmo NSR.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $lines
     */
    private function lineProblem(array $row, array $lines): ?string
    {
        $lineNumber = (int) ($row['afd_line'] ?? 0);
        $line = $lines[$lineNumber] ?? null;

        if ($line === null || ! hash_equals((string) ($row['afd_line_sha256'] ?? ''), hash('sha256', $line))) {
            return 'linha do AFD ausente ou com SHA-256 diferente';
        }

        $record = $this->parser->parse($line, $lineNumber);

        return match (true) {
            $record->type !== AfdRecordType::Punch => 'a linha não é marcação (tipo 3)',
            $record->nsr !== (int) ($row['nsr'] ?? 0) => 'NSR da linha diferente do manifesto',
            $record->rejection !== null => 'marcação inválida ('.$record->rejection->value.')',
            default => null,
        };
    }

    /**
     * Pendência registrada: mesma linha, classificação tratada por decisão do RH, decisão
     * permitida para a classe e compatível com o estado atual.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function entryProblems(AfdHistoricalNsr $entry, ?AfdHistoricalNsrDecision $active, array $row, ?string $decision): array
    {
        $errors = [];

        if ($entry->afd_line !== (int) ($row['afd_line'] ?? 0) || ! hash_equals($entry->afd_line_sha256, (string) ($row['afd_line_sha256'] ?? ''))) {
            $errors[] = 'linha diferente da pendência registrada';
        }

        $allowed = AfdHistoricalNsrDecision::PERMISSIONS[$entry->classification] ?? null;

        if ($allowed === null) {
            $errors[] = match ($entry->classification) {
                'C2' => 'classificação C2: tratada pelo lote de recuperação (afd:correction recover), não por decisão do RH',
                AfdHistoricalNsr::CLASSIFICATION_WITHHELD => 'classificação R: retenção de marcação nova, não é pendência histórica',
                default => 'classificação '.$entry->classification.' não é tratada por decisão do RH',
            };
        } elseif ($decision !== null && $decision !== AfdHistoricalNsrDecision::DECISION_REVOKE && ! in_array($decision, $allowed, true)) {
            $errors[] = 'decisão '.$decision.' não permitida para a classe '.$entry->classification;
        }

        if ($decision === null) {
            return $errors;
        }

        if ($decision !== AfdHistoricalNsrDecision::DECISION_REVOKE) {
            if ($entry->status !== AfdHistoricalNsr::STATUS_PENDING) {
                $errors[] = 'pendência em estado '.$entry->status.': só pendências em aberto recebem decisão';
            } elseif ($active !== null && ! in_array($active->decision, self::OPEN_DECISIONS, true)) {
                $errors[] = 'já existe decisão ativa ('.$active->decision.') para a pendência';
            }

            return $errors;
        }

        $revocable = [AfdHistoricalNsrDecision::DECISION_RECOVER, AfdHistoricalNsrDecision::DECISION_RETAIN_MANUAL, AfdHistoricalNsrDecision::DECISION_DISCARD];

        if ($active === null || ! in_array($active->decision, $revocable, true)) {
            $errors[] = $entry->status === AfdHistoricalNsr::STATUS_RECOVERED
                ? 'recuperação feita por lote de correção: só é desfeita por afd:correction revert'
                : 'não há decisão ativa revogável para a pendência';
        } elseif ($entry->status !== AfdHistoricalNsrDecision::RESULTING_STATUS[$active->decision]) {
            $errors[] = 'estado da pendência ('.$entry->status.') diferente do produzido pela decisão ativa';
        }

        $decidedAt = $this->date($row['decided_at'] ?? null);

        if ($active !== null && $decidedAt !== null && $decidedAt->lt($active->decided_at)) {
            $errors[] = 'data da revogação anterior à da decisão revogada';
        }

        return $errors;
    }

    /**
     * Dados da decisão: responsável, data, justificativa, evidência e revisão.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function decisionDataProblems(array $row, ?AfdHistoricalNsr $entry, ?string $decision): array
    {
        $errors = [];

        if (blank($row['decided_by'] ?? null) || blank($row['justification'] ?? null) || blank($row['decided_at'] ?? null)) {
            $errors[] = 'sem responsável, justificativa ou data da decisão do RH';
        }

        $decidedAt = $this->date($row['decided_at'] ?? null);

        if (filled($row['decided_at'] ?? null) && ($decidedAt === null || $decidedAt->isFuture())) {
            $errors[] = 'data da decisão do RH inválida ou futura';
        }

        $needsEvidence = $decision === AfdHistoricalNsrDecision::DECISION_DISCARD
            || ($decision === AfdHistoricalNsrDecision::DECISION_RECOVER && in_array($entry?->classification, AfdHistoricalNsrDecision::RECOVER_REQUIRES_EVIDENCE, true));

        if ($needsEvidence && (blank($row['evidence_type'] ?? null) || blank($row['evidence_reference'] ?? null))) {
            $errors[] = 'evidência documental obrigatória (tipo e referência)';
        }

        if (filled($row['evidence_sha256'] ?? null) && preg_match('/^[0-9a-f]{64}$/', (string) $row['evidence_sha256']) !== 1) {
            $errors[] = 'SHA-256 da evidência inválido';
        }

        if (filled($row['review_due_on'] ?? null)) {
            $reviewOn = $this->date($row['review_due_on']);

            if ($decision !== AfdHistoricalNsrDecision::DECISION_DEFER) {
                $errors[] = 'data de revisão só se aplica ao adiamento (defer)';
            } elseif ($reviewOn === null || ($decidedAt !== null && $reviewOn->toDateString() < $decidedAt->toDateString())) {
                $errors[] = 'data de revisão inválida ou anterior à decisão';
            }
        }

        return $errors;
    }

    /**
     * @param  ?Collection<int, object>  $matches  Funcionários com o PIS da marcação.
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    private function recoveryProblems(AfdRecord $record, int $nsr, ?Collection $matches, array $context): array
    {
        $errors = [];

        if (Point::query()->where('rep_serial', $context['rep_serial'])->where('nsr', $nsr)->exists()) {
            $errors[] = 'NSR já gravado com REP e NSR';
        }

        if (isset($context['verified'][$nsr])) {
            $errors[] = 'comprovado no legado: não é lacuna';
        }

        if ($this->sameMinute($record)->exists()) {
            $errors[] = 'já existe ponto com o mesmo PIS, data e minuto';
        }

        if ($matches === null) {
            $errors[] = 'sem funcionário cadastrado com o PIS da marcação';
        } elseif ($matches->count() > 1) {
            $errors[] = 'PIS com mais de um cadastro de funcionário';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $context
     * @return array{0: list<string>, 1: list<string>}
     */
    private function manualProblems(array $row, AfdRecord $record, AfdHistoricalNsr $entry, array $context): array
    {
        $manualId = (int) ($row['related_manual_point_id'] ?? 0);

        if ($manualId === 0) {
            return [['informe o ponto manual mantido (related_manual_point_id)'], []];
        }

        $manual = Point::query()->find($manualId);
        $errors = match (true) {
            $manual === null => ['ponto manual inexistente'],
            $manual->type !== 'manual' => ['o ponto informado não é manual'],
            (string) $manual->pis !== ltrim((string) $record->pis, '0') || CarbonImmutable::parse($manual->date)->toDateString() !== $record->date->toDateString() => ['ponto manual de outro funcionário ou de outro dia'],
            default => [],
        };

        if (in_array($manualId, $context['repeated_manuals'], true)) {
            $errors[] = 'ponto manual repetido no manifesto';
        }

        $linked = AfdHistoricalNsr::query()->where('related_manual_point_id', $manualId)->where('status', AfdHistoricalNsr::STATUS_MANUAL_RETAINED)->whereKeyNot($entry->id)->exists()
            || AfdHistoricalNsrDecision::query()->where('related_manual_point_id', $manualId)->where('status', AfdHistoricalNsrDecision::STATUS_ACTIVE)->where('afd_historical_nsr_id', '!=', $entry->id)->exists();

        if ($linked) {
            $errors[] = 'ponto manual já vinculado a outra pendência';
        }

        $warnings = $entry->related_manual_point_id !== null && $entry->related_manual_point_id !== $manualId ? ['manual_differs_from_audit'] : [];

        return [$errors, $warnings];
    }

    /**
     * Revogação de recuperação feita por decisão: o ponto existe inalterado, a
     * competência do dia não está fechada e não há ajuste confirmado no dia.
     *
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    private function revocationProblems(AfdHistoricalNsrDecision $active, AfdRecord $record, ?object $employee, array $context): array
    {
        $errors = [];
        $recovered = AfdRecoveredPoint::query()->where('afd_historical_nsr_decision_id', $active->id)->where('status', AfdRecoveredPoint::STATUS_RECOVERED)->get();
        $point = $recovered->count() !== 1 ? null : $this->correction->canonicalPoints([$recovered->first()->point_id], false)->get($recovered->first()->point_id);

        if ($point === null || (int) $point->id !== $active->recovered_point_id || ! hash_equals($recovered->first()->point_hash, $this->correction->pointHash($point))) {
            $errors[] = 'ponto recuperado ausente ou alterado depois da recuperação';
        }

        $date = $record->date->toDateString();
        /** @var ?BenefitPeriod $period */
        $period = $context['periods']->first(fn (BenefitPeriod $period) => $period->windowStart()->toDateString() <= $date && $date <= $period->windowEnd()->toDateString());

        if ($period?->status === BenefitPeriodStatus::Closed) {
            $errors[] = 'dia em competência de benefício fechada ('.$period->competence->format('Y-m').')';
        }

        if ($employee === null || BenefitAdjustment::query()->where('employee_id', $employee->id)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->where('status', AdjustmentStatus::Confirmed)->exists()) {
            $errors[] = $employee === null ? 'sem funcionário único cadastrado com o PIS da marcação' : 'há ajuste de benefício confirmado no dia';
        }

        return $errors;
    }

    /**
     * Consequência prevista da decisão e avisos que exigem confirmação explícita.
     *
     * @param  array<string, mixed>  $context
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function consequence(AfdRecord $record, ?object $employee, string $decision, ?AfdHistoricalNsrDecision $active, array $context): array
    {
        $date = $record->date->toDateString();
        $day = CarbonImmutable::parse($date);
        $effect = match (true) {
            $decision === AfdHistoricalNsrDecision::DECISION_RECOVER => 'add_point',
            $decision === AfdHistoricalNsrDecision::DECISION_REVOKE && $active?->decision === AfdHistoricalNsrDecision::DECISION_RECOVER => 'remove_point',
            default => 'none',
        };

        $before = Point::query()->where('pis', (int) $record->pis)->whereDate('date', $date)->orderBy('time')->get();
        $after = match ($effect) {
            'add_point' => $before->concat([(new Point)->forceFill(['pis' => (int) $record->pis, 'date' => $date, 'time' => $record->time, 'type' => 'importado'])]),
            'remove_point' => $before->reject(fn (Point $point) => $point->id === $active?->recovered_point_id)->values(),
            default => $before,
        };

        $dayBefore = $this->mirror->build($before, $day, $day)['days'][0];
        $dayAfter = $this->mirror->build($after, $day, $day)['days'][0];
        /** @var ?BenefitPeriod $period */
        $period = $context['periods']->first(fn (BenefitPeriod $period) => $period->windowStart()->toDateString() <= $date && $date <= $period->windowEnd()->toDateString());
        $adjustments = $employee === null ? collect() : BenefitAdjustment::query()
            ->where('employee_id', $employee->id)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)
            ->orderBy('id')->get(['id', 'benefit_period_id', 'reason', 'status', 'source']);

        $conflictsBefore = $period === null || $employee === null ? [] : collect($this->conflictsFor($period))
            ->filter(fn (array $conflict) => $conflict['employee_id'] === $employee->id && substr((string) $conflict['date'], 0, 10) === $date)
            ->map(fn (array $conflict) => ['type' => $conflict['type'], 'adjustment_id' => $conflict['adjustment_id']])
            ->values()->all();

        $conflictsAfter = $effect === 'none' ? $conflictsBefore : ($dayAfter['punch_count'] === 0 ? [] : $adjustments
            ->filter(fn (BenefitAdjustment $adjustment) => $adjustment->reason->isAbsence() && $adjustment->status !== AdjustmentStatus::Rejected)
            ->map(fn (BenefitAdjustment $adjustment) => ['type' => $dayAfter['day_type'] === CalendarDayType::BusinessDay ? 'absence_with_punch' : 'work_on_absence', 'adjustment_id' => $adjustment->id])
            ->values()->all());

        $warnings = [];

        if ($effect !== 'none') {
            $warnings = array_filter([
                $period?->status === BenefitPeriodStatus::Closed ? 'competence_closed' : null,
                $period?->status === BenefitPeriodStatus::Calculated ? 'competence_calculated' : null,
                array_diff(array_map('json_encode', $conflictsAfter), array_map('json_encode', $conflictsBefore)) !== [] ? 'new_conflict' : null,
                $dayAfter['status'] === TimesheetDayStatus::OddPunches && $dayBefore['status'] !== TimesheetDayStatus::OddPunches ? 'day_becomes_odd' : null,
                $dayAfter['status'] === TimesheetDayStatus::ExcessPunches && $dayBefore['status'] !== TimesheetDayStatus::ExcessPunches ? 'day_becomes_excess' : null,
                $effect === 'add_point' && $employee !== null && $employee->recision_date !== null ? 'employee_rescinded' : null,
            ]);
        }

        $consequence = [
            'employee_id' => $employee?->id,
            'employee_rescinded' => $employee !== null && $employee->recision_date !== null,
            'date' => $date,
            'time' => $record->time,
            'effect_on_points' => $effect,
            'day_before' => ['status' => $dayBefore['status']->value, 'punch_count' => $dayBefore['punch_count'], 'needs_review' => $dayBefore['needs_review']],
            'day_after' => ['status' => $dayAfter['status']->value, 'punch_count' => $dayAfter['punch_count'], 'needs_review' => $dayAfter['needs_review']],
            'benefit_period' => $period === null ? null : [
                'id' => $period->id,
                'competence' => $period->competence->format('Y-m'),
                'status' => $period->status->value,
                'participant' => $employee !== null && BenefitPeriodEmployee::query()->where('benefit_period_id', $period->id)->where('employee_id', $employee->id)->exists(),
            ],
            'adjustments_on_day' => $adjustments->map(fn (BenefitAdjustment $adjustment) => [
                'id' => $adjustment->id,
                'benefit_period_id' => $adjustment->benefit_period_id,
                'reason' => $adjustment->reason->value,
                'status' => $adjustment->status->value,
                'source' => $adjustment->source->value,
            ])->values()->all(),
            'conflicts_before' => $conflictsBefore,
            'conflicts_after_projected' => $conflictsAfter,
        ];

        return [$consequence, array_values($warnings)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function conflictsFor(BenefitPeriod $period): array
    {
        return $this->periodConflicts[$period->id] ??= array_values($this->suggester->conflicts($period));
    }

    /**
     * @return Builder<Point>
     */
    private function sameMinute(AfdRecord $record): Builder
    {
        return Point::query()->where('pis', (int) $record->pis)->whereDate('date', $record->date->toDateString())->where('time', 'like', substr((string) $record->time, 0, 5).'%');
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
     */
    private function load(string $manifestPath, string $afdPath): array
    {
        $contents = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
        $manifest = $contents === false ? null : json_decode($contents, true);

        if (! is_array($manifest) || ($manifest['kind'] ?? null) !== self::MANIFEST_KIND || ! is_array($manifest['rows'] ?? null) || ! array_is_list($manifest['rows'])) {
            throw new DomainException('Manifesto ausente, ilegível ou de outro tipo (esperado: '.self::MANIFEST_KIND.'). Nada foi feito.');
        }

        if (($manifest['expected_count'] ?? null) !== count($manifest['rows']) || collect($manifest['rows'])->contains(fn ($row) => ! is_array($row))) {
            throw new DomainException('Manifesto inconsistente: expected_count diferente da quantidade de linhas ou linha inválida. Nada foi feito.');
        }

        if (blank($manifest['rep_serial'] ?? null)) {
            throw new DomainException('Manifesto sem REP. Nada foi feito.');
        }

        if (! is_file($afdPath) || ! hash_equals((string) ($manifest['afd_sha256'] ?? ''), hash_file('sha256', $afdPath))) {
            throw new DomainException('AFD ausente ou com SHA-256 diferente do manifesto. Nada foi feito.');
        }

        $lines = $this->alignment->readLines($afdPath);

        if ($this->parser->parse($lines[1] ?? '', 1)->repSerial !== $manifest['rep_serial']) {
            throw new DomainException('REP do manifesto diferente do cabeçalho do AFD. Nada foi feito.');
        }

        return [$manifest, hash('sha256', $contents), $lines];
    }
}
