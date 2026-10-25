<?php

namespace App\Services;

use App\Enums\AfdRecordType;
use App\Models\AfdCorrectionBatch;
use App\Models\AfdHistoricalNsr;
use App\Models\AfdPointDecision;
use App\Models\Employee;
use App\Models\Point;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Anotações do RH sobre pontos importados do AFD (afd_point_decisions), a partir de um
 * manifesto JSON fixo (`kind: point_annotation`, com o SHA-256 do AFD e o REP). A anotação
 * é só registro: não altera `points` nem os cálculos.
 *
 * Simulação (`preview`, sob AfdPrecheck::whileReadOnly(), nada é gravado). Para cada linha:
 * - a linha do AFD (SHA-256, tipo 3, NSR) e o ponto (`point_id`) gravado com o mesmo REP e
 *   NSR e com o mesmo funcionário, data e horário da linha; o NSR não pode ser pendência
 *   histórica (essas são tratadas por afd:decisions);
 * - o estado: `disregard`/`duplicate` só sem anotação ativa (ou com revogação ativa);
 *   `revoke` só com `disregard`/`duplicate` ativa, com data não anterior à dela;
 * - `duplicate`: `duplicate_of_point_id` existente, outro ponto, do mesmo funcionário, data
 *   e minuto, que não seja ele próprio duplicata anotada; o ponto anotado não pode ser o
 *   original de outra duplicata (sem cadeias);
 * - os dados da decisão: responsável, data (não futura) e justificativa; evidência
 *   documental (tipo e referência) obrigatória em `disregard`.
 * O resultado traz o hash canônico do ponto (AfdHistoricalCorrection::pointHash).
 *
 * Gravação (`apply`): exige o responsável, a identidade esperada do banco e, em cada linha,
 * `expected_point_hash` igual ao hash atual (o ponto não mudou desde a simulação aprovada).
 * Uma transação: trava os pontos, reavalia, cria um lote `point_annotation` e uma anotação
 * ativa por linha (a revogação passa a anterior a `superseded`) e confere que `points` não
 * mudou (impressão digital). Repetir o mesmo manifesto devolve `already_applied`.
 *
 * O relatório não traz PIS: o funcionário aparece pelo id interno.
 */
class AfdPointAnnotations
{
    public const MANIFEST_KIND = 'point_annotation';

    public const STATUS_VALID = 'valid';

    public const STATUS_REFUSED = 'refused';

    public function __construct(
        private AfdParser $parser,
        private AfdLegacyAlignment $alignment,
        private AfdPrecheck $precheck,
        private AfdHistoricalCorrection $correction,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws DomainException
     */
    public function preview(string $manifestPath, string $afdPath): array
    {
        [$manifest, $manifestHash, $lines] = $this->load($manifestPath, $afdPath);

        return [
            'mode' => 'preview',
            'writes' => 'none',
            'manifest_sha256' => $manifestHash,
            'afd_sha256' => $manifest['afd_sha256'],
            'rep_serial' => $manifest['rep_serial'],
            ...$this->precheck->whileReadOnly(fn () => $this->evaluate($manifest, $lines)),
        ];
    }

    /**
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
        $existing = AfdCorrectionBatch::query()->where('kind', AfdCorrectionBatch::KIND_POINT_ANNOTATION)->where('manifest_sha256', $manifestHash)->where('status', AfdCorrectionBatch::STATUS_APPLIED)->first();

        if ($existing !== null) {
            $recorded = AfdPointDecision::query()->where('afd_correction_batch_id', $existing->id)->count();
            $this->refuseUnless($recorded === $existing->applied_count, 'o lote '.$existing->id.' deste manifesto já foi aplicado, mas o estado atual não confere com ele');

            return ['status' => 'already_applied', 'batch_id' => $existing->id, 'rows' => $existing->applied_count];
        }

        $rows = $manifest['rows'];

        foreach ($rows as $index => $row) {
            $this->refuseUnless(filled($row['expected_point_hash'] ?? null), 'linha '.($index + 1).': sem expected_point_hash da simulação aprovada');
        }

        $pointIds = collect($rows)->flatMap(fn (array $row) => [(int) ($row['point_id'] ?? 0), (int) ($row['duplicate_of_point_id'] ?? 0)])->filter()->unique()->values()->all();
        Point::query()->whereIn('id', $pointIds)->lockForUpdate()->get();
        AfdPointDecision::query()->where(fn ($query) => $query->whereIn('point_id', $pointIds)->orWhereIn('duplicate_of_point_id', $pointIds))->lockForUpdate()->get();

        $report = $this->evaluate($manifest, $lines);
        $problems = collect($report['results'])->flatMap(fn (array $result) => array_map(fn (string $error) => 'NSR '.$result['nsr'].': '.$error, $result['errors']));
        $this->refuseUnless($report['status'] === self::STATUS_VALID, $problems->count().' problema(s): '.$problems->take(5)->implode('; '));

        foreach ($report['results'] as $index => $result) {
            $this->refuseUnless(hash_equals((string) $result['point_hash'], (string) $rows[$index]['expected_point_hash']), 'NSR '.$result['nsr'].': ponto diferente do aprovado na simulação (o estado mudou)');
        }

        $before = $this->correction->pointsFingerprint([]);
        $batch = AfdCorrectionBatch::query()->create([
            'kind' => AfdCorrectionBatch::KIND_POINT_ANNOTATION,
            'status' => AfdCorrectionBatch::STATUS_APPLIED,
            'manifest_sha256' => $manifestHash,
            'afd_sha256' => $manifest['afd_sha256'],
            'rep_serial' => $manifest['rep_serial'],
            'expected_count' => $manifest['expected_count'],
            'target' => $target,
            'applied_by' => $operator,
            'applied_at' => now(),
        ]);

        foreach ($report['results'] as $index => $result) {
            $this->record($batch, $manifest['rep_serial'], $rows[$index], $result);
        }

        $after = $this->correction->pointsFingerprint([]);
        $this->ensure(AfdPointDecision::query()->where('afd_correction_batch_id', $batch->id)->where('status', AfdPointDecision::STATUS_ACTIVE)->count() === count($rows), 'quantidade de anotações gravadas diferente do manifesto');
        $this->ensure($after === $before, 'pontos foram alterados');
        $batch->update(['applied_count' => count($rows)]);

        return ['status' => 'applied', 'batch_id' => $batch->id, 'rows' => count($rows), 'by_decision' => $report['by_decision']];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $result
     */
    private function record(AfdCorrectionBatch $batch, string $repSerial, array $row, array $result): void
    {
        $previous = AfdPointDecision::query()->where('point_id', $result['point_id'])->where('status', AfdPointDecision::STATUS_ACTIVE)->first();
        $decision = $result['decision'];

        if ($decision === AfdPointDecision::DECISION_REVOKE) {
            $this->ensure($previous !== null && in_array($previous->decision, AfdPointDecision::MARKING, true), 'ponto '.$result['point_id'].' sem anotação ativa revogável');
        } else {
            $this->ensure($previous === null || $previous->decision === AfdPointDecision::DECISION_REVOKE, 'ponto '.$result['point_id'].' já tem anotação ativa');
        }

        $previous?->update(['status' => AfdPointDecision::STATUS_SUPERSEDED]);

        AfdPointDecision::query()->create([
            'afd_correction_batch_id' => $batch->id,
            'point_id' => $result['point_id'],
            'rep_serial' => $repSerial,
            'nsr' => $result['nsr'],
            'afd_line' => $result['afd_line'],
            'afd_line_sha256' => (string) $row['afd_line_sha256'],
            'point_hash' => $result['point_hash'],
            'decision' => $decision,
            'duplicate_of_point_id' => $result['duplicate_of_point_id'],
            'duplicate_of_point_hash' => $result['duplicate_of_point_hash'],
            'decided_by' => trim((string) $row['decided_by']),
            'decided_role' => filled($row['decided_role'] ?? null) ? trim((string) $row['decided_role']) : null,
            'decided_at' => CarbonImmutable::parse($row['decided_at'])->utc(),
            'justification' => trim((string) $row['justification']),
            'evidence_type' => filled($row['evidence_type'] ?? null) ? trim((string) $row['evidence_type']) : null,
            'evidence_reference' => filled($row['evidence_reference'] ?? null) ? trim((string) $row['evidence_reference']) : null,
            'evidence_sha256' => filled($row['evidence_sha256'] ?? null) ? (string) $row['evidence_sha256'] : null,
            'supersedes_decision_id' => $previous?->id,
            'status' => AfdPointDecision::STATUS_ACTIVE,
        ]);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<int, string>  $lines
     * @return array<string, mixed>
     */
    private function evaluate(array $manifest, array $lines): array
    {
        $rows = $manifest['rows'];
        $pointIds = array_map(fn (array $row) => (int) ($row['point_id'] ?? 0), $rows);
        $context = [
            'rep_serial' => $manifest['rep_serial'],
            'lines' => $lines,
            'repeated_points' => array_keys(array_filter(array_count_values($pointIds), fn (int $count) => $count > 1)),
            'manifest_duplicates' => collect($rows)->filter(fn (array $row) => ($row['decision'] ?? null) === AfdPointDecision::DECISION_DUPLICATE)->pluck('point_id')->map(fn ($id) => (int) $id)->all(),
            'manifest_originals' => collect($rows)->filter(fn (array $row) => ($row['decision'] ?? null) === AfdPointDecision::DECISION_DUPLICATE)->pluck('duplicate_of_point_id')->map(fn ($id) => (int) $id)->all(),
            'employees' => Employee::query()->toBase()->get(['id', 'pis'])->groupBy(fn (object $employee) => ltrim((string) preg_replace('/\D/', '', (string) $employee->pis), '0')),
        ];

        $results = [];

        foreach ($rows as $index => $row) {
            $results[] = $this->evaluateRow($index + 1, $row, $context);
        }

        return [
            'status' => collect($results)->contains(fn (array $result) => $result['errors'] !== []) ? self::STATUS_REFUSED : self::STATUS_VALID,
            'rows' => count($results),
            'by_decision' => collect($results)->countBy('decision')->sortKeys()->all(),
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
        $pointId = (int) ($row['point_id'] ?? 0);
        $decision = $row['decision'] ?? null;
        $errors = [];

        if (in_array($pointId, $context['repeated_points'], true)) {
            $errors[] = 'ponto repetido no manifesto';
        }

        if (! in_array($decision, AfdPointDecision::DECISIONS, true)) {
            $errors[] = 'anotação inválida ('.(is_string($decision) && $decision !== '' ? $decision : 'vazia').')';
            $decision = null;
        }

        [$record, $lineProblem] = $this->lineRecord($row, $context['lines']);
        $point = $this->point($pointId);

        if ($lineProblem !== null) {
            $errors[] = $lineProblem;
        }

        if ($point === null) {
            $errors[] = 'ponto inexistente';
        } elseif ($point->rep_serial !== $context['rep_serial'] || (int) $point->nsr !== $nsr) {
            $errors[] = 'o ponto não é a marcação importada deste REP e NSR';
        } elseif ($record !== null && ((string) $point->pis !== ltrim((string) $record->pis, '0') || $point->date !== $record->date->toDateString() || substr((string) $point->time, 0, 5) !== substr((string) $record->time, 0, 5))) {
            $errors[] = 'o ponto não confere com a linha do AFD (funcionário, data ou horário)';
        }

        if (AfdHistoricalNsr::query()->where('rep_serial', $context['rep_serial'])->where('nsr', $nsr)->exists()) {
            $errors[] = 'NSR registrado como pendência histórica: use afd:decisions';
        }

        $active = $pointId === 0 ? null : AfdPointDecision::query()->where('point_id', $pointId)->where('status', AfdPointDecision::STATUS_ACTIVE)->first();
        $errors = [...$errors, ...$this->stateProblems($active, $row, $decision), ...$this->decisionDataProblems($row, $decision)];
        $original = null;

        if ($decision === AfdPointDecision::DECISION_DUPLICATE) {
            [$original, $duplicateErrors] = $this->duplicateProblems($row, $point, $context);
            $errors = [...$errors, ...$duplicateErrors];
        } elseif (filled($row['duplicate_of_point_id'] ?? null)) {
            $errors[] = 'duplicate_of_point_id só se aplica à anotação duplicate';
        }

        if ($decision === AfdPointDecision::DECISION_DUPLICATE && $point !== null && AfdPointDecision::query()->where('duplicate_of_point_id', $point->id)->where('status', AfdPointDecision::STATUS_ACTIVE)->exists()) {
            $errors[] = 'o ponto é o original de outra batida anotada como duplicada';
        }

        $employees = $point === null ? null : $context['employees']->get((string) $point->pis);

        return [
            'row' => $position,
            'nsr' => $nsr,
            'afd_line' => (int) ($row['afd_line'] ?? 0),
            'point_id' => $pointId,
            'decision' => $decision,
            'active_decision' => $active === null ? null : ['id' => $active->id, 'decision' => $active->decision],
            'employee_id' => $employees !== null && $employees->count() === 1 ? $employees->first()->id : null,
            'date' => $point?->date,
            'time' => $point?->time,
            'duplicate_of_point_id' => $original?->id,
            'errors' => array_values(array_unique($errors)),
            'point_hash' => $point === null ? null : $this->correction->pointHash($point),
            'duplicate_of_point_hash' => $original === null ? null : $this->correction->pointHash($original),
            'effect' => 'none',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $lines
     * @return array{0: ?AfdRecord, 1: ?string}
     */
    private function lineRecord(array $row, array $lines): array
    {
        $lineNumber = (int) ($row['afd_line'] ?? 0);
        $line = $lines[$lineNumber] ?? null;

        if ($line === null || ! hash_equals((string) ($row['afd_line_sha256'] ?? ''), hash('sha256', $line))) {
            return [null, 'linha do AFD ausente ou com SHA-256 diferente'];
        }

        $record = $this->parser->parse($line, $lineNumber);

        return match (true) {
            $record->type !== AfdRecordType::Punch => [null, 'a linha não é marcação (tipo 3)'],
            $record->nsr !== (int) ($row['nsr'] ?? 0) => [null, 'NSR da linha diferente do manifesto'],
            default => [$record, null],
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function stateProblems(?AfdPointDecision $active, array $row, ?string $decision): array
    {
        if ($decision === null) {
            return [];
        }

        if ($decision !== AfdPointDecision::DECISION_REVOKE) {
            return $active !== null && $active->decision !== AfdPointDecision::DECISION_REVOKE ? ['o ponto já tem anotação ativa ('.$active->decision.')'] : [];
        }

        if ($active === null || ! in_array($active->decision, AfdPointDecision::MARKING, true)) {
            return ['não há anotação ativa revogável para o ponto'];
        }

        $decidedAt = $this->date($row['decided_at'] ?? null);

        return $decidedAt !== null && $decidedAt->lt($active->decided_at) ? ['data da revogação anterior à da anotação revogada'] : [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function decisionDataProblems(array $row, ?string $decision): array
    {
        $errors = [];

        if (blank($row['decided_by'] ?? null) || blank($row['justification'] ?? null) || blank($row['decided_at'] ?? null)) {
            $errors[] = 'sem responsável, justificativa ou data da decisão do RH';
        }

        $decidedAt = $this->date($row['decided_at'] ?? null);

        if (filled($row['decided_at'] ?? null) && ($decidedAt === null || $decidedAt->isFuture())) {
            $errors[] = 'data da decisão do RH inválida ou futura';
        }

        if ($decision === AfdPointDecision::DECISION_DISREGARD && (blank($row['evidence_type'] ?? null) || blank($row['evidence_reference'] ?? null))) {
            $errors[] = 'evidência documental obrigatória (tipo e referência)';
        }

        if (filled($row['evidence_sha256'] ?? null) && preg_match('/^[0-9a-f]{64}$/', (string) $row['evidence_sha256']) !== 1) {
            $errors[] = 'SHA-256 da evidência inválido';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $context
     * @return array{0: ?object, 1: list<string>}
     */
    private function duplicateProblems(array $row, ?object $point, array $context): array
    {
        $originalId = (int) ($row['duplicate_of_point_id'] ?? 0);

        if ($originalId === 0) {
            return [null, ['informe o ponto original (duplicate_of_point_id)']];
        }

        $original = $this->point($originalId);
        $errors = match (true) {
            $original === null => ['ponto original inexistente'],
            $originalId === (int) ($row['point_id'] ?? 0) => ['o ponto original não pode ser o próprio ponto'],
            $point !== null && ((string) $original->pis !== (string) $point->pis || $original->date !== $point->date || substr((string) $original->time, 0, 5) !== substr((string) $point->time, 0, 5)) => ['o ponto original não é do mesmo funcionário, data e minuto'],
            default => [],
        };

        if (in_array($originalId, $context['manifest_duplicates'], true) || AfdPointDecision::query()->where('point_id', $originalId)->where('status', AfdPointDecision::STATUS_ACTIVE)->where('decision', AfdPointDecision::DECISION_DUPLICATE)->exists()) {
            $errors[] = 'o ponto original também está anotado como duplicada';
        }

        if (in_array((int) ($row['point_id'] ?? 0), $context['manifest_originals'], true)) {
            $errors[] = 'o ponto é o original de outra batida anotada como duplicada';
        }

        return [$original, $errors];
    }

    private function point(int $id): ?object
    {
        return $id === 0 ? null : $this->correction->canonicalPoints([$id], false)->get($id);
    }

    private function refuseUnless(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new DomainException('Anotações recusadas: '.$reason.'. Nada foi gravado.');
        }
    }

    private function ensure(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new DomainException('Anotações desfeitas: '.$reason.'. Nenhuma alteração foi confirmada.');
        }
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

        if (($manifest['expected_count'] ?? null) !== count($manifest['rows']) || $manifest['rows'] === [] || collect($manifest['rows'])->contains(fn ($row) => ! is_array($row))) {
            throw new DomainException('Manifesto inconsistente: expected_count diferente da quantidade de linhas, sem linhas ou linha inválida. Nada foi feito.');
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
