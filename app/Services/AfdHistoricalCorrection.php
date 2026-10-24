<?php

namespace App\Services;

use App\Enums\AfdRecordType;
use App\Models\AfdCorrectionBatch;
use App\Models\AfdHistoricalNsr;
use App\Models\AfdImportRejection;
use App\Models\AfdPointQuarantine;
use App\Models\AfdRecoveredPoint;
use App\Models\AfdRep;
use App\Models\Point;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use DomainException;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Correção histórica do importador AFD por lotes auditáveis e reversíveis, sempre a
 * partir de um manifesto JSON fixo (com o SHA-256 do AFD) e nunca por filtro amplo.
 *
 * - register: grava as pendências históricas (`afd_historical_nsrs`, estado `pending`).
 * - quarantine: move para `afd_point_quarantines` os pontos do manifesto, conferindo
 *   hash canônico, posição no AFD (AfdLegacyAlignment) e tipo do registro; apaga de
 *   `points` somente esses ids.
 * - recover: grava as marcações do manifesto (somente pendências com classificação
 *   recuperável) como `importado`, com REP e NSR e `afd_import_id` nulo, sem criar REP
 *   nem importação, ligadas ao lote por `afd_recovered_points`.
 * - withhold: registra, antes da importação, marcações novas (NSR acima do cursor) que o
 *   RH decidiu não importar: classificação R, estado `withheld`, com responsável,
 *   justificativa, data e evidência da decisão. Não grava ponto nem mexe no cursor; o
 *   importador as conta em `withheld` (AfdImporter). Não confirma nem nega a
 *   legitimidade da marcação.
 * - restore / revert: desfazem um lote de quarentena ou de recuperação, na ordem inversa
 *   da aplicação (o lote revertido precisa ser o último aplicado). Os registros do lote
 *   são mantidos como evidência.
 *
 * Sem `apply`, tudo é simulado sob AfdPrecheck::whileReadOnly() (sessão somente leitura
 * e bloqueio de escrita). Com `apply`, exige o responsável e a identidade esperada do
 * banco (hostname, server_uuid e nome do banco), recusa o ambiente de produção e executa
 * uma única transação: qualquer divergência desfaz tudo.
 *
 * Hash canônico de um ponto: SHA-256 dos valores, na ordem de POINT_COLUMNS, separados
 * por "\x1F"; NULL vira "\N"; números em decimal; `date` como AAAA-MM-DD e `time` como
 * HH:MM:SS (texto do MySQL); `created_at` e `updated_at` como UNIX_TIMESTAMP (independe
 * do fuso da sessão). As gravações usam a sessão em '+00:00', para copiar os timestamps
 * sem conversão.
 */
class AfdHistoricalCorrection
{
    public const POINT_COLUMNS = ['id', 'pis', 'date', 'time', 'type', 'rep_serial', 'nsr', 'afd_import_id', 'created_at', 'updated_at'];

    private const CHUNK = 1000;

    public function __construct(private AfdParser $parser, private AfdLegacyAlignment $alignment, private AfdPrecheck $precheck, private AfdImporter $importer) {}

    /**
     * Identidade do banco da conexão padrão.
     *
     * @return array{hostname: string, server_uuid: string, database: string, environment: string}
     */
    public function target(): array
    {
        $row = DB::selectOne('select @@hostname as hostname, @@server_uuid as server_uuid, database() as db');

        return ['hostname' => (string) $row->hostname, 'server_uuid' => (string) $row->server_uuid, 'database' => (string) $row->db, 'environment' => (string) app()->environment()];
    }

    /**
     * @param  array{hostname?: ?string, server_uuid?: ?string, database?: ?string}  $expected
     * @return array{hostname: string, server_uuid: string, database: string, environment: string}
     *
     * @throws DomainException
     */
    public function assertTarget(array $expected): array
    {
        if (app()->isProduction()) {
            throw new DomainException('Correção histórica recusada no ambiente de produção. Nada foi gravado.');
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new DomainException('A correção histórica exige MySQL. Nada foi gravado.');
        }

        $target = $this->target();

        foreach (['hostname', 'server_uuid', 'database'] as $key) {
            if (blank($expected[$key] ?? null) || $expected[$key] !== $target[$key]) {
                throw new DomainException('Banco de destino diferente do esperado ('.$key.'). Nada foi gravado.');
            }
        }

        return $target;
    }

    /**
     * @param  object  $row  Colunas de canonicalSelect().
     */
    public function pointHash(object $row): string
    {
        return hash('sha256', implode("\x1F", array_map(
            fn (string $column) => $row->{$column} === null ? '\N' : (string) $row->{$column},
            self::POINT_COLUMNS,
        )));
    }

    /**
     * @param  array{operator?: string, expected?: array<string, ?string>}|null  $apply  null = simulação.
     * @return array<string, mixed>
     */
    public function register(string $manifestPath, string $afdPath, ?array $apply = null): array
    {
        [$manifest, $manifestHash, $lines] = $this->load($manifestPath, $afdPath, AfdCorrectionBatch::KIND_REGISTRATION);

        return $this->execute($apply, function (bool $writing, ?array $target, string $operator) use ($manifest, $manifestHash, $lines) {
            if (($existing = $this->appliedBatch(AfdCorrectionBatch::KIND_REGISTRATION, $manifestHash)) !== null) {
                $registered = AfdHistoricalNsr::query()->where('afd_correction_batch_id', $existing->id)->count();

                return $this->already($existing, $registered === $existing->applied_count);
            }

            $rows = $this->validateRegistration($manifest, $lines);

            if (! $writing) {
                return ['status' => 'valid', 'rows' => count($rows), 'by_classification' => collect($rows)->countBy('classification')->sortKeys()->all()];
            }

            $batch = $this->newBatch(AfdCorrectionBatch::KIND_REGISTRATION, $manifest, $manifestHash, $target, $operator);
            $now = now();

            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                AfdHistoricalNsr::query()->insert(array_map(fn (array $row) => [
                    'afd_correction_batch_id' => $batch->id,
                    'rep_serial' => $manifest['rep_serial'],
                    'nsr' => $row['nsr'],
                    'afd_line' => $row['afd_line'],
                    'afd_line_sha256' => $row['afd_line_sha256'],
                    'classification' => $row['classification'],
                    'evidence' => $row['evidence'],
                    'related_manual_point_id' => $row['related_manual_point_id'] ?? null,
                    'status' => AfdHistoricalNsr::STATUS_PENDING,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            $this->ensure(AfdHistoricalNsr::query()->where('afd_correction_batch_id', $batch->id)->count() === count($rows), 'quantidade de pendências gravadas diferente do manifesto');
            $batch->update(['applied_count' => count($rows)]);

            return ['status' => 'applied', 'batch_id' => $batch->id, 'rows' => count($rows)];
        });
    }

    /**
     * @param  array{operator?: string, expected?: array<string, ?string>}|null  $apply
     * @return array<string, mixed>
     */
    public function quarantine(string $manifestPath, string $afdPath, ?array $apply = null): array
    {
        [$manifest, $manifestHash, $lines] = $this->load($manifestPath, $afdPath, AfdCorrectionBatch::KIND_QUARANTINE);

        return $this->execute($apply, function (bool $writing, ?array $target, string $operator) use ($manifest, $manifestHash, $lines) {
            $ids = array_map(fn (array $row) => (int) $row['id'], $manifest['rows']);

            if (($existing = $this->appliedBatch(AfdCorrectionBatch::KIND_QUARANTINE, $manifestHash)) !== null) {
                $consistent = Point::query()->whereIn('id', $ids)->doesntExist()
                    && AfdPointQuarantine::query()->where('afd_correction_batch_id', $existing->id)->where('status', AfdPointQuarantine::STATUS_QUARANTINED)->count() === count($ids);

                return $this->already($existing, $consistent);
            }

            $current = $this->validateQuarantine($manifest, $lines, $writing);

            if (! $writing) {
                return ['status' => 'valid', 'rows' => count($current), 'by_record_type' => collect($manifest['rows'])->countBy('afd_record_type')->sortKeys()->all()];
            }

            $before = $this->pointsFingerprint($ids);
            $batch = $this->newBatch(AfdCorrectionBatch::KIND_QUARANTINE, $manifest, $manifestHash, $target, $operator);
            $now = now();

            foreach (array_chunk($manifest['rows'], self::CHUNK) as $chunk) {
                AfdPointQuarantine::query()->insert(array_map(function (array $row) use ($current, $batch, $now) {
                    $point = $current[(int) $row['id']];

                    return [
                        'afd_correction_batch_id' => $batch->id,
                        'original_id' => $point->id,
                        'pis' => $point->pis,
                        'date' => $point->date,
                        'time' => $point->time,
                        'type' => $point->type,
                        'rep_serial' => $point->rep_serial,
                        'nsr' => $point->nsr,
                        'afd_import_id' => $point->afd_import_id,
                        'original_created_at' => $point->raw_created_at,
                        'original_updated_at' => $point->raw_updated_at,
                        'row_hash' => $row['row_hash'],
                        'afd_line' => $row['afd_line'],
                        'afd_nsr' => $row['afd_nsr'] ?? null,
                        'afd_record_type' => $row['afd_record_type'],
                        'criterion' => $row['criterion'],
                        'status' => AfdPointQuarantine::STATUS_QUARANTINED,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }, $chunk));
            }

            $copies = $this->quarantinedRows($batch->id, false);
            $this->ensure($copies->count() === count($ids), 'quantidade copiada para a quarentena diferente do manifesto');
            $this->ensure($copies->every(fn (object $copy) => $this->pointHash($copy) === $copy->row_hash), 'cópia na quarentena diferente da linha original');

            $deleted = 0;

            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $deleted += Point::query()->whereIn('id', $chunk)->delete();
            }

            $this->ensure($deleted === count($ids), 'quantidade removida de points diferente do manifesto');
            $after = $this->pointsFingerprint($ids);
            $this->ensure($after['others'] === $before['others'], 'pontos fora do manifesto foram alterados');
            $this->ensure($after['listed'] === 0 && $after['total'] === $before['total'] - count($ids), 'contagem de points inconsistente após a remoção');
            $batch->update(['applied_count' => count($ids)]);

            return ['status' => 'applied', 'batch_id' => $batch->id, 'rows' => count($ids), 'points_before' => $before['total'], 'points_after' => $after['total']];
        });
    }

    /**
     * @param  array{operator?: string, expected?: array<string, ?string>}|null  $apply  null = simulação.
     * @return array<string, mixed>
     */
    public function withhold(string $manifestPath, string $afdPath, ?array $apply = null): array
    {
        [$manifest, $manifestHash, $lines] = $this->load($manifestPath, $afdPath, AfdCorrectionBatch::KIND_WITHHOLDING);

        return $this->execute($apply, function (bool $writing, ?array $target, string $operator) use ($manifest, $manifestHash, $lines, $afdPath) {
            if (($existing = $this->appliedBatch(AfdCorrectionBatch::KIND_WITHHOLDING, $manifestHash)) !== null) {
                $withheld = AfdHistoricalNsr::query()->where('afd_correction_batch_id', $existing->id)->where('status', AfdHistoricalNsr::STATUS_WITHHELD)->count();

                return $this->already($existing, $withheld === $existing->applied_count);
            }

            [$rows, $cursor] = $this->validateWithholding($manifest, $lines, $afdPath, $writing);

            if (! $writing) {
                return ['status' => 'valid', 'rows' => count($rows), 'cursor' => $cursor, 'nsrs' => array_column($rows, 'nsr')];
            }

            $batch = $this->newBatch(AfdCorrectionBatch::KIND_WITHHOLDING, $manifest, $manifestHash, $target, $operator);
            $now = now();

            AfdHistoricalNsr::query()->insert(array_map(fn (array $row) => [
                'afd_correction_batch_id' => $batch->id,
                'rep_serial' => $manifest['rep_serial'],
                'nsr' => $row['nsr'],
                'afd_line' => $row['afd_line'],
                'afd_line_sha256' => $row['afd_line_sha256'],
                'classification' => AfdHistoricalNsr::CLASSIFICATION_WITHHELD,
                'evidence' => $row['evidence'],
                'status' => AfdHistoricalNsr::STATUS_WITHHELD,
                'decided_by' => $row['decided_by'],
                'decision_justification' => $row['decision_justification'],
                'decided_at' => $row['decided_at'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows));

            $this->ensure(AfdHistoricalNsr::query()->where('afd_correction_batch_id', $batch->id)->where('status', AfdHistoricalNsr::STATUS_WITHHELD)->count() === count($rows), 'quantidade de retenções gravadas diferente do manifesto');
            $batch->update(['applied_count' => count($rows)]);

            return ['status' => 'applied', 'batch_id' => $batch->id, 'rows' => count($rows), 'cursor' => $cursor, 'nsrs' => array_column($rows, 'nsr')];
        });
    }

    /**
     * @param  array{operator?: string, expected?: array<string, ?string>}|null  $apply
     * @return array<string, mixed>
     */
    public function restore(int $batchId, ?array $apply = null): array
    {
        return $this->execute($apply, function (bool $writing, ?array $target, string $operator) use ($batchId) {
            $batch = $this->revertibleBatch($batchId, AfdCorrectionBatch::KIND_QUARANTINE, $writing);
            $rows = $this->quarantinedRows($batch->id, $writing);
            $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();

            $this->ensure($rows->count() === $batch->applied_count && $rows->every(fn (object $row) => $row->status === AfdPointQuarantine::STATUS_QUARANTINED), 'quarentena incompleta ou já restaurada');
            $this->ensure($rows->every(fn (object $row) => $this->pointHash($row) === $row->row_hash), 'hash da quarentena divergente: o registro foi alterado depois da quarentena');
            $reused = $this->canonicalPoints($ids, $writing)->count();
            $this->ensure($reused === 0, $reused.' id(s) da quarentena já existem em points');

            if (! $writing) {
                return ['status' => 'valid', 'batch_id' => $batch->id, 'rows' => count($ids)];
            }

            $before = $this->pointsFingerprint($ids);

            foreach ($rows->chunk(self::CHUNK) as $chunk) {
                Point::query()->insert($chunk->map(fn (object $row) => [
                    'id' => $row->id,
                    'pis' => $row->pis,
                    'date' => $row->date,
                    'time' => $row->time,
                    'type' => $row->type,
                    'rep_serial' => $row->rep_serial,
                    'nsr' => $row->nsr,
                    'afd_import_id' => $row->afd_import_id,
                    'created_at' => $row->raw_created_at,
                    'updated_at' => $row->raw_updated_at,
                ])->values()->all());
            }

            $restored = $this->canonicalPoints($ids, false);
            $expected = $rows->keyBy('id');
            $this->ensure($restored->count() === count($ids) && $restored->every(fn (object $point) => $this->pointHash($point) === $expected[$point->id]->row_hash), 'linha restaurada diferente da original');
            $after = $this->pointsFingerprint($ids);
            $this->ensure($after['others'] === $before['others'] && $after['total'] === $before['total'] + count($ids), 'pontos fora do lote foram alterados');

            AfdPointQuarantine::query()->where('afd_correction_batch_id', $batch->id)->update(['status' => AfdPointQuarantine::STATUS_RESTORED, 'restored_by' => $operator, 'restored_at' => now(), 'updated_at' => now()]);
            $batch->update(['status' => AfdCorrectionBatch::STATUS_REVERTED, 'reverted_by' => $operator, 'reverted_at' => now()]);

            return ['status' => 'restored', 'batch_id' => $batch->id, 'rows' => count($ids), 'points_after' => $after['total']];
        });
    }

    /**
     * @param  array{operator?: string, expected?: array<string, ?string>}|null  $apply
     * @return array<string, mixed>
     */
    public function recover(string $manifestPath, string $afdPath, ?array $apply = null): array
    {
        [$manifest, $manifestHash, $lines] = $this->load($manifestPath, $afdPath, AfdCorrectionBatch::KIND_RECOVERY);

        return $this->execute($apply, function (bool $writing, ?array $target, string $operator) use ($manifest, $manifestHash, $lines) {
            $repSerial = $manifest['rep_serial'];
            $nsrs = array_map(fn (array $row) => (int) $row['nsr'], $manifest['rows']);

            if (($existing = $this->appliedBatch(AfdCorrectionBatch::KIND_RECOVERY, $manifestHash)) !== null) {
                $consistent = AfdRecoveredPoint::query()->where('afd_correction_batch_id', $existing->id)->where('status', AfdRecoveredPoint::STATUS_RECOVERED)->count() === count($nsrs)
                    && Point::query()->where('rep_serial', $repSerial)->whereIn('nsr', $nsrs)->count() === count($nsrs);

                return $this->already($existing, $consistent);
            }

            $records = $this->validateRecovery($manifest, $lines, $writing);

            if (! $writing) {
                return ['status' => 'valid', 'rows' => count($records)];
            }

            $before = $this->pointsFingerprint([]);
            $batch = $this->newBatch(AfdCorrectionBatch::KIND_RECOVERY, $manifest, $manifestHash, $target, $operator);
            $now = now();

            try {
                Point::query()->insert(array_map(fn (array $record) => [
                    'pis' => $record['pis'],
                    'date' => $record['date'],
                    'time' => $record['time'],
                    'type' => 'importado',
                    'rep_serial' => $repSerial,
                    'nsr' => $record['nsr'],
                    'afd_import_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $records));
            } catch (UniqueConstraintViolationException) {
                throw new DomainException('Recuperação recusada: NSR já gravado para o REP (gravação concorrente). Nada foi gravado.');
            }

            $inserted = DB::table('points')->select($this->canonicalSelect())->where('rep_serial', $repSerial)->whereIn('nsr', $nsrs)->get()->keyBy('nsr');
            $this->ensure($inserted->count() === count($nsrs) && $inserted->every(fn (object $point) => $point->rep_serial === $repSerial && $point->nsr !== null && $point->afd_import_id === null), 'marcações recuperadas sem REP e NSR únicos');

            AfdRecoveredPoint::query()->insert(array_map(fn (array $record) => [
                'afd_correction_batch_id' => $batch->id,
                'point_id' => $inserted[$record['nsr']]->id,
                'rep_serial' => $repSerial,
                'nsr' => $record['nsr'],
                'afd_line' => $record['afd_line'],
                'afd_line_sha256' => $record['afd_line_sha256'],
                'point_hash' => $this->pointHash($inserted[$record['nsr']]),
                'status' => AfdRecoveredPoint::STATUS_RECOVERED,
                'created_at' => $now,
                'updated_at' => $now,
            ], $records));

            foreach ($records as $record) {
                $updated = AfdHistoricalNsr::query()->where('rep_serial', $repSerial)->where('nsr', $record['nsr'])->where('status', AfdHistoricalNsr::STATUS_PENDING)
                    ->update(['status' => AfdHistoricalNsr::STATUS_RECOVERED, 'recovered_point_id' => $inserted[$record['nsr']]->id, 'updated_at' => $now]);
                $this->ensure($updated === 1, 'pendência do NSR '.$record['nsr'].' não pôde ser marcada como recuperada');
            }

            $after = $this->pointsFingerprint($inserted->pluck('id')->map(fn ($id) => (int) $id)->all());
            $this->ensure($after['others'] === $before['others'] && $after['listed'] === count($nsrs) && $after['total'] === $before['total'] + count($nsrs), 'pontos fora do lote foram alterados ou contagem inconsistente após a recuperação');
            $batch->update(['applied_count' => count($nsrs)]);

            return ['status' => 'applied', 'batch_id' => $batch->id, 'rows' => count($nsrs), 'point_ids' => [min(array_column($inserted->all(), 'id')), max(array_column($inserted->all(), 'id'))]];
        });
    }

    /**
     * @param  array{operator?: string, expected?: array<string, ?string>}|null  $apply
     * @return array<string, mixed>
     */
    public function revert(int $batchId, ?array $apply = null): array
    {
        return $this->execute($apply, function (bool $writing, ?array $target, string $operator) use ($batchId) {
            $batch = $this->revertibleBatch($batchId, AfdCorrectionBatch::KIND_RECOVERY, $writing);
            $recovered = AfdRecoveredPoint::query()->where('afd_correction_batch_id', $batch->id)->when($writing, fn ($query) => $query->lockForUpdate())->get();
            $this->ensure($recovered->count() === $batch->applied_count && $recovered->every(fn (AfdRecoveredPoint $row) => $row->status === AfdRecoveredPoint::STATUS_RECOVERED), 'recuperação incompleta ou já revertida');

            $points = $this->canonicalPoints($recovered->pluck('point_id')->all(), $writing);
            $this->ensure($points->count() === $recovered->count(), 'ponto recuperado ausente de points');
            $this->ensure($recovered->every(fn (AfdRecoveredPoint $row) => $points[$row->point_id]->rep_serial === $row->rep_serial
                && (int) $points[$row->point_id]->nsr === $row->nsr
                && $this->pointHash($points[$row->point_id]) === $row->point_hash), 'ponto recuperado alterado depois da recuperação');

            if (! $writing) {
                return ['status' => 'valid', 'batch_id' => $batch->id, 'rows' => $recovered->count()];
            }

            $before = $this->pointsFingerprint($recovered->pluck('point_id')->all());
            $deleted = Point::query()->whereIn('id', $recovered->pluck('point_id'))->delete();
            $this->ensure($deleted === $recovered->count(), 'quantidade removida diferente do lote');

            foreach ($recovered as $row) {
                $updated = AfdHistoricalNsr::query()->where('rep_serial', $row->rep_serial)->where('nsr', $row->nsr)
                    ->where('status', AfdHistoricalNsr::STATUS_RECOVERED)->where('recovered_point_id', $row->point_id)
                    ->update(['status' => AfdHistoricalNsr::STATUS_PENDING, 'recovered_point_id' => null, 'updated_at' => now()]);
                $this->ensure($updated === 1, 'pendência do NSR '.$row->nsr.' não pôde voltar a pendente');
            }

            $after = $this->pointsFingerprint($recovered->pluck('point_id')->all());
            $this->ensure($after['others'] === $before['others'] && $after['listed'] === 0, 'pontos fora do lote foram alterados');

            AfdRecoveredPoint::query()->where('afd_correction_batch_id', $batch->id)->update(['status' => AfdRecoveredPoint::STATUS_REVERTED, 'reverted_by' => $operator, 'reverted_at' => now(), 'updated_at' => now()]);
            $batch->update(['status' => AfdCorrectionBatch::STATUS_REVERTED, 'reverted_by' => $operator, 'reverted_at' => now()]);

            return ['status' => 'reverted', 'batch_id' => $batch->id, 'rows' => $recovered->count(), 'points_after' => $after['total']];
        });
    }

    /**
     * Simulação somente leitura ou gravação numa transação, conforme `apply`.
     *
     * @param  array{operator?: string, expected?: array<string, ?string>}|null  $apply
     * @param  Closure(bool, ?array<string, string>, string): array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function execute(?array $apply, Closure $operation): array
    {
        if ($apply === null) {
            return ['mode' => 'dry-run', ...$this->precheck->whileReadOnly(fn () => $operation(false, null, ''))];
        }

        $operator = trim((string) ($apply['operator'] ?? ''));

        if ($operator === '') {
            throw new DomainException('Informe o responsável pela operação. Nada foi gravado.');
        }

        $target = $this->assertTarget($apply['expected'] ?? []);
        $timeZone = DB::selectOne('select @@session.time_zone as tz')->tz;
        DB::statement("set time_zone = '+00:00'");

        try {
            return ['mode' => 'apply', 'target' => $target, ...DB::transaction(fn () => $operation(true, $target, $operator))];
        } finally {
            DB::statement('set time_zone = ?', [$timeZone]);
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
     */
    private function load(string $manifestPath, string $afdPath, string $kind): array
    {
        $contents = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
        $manifest = $contents === false ? null : json_decode($contents, true);

        if (! is_array($manifest) || ($manifest['kind'] ?? null) !== $kind || ! is_array($manifest['rows'] ?? null)) {
            throw new DomainException('Manifesto ausente, ilegível ou de outro tipo (esperado: '.$kind.'). Nada foi feito.');
        }

        if (($manifest['expected_count'] ?? null) !== count($manifest['rows'])) {
            throw new DomainException('Manifesto inconsistente: expected_count diferente da quantidade de linhas. Nada foi feito.');
        }

        if (! is_file($afdPath) || ! hash_equals((string) ($manifest['afd_sha256'] ?? ''), hash_file('sha256', $afdPath))) {
            throw new DomainException('AFD ausente ou com SHA-256 diferente do manifesto. Nada foi feito.');
        }

        $lines = $this->alignment->readLines($afdPath);
        $header = $this->parser->parse($lines[1] ?? '', 1);

        if (isset($manifest['rep_serial']) && $header->repSerial !== $manifest['rep_serial']) {
            throw new DomainException('REP do manifesto diferente do cabeçalho do AFD. Nada foi feito.');
        }

        return [$manifest, hash('sha256', $contents), $lines];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<int, string>  $lines
     * @return list<array<string, mixed>>
     */
    private function validateRegistration(array $manifest, array $lines): array
    {
        $rows = $manifest['rows'];
        $nsrs = array_map(fn (array $row) => (int) ($row['nsr'] ?? 0), $rows);
        $this->refuseUnless(count(array_unique($nsrs)) === count($rows), 'NSR repetido no manifesto');

        $verified = $this->alignment->align($lines)['verified'];
        $stored = Point::query()->where('rep_serial', $manifest['rep_serial'])->whereIn('nsr', $nsrs)->count();
        $this->refuseUnless($stored === 0, $stored.' NSR(s) do manifesto já gravados com REP e NSR');
        $registered = AfdHistoricalNsr::query()->where('rep_serial', $manifest['rep_serial'])->whereIn('nsr', $nsrs)->count();
        $this->refuseUnless($registered === 0, $registered.' NSR(s) do manifesto já registrados');
        $manualIds = Point::query()->where('type', 'manual')->whereIn('id', array_filter(array_column($rows, 'related_manual_point_id')))->pluck('id')->flip();
        $errors = [];

        foreach ($rows as $row) {
            $nsr = (int) $row['nsr'];
            $problem = $this->punchProblem($row, $lines)
                ?? match (true) {
                    ! in_array($row['classification'] ?? null, AfdHistoricalNsr::CLASSIFICATIONS, true) => 'classificação inválida',
                    blank($row['evidence'] ?? null) => 'sem evidência',
                    isset($verified[$nsr]) => 'comprovado no legado: não é lacuna',
                    isset($row['related_manual_point_id']) && ! $manualIds->has($row['related_manual_point_id']) => 'ponto manual relacionado inexistente',
                    default => null,
                };

            if ($problem !== null) {
                $errors[] = 'NSR '.$nsr.': '.$problem;
            }
        }

        $this->refuseUnless($errors === [], count($errors).' divergência(s): '.implode('; ', array_slice($errors, 0, 5)));

        return $rows;
    }

    /**
     * Retenção: o AFD inteiro passa pela validação estrutural do importador (NSR contínuo,
     * trailer e contadores); cada linha é uma marcação válida com NSR acima do cursor
     * atual do REP (AfdImporter::startFor), ainda não gravada, rejeitada nem registrada,
     * com classificação R e o registro formal da decisão do RH.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<int, string>  $lines
     * @return array{0: list<array{nsr: int, afd_line: int, afd_line_sha256: string, evidence: string, decided_by: string, decision_justification: string, decided_at: string}>, 1: int}
     */
    private function validateWithholding(array $manifest, array $lines, string $afdPath, bool $lock): array
    {
        $repSerial = $manifest['rep_serial'] ?? null;
        $this->refuseUnless(filled($repSerial), 'manifesto sem REP');
        $rows = $manifest['rows'];
        $nsrs = array_map(fn (array $row) => (int) ($row['nsr'] ?? 0), $rows);
        $this->refuseUnless(count(array_unique($nsrs)) === count($rows), 'NSR repetido no manifesto');

        $scan = $this->importer->scan($afdPath);
        $rep = AfdRep::query()->where('rep_serial', $repSerial)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $cursor = $this->importer->startFor($rep, $afdPath, $scan)['cursor'];

        $stored = Point::query()->where('rep_serial', $repSerial)->whereIn('nsr', $nsrs)->count();
        $this->refuseUnless($stored === 0, $stored.' NSR(s) do manifesto já gravados com REP e NSR');
        $registered = AfdHistoricalNsr::query()->where('rep_serial', $repSerial)->whereIn('nsr', $nsrs)->count();
        $this->refuseUnless($registered === 0, $registered.' NSR(s) do manifesto já registrados');
        $rejected = $rep === null ? 0 : AfdImportRejection::query()->where('afd_rep_id', $rep->id)->whereIn('nsr', $nsrs)->count();
        $this->refuseUnless($rejected === 0, $rejected.' NSR(s) do manifesto já rejeitados');

        $valid = [];
        $errors = [];

        foreach ($rows as $row) {
            $nsr = (int) $row['nsr'];
            $decidedAt = $this->decisionDate($row['decided_at'] ?? null);
            $problem = $this->punchProblem($row, $lines) ?? match (true) {
                ($row['classification'] ?? null) !== AfdHistoricalNsr::CLASSIFICATION_WITHHELD => 'classificação '.($row['classification'] ?? '(vazia)').' não é retenção ('.AfdHistoricalNsr::CLASSIFICATION_WITHHELD.')',
                blank($row['evidence'] ?? null) || blank($row['decided_by'] ?? null) || blank($row['decision_justification'] ?? null) || blank($row['decided_at'] ?? null) => 'sem registro formal da decisão do RH (responsável, justificativa, data e evidência)',
                $decidedAt === null => 'data da decisão do RH inválida ou futura',
                $nsr <= $cursor => 'NSR até o cursor '.$cursor.': não é marcação nova',
                default => null,
            };

            if ($problem !== null) {
                $errors[] = 'NSR '.$nsr.': '.$problem;

                continue;
            }

            $valid[] = [
                'nsr' => $nsr,
                'afd_line' => (int) $row['afd_line'],
                'afd_line_sha256' => (string) $row['afd_line_sha256'],
                'evidence' => trim((string) $row['evidence']),
                'decided_by' => trim((string) $row['decided_by']),
                'decision_justification' => trim((string) $row['decision_justification']),
                'decided_at' => $decidedAt,
            ];
        }

        $this->refuseUnless($errors === [], count($errors).' divergência(s): '.implode('; ', array_slice($errors, 0, 5)));

        return [$valid, $cursor];
    }

    /**
     * Data da decisão do RH em UTC (AAAA-MM-DD HH:MM:SS), ou null se inválida ou futura.
     */
    private function decisionDate(mixed $value): ?string
    {
        if (! is_string($value) || blank($value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($value)->utc();
        } catch (InvalidFormatException) {
            return null;
        }

        return $date->isFuture() ? null : $date->format('Y-m-d H:i:s');
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<int, string>  $lines
     * @return array<int, object>
     */
    private function validateQuarantine(array $manifest, array $lines, bool $lock): array
    {
        $ids = array_map(fn (array $row) => (int) ($row['id'] ?? 0), $manifest['rows']);
        $this->refuseUnless(count(array_unique($ids)) === count($ids), 'id repetido no manifesto');

        $positions = $this->alignment->align($lines)['positions'];
        $current = $this->canonicalPoints($ids, $lock)->all();
        $this->refuseUnless(count($current) === count($ids), (count($ids) - count($current)).' id(s) do manifesto ausentes de points');
        $errors = [];

        foreach ($manifest['rows'] as $row) {
            $point = $current[(int) $row['id']];
            $position = $positions[$point->id] ?? null;
            $line = $lines[(int) ($row['afd_line'] ?? 0)] ?? null;
            $fields = $line === null ? null : $this->alignment->legacyFields($line);

            $problem = match (true) {
                ! hash_equals((string) ($row['row_hash'] ?? ''), $this->pointHash($point)) => 'hash diferente do manifesto',
                $point->type !== 'importado' || $point->nsr !== null || $point->rep_serial !== null => 'não é ponto do importador legado',
                blank($row['criterion'] ?? null) => 'sem critério',
                $position === null || $position['line'] !== (int) $row['afd_line'] => 'posição no AFD diferente do manifesto',
                ($row['afd_record_type'] ?? null) === AfdRecordType::Trailer->value => $position['last'] && $point->date < '1000-01-01' && ! isset($row['afd_nsr']) ? null : 'não é o trailer da execução',
                ! in_array($row['afd_record_type'] ?? null, [AfdRecordType::Company->value, AfdRecordType::ClockAdjustment->value, AfdRecordType::Employee->value], true) => 'tipo de registro não removível',
                $line === null || substr($line, 9, 1) !== $row['afd_record_type'] || (int) substr($line, 0, 9) !== (int) ($row['afd_nsr'] ?? -1) => 'tipo ou NSR da linha diferente do manifesto',
                $fields === null || [$fields['date'], $fields['time'], $fields['pis']] !== [(string) $point->date, (string) $point->time, (string) $point->pis] => 'data, hora ou PIS diferente da transformação legada da linha',
                default => null,
            };

            if ($problem !== null) {
                $errors[] = 'id '.$point->id.': '.$problem;
            }
        }

        $this->refuseUnless($errors === [], count($errors).' divergência(s): '.implode('; ', array_slice($errors, 0, 5)));

        return $current;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<int, string>  $lines
     * @return list<array{nsr: int, afd_line: int, afd_line_sha256: string, pis: int, date: string, time: string}>
     */
    private function validateRecovery(array $manifest, array $lines, bool $lock): array
    {
        $repSerial = $manifest['rep_serial'] ?? null;
        $this->refuseUnless(filled($repSerial), 'manifesto sem REP');
        $nsrs = array_map(fn (array $row) => (int) ($row['nsr'] ?? 0), $manifest['rows']);
        $this->refuseUnless(count(array_unique($nsrs)) === count($nsrs), 'NSR repetido no manifesto');

        $registry = AfdHistoricalNsr::query()->where('rep_serial', $repSerial)->whereIn('nsr', $nsrs)->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('nsr');
        $stored = Point::query()->where('rep_serial', $repSerial)->whereIn('nsr', $nsrs)->when($lock, fn ($query) => $query->lockForUpdate())->count();
        $this->refuseUnless($stored === 0, $stored.' NSR(s) já gravados com REP e NSR');
        $verified = $this->alignment->align($lines)['verified'];
        $records = [];
        $errors = [];

        foreach ($manifest['rows'] as $row) {
            $nsr = (int) $row['nsr'];
            $entry = $registry->get($nsr);
            $problem = $this->punchProblem($row, $lines) ?? match (true) {
                $entry === null => 'sem pendência histórica registrada',
                $entry->status !== AfdHistoricalNsr::STATUS_PENDING => 'pendência em estado '.$entry->status,
                ! in_array($entry->classification, AfdHistoricalNsr::AUTOMATICALLY_RECOVERABLE, true) || $entry->classification !== ($row['classification'] ?? null) => 'classificação '.$entry->classification.' não permite recuperação automática',
                ! hash_equals($entry->afd_line_sha256, (string) $row['afd_line_sha256']) || $entry->afd_line !== (int) $row['afd_line'] => 'linha diferente da pendência registrada',
                isset($verified[$nsr]) => 'comprovado no legado: não é lacuna',
                default => null,
            };

            if ($problem !== null) {
                $errors[] = 'NSR '.$nsr.': '.$problem;

                continue;
            }

            $record = $this->parser->parse($lines[(int) $row['afd_line']], (int) $row['afd_line']);
            $records[] = ['nsr' => $nsr, 'afd_line' => (int) $row['afd_line'], 'afd_line_sha256' => (string) $row['afd_line_sha256'], 'pis' => (int) $record->pis, 'date' => $record->date->toDateString(), 'time' => $record->time];
        }

        $this->refuseUnless($errors === [], count($errors).' divergência(s): '.implode('; ', array_slice($errors, 0, 5)));

        foreach ($records as $record) {
            $sameKey = Point::query()->where('pis', $record['pis'])->whereDate('date', $record['date'])->where('time', 'like', substr($record['time'], 0, 5).'%')->count();
            $this->refuseUnless($sameKey === 0, 'NSR '.$record['nsr'].': já existe ponto com o mesmo PIS, data e minuto');
        }

        return $records;
    }

    /**
     * Linha do manifesto confere com uma marcação válida do AFD com o mesmo NSR.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $lines
     */
    private function punchProblem(array $row, array $lines): ?string
    {
        $lineNumber = (int) ($row['afd_line'] ?? 0);
        $line = $lines[$lineNumber] ?? null;

        if ($line === null || ! hash_equals((string) ($row['afd_line_sha256'] ?? ''), hash('sha256', $line))) {
            return 'linha do AFD ausente ou com SHA-256 diferente';
        }

        $record = $this->parser->parse($line, $lineNumber);

        return match (true) {
            $record->type !== AfdRecordType::Punch => 'a linha não é marcação (tipo 3)',
            $record->nsr !== (int) $row['nsr'] => 'NSR da linha diferente do manifesto',
            $record->rejection !== null => 'marcação inválida ('.$record->rejection->value.')',
            default => null,
        };
    }

    /**
     * @return list<Expression|string>
     */
    private function canonicalSelect(): array
    {
        return [
            'id', 'pis', 'date', 'time', 'type', 'rep_serial', 'nsr', 'afd_import_id',
            DB::raw('unix_timestamp(created_at) as created_at'),
            DB::raw('unix_timestamp(updated_at) as updated_at'),
            DB::raw('created_at as raw_created_at'),
            DB::raw('updated_at as raw_updated_at'),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, object>
     */
    private function canonicalPoints(array $ids, bool $lock): Collection
    {
        $points = collect();

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $points = $points->concat(DB::table('points')->select($this->canonicalSelect())->whereIn('id', $chunk)->when($lock, fn ($query) => $query->lockForUpdate())->get());
        }

        return $points->keyBy('id');
    }

    /**
     * @return Collection<int, object>
     */
    private function quarantinedRows(int $batchId, bool $lock): Collection
    {
        return DB::table('afd_point_quarantines')
            ->select([
                'original_id as id', 'pis', 'date', 'time', 'type', 'rep_serial', 'nsr', 'afd_import_id',
                DB::raw('unix_timestamp(original_created_at) as created_at'),
                DB::raw('unix_timestamp(original_updated_at) as updated_at'),
                DB::raw('original_created_at as raw_created_at'),
                DB::raw('original_updated_at as raw_updated_at'),
                'row_hash', 'status',
            ])
            ->where('afd_correction_batch_id', $batchId)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderBy('original_id')
            ->get();
    }

    /**
     * Total de points, quantos dos ids informados existem e o hash de todos os demais.
     *
     * @param  list<int>  $ids
     * @return array{total: int, listed: int, others: string}
     */
    private function pointsFingerprint(array $ids): array
    {
        $listed = array_flip($ids);
        $context = hash_init('sha256');
        $total = 0;
        $found = 0;

        foreach (DB::table('points')->select(array_slice($this->canonicalSelect(), 0, 10))->orderBy('id')->cursor() as $point) {
            $total++;

            if (isset($listed[$point->id])) {
                $found++;

                continue;
            }

            hash_update($context, $this->pointHash($point)."\n");
        }

        return ['total' => $total, 'listed' => $found, 'others' => hash_final($context)];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $target
     */
    private function newBatch(string $kind, array $manifest, string $manifestHash, array $target, string $operator): AfdCorrectionBatch
    {
        return AfdCorrectionBatch::query()->create([
            'kind' => $kind,
            'status' => AfdCorrectionBatch::STATUS_APPLIED,
            'manifest_sha256' => $manifestHash,
            'afd_sha256' => $manifest['afd_sha256'],
            'rep_serial' => $manifest['rep_serial'] ?? null,
            'expected_count' => $manifest['expected_count'],
            'target' => $target,
            'applied_by' => $operator,
            'applied_at' => now(),
        ]);
    }

    private function appliedBatch(string $kind, string $manifestHash): ?AfdCorrectionBatch
    {
        return AfdCorrectionBatch::query()->where('kind', $kind)->where('manifest_sha256', $manifestHash)->where('status', AfdCorrectionBatch::STATUS_APPLIED)->first();
    }

    /**
     * Lote de quarentena ou recuperação aplicado e que é o último ainda aplicado.
     */
    private function revertibleBatch(int $batchId, string $kind, bool $lock): AfdCorrectionBatch
    {
        $batch = AfdCorrectionBatch::query()->whereKey($batchId)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $this->refuseUnless($batch !== null && $batch->kind === $kind && $batch->status === AfdCorrectionBatch::STATUS_APPLIED, 'lote '.$batchId.' inexistente, de outro tipo ou já revertido');

        $later = AfdCorrectionBatch::query()->whereIn('kind', [AfdCorrectionBatch::KIND_QUARANTINE, AfdCorrectionBatch::KIND_RECOVERY])
            ->where('status', AfdCorrectionBatch::STATUS_APPLIED)->where('id', '>', $batch->id)->pluck('id');
        $this->refuseUnless($later->isEmpty(), 'reverta antes o(s) lote(s) posterior(es) '.$later->implode(', '));

        return $batch;
    }

    /**
     * @return array<string, mixed>
     */
    private function already(AfdCorrectionBatch $batch, bool $consistent): array
    {
        $this->refuseUnless($consistent, 'o lote '.$batch->id.' deste manifesto já foi aplicado, mas o estado atual não confere com ele');

        return ['status' => 'already_applied', 'batch_id' => $batch->id, 'rows' => $batch->applied_count];
    }

    private function refuseUnless(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new DomainException('Correção recusada: '.$reason.'. Nada foi gravado.');
        }
    }

    private function ensure(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new DomainException('Correção desfeita: '.$reason.'. Nenhuma alteração foi confirmada.');
        }
    }
}
