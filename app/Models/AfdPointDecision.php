<?php

namespace App\Models;

use Database\Factories\AfdPointDecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Anotação do RH sobre um ponto importado do AFD (com REP e NSR), em histórico
 * append-only, no mesmo padrão de AfdHistoricalNsrDecision: nunca apagada nem editada; a
 * única mudança permitida é `active` → `superseded`, quando uma revogação a substitui por
 * `supersedes_decision_id`. No máximo uma anotação ativa por ponto (`active_key`).
 *
 * A anotação é **só registro**: não altera `points` nem os cálculos (espelho, horas
 * extras, benefícios). O espelho e a tela de pontos mostram um marcador.
 * - `disregard`: o RH decidiu desconsiderar a marcação administrativamente.
 * - `duplicate`: batida duplicada de `duplicate_of_point_id` (mesmo funcionário, data e
 *   minuto).
 * - `revoke`: revoga a anotação ativa anterior.
 *
 * Guarda o hash canônico dos pontos no momento da anotação (AfdHistoricalCorrection::pointHash).
 * A proteção vale para o Eloquent; atualizações diretas pelo query builder não passam por ela.
 *
 * As consultas usadas fora dos comandos (espelho, tela de pontos, proteção do ponto,
 * precheck) conferem antes se a tabela existe (`isAvailable`): sem a migration, nada muda.
 */
class AfdPointDecision extends Model
{
    /** @use HasFactory<AfdPointDecisionFactory> */
    use HasFactory;

    public const DECISION_DISREGARD = 'disregard';

    public const DECISION_DUPLICATE = 'duplicate';

    public const DECISION_REVOKE = 'revoke';

    public const DECISIONS = [self::DECISION_DISREGARD, self::DECISION_DUPLICATE, self::DECISION_REVOKE];

    /**
     * Anotações que marcam o ponto (a revogação só encerra a anterior).
     */
    public const MARKING = [self::DECISION_DISREGARD, self::DECISION_DUPLICATE];

    public const LABELS = [
        self::DECISION_DISREGARD => 'Desconsiderada (RH)',
        self::DECISION_DUPLICATE => 'Duplicada (RH)',
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'afd_correction_batch_id',
        'point_id',
        'rep_serial',
        'nsr',
        'afd_line',
        'afd_line_sha256',
        'point_hash',
        'decision',
        'duplicate_of_point_id',
        'duplicate_of_point_hash',
        'decided_by',
        'decided_role',
        'decided_at',
        'justification',
        'evidence_type',
        'evidence_reference',
        'evidence_sha256',
        'supersedes_decision_id',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'point_id' => 'integer',
            'nsr' => 'integer',
            'afd_line' => 'integer',
            'duplicate_of_point_id' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $decision) {
            $changed = array_diff(array_keys($decision->getDirty()), ['status', 'updated_at']);

            if ($changed !== [] || $decision->getOriginal('status') !== self::STATUS_ACTIVE || $decision->status !== self::STATUS_SUPERSEDED) {
                throw new LogicException('Anotação do RH não pode ser editada: o histórico é append-only (só active → superseded).');
            }
        });

        static::deleting(function () {
            throw new LogicException('Anotação do RH não pode ser apagada: o histórico é append-only.');
        });
    }

    /**
     * A tabela existe (migration aplicada). Conferido uma vez por requisição.
     */
    public static function isAvailable(): bool
    {
        return once(fn () => Schema::hasTable((new self)->getTable()));
    }

    /**
     * Rótulo da anotação ativa de cada ponto anotado (ponto id => rótulo).
     *
     * @param  list<int>  $pointIds
     * @return array<int, string>
     */
    public static function activeLabels(array $pointIds): array
    {
        if ($pointIds === [] || ! self::isAvailable()) {
            return [];
        }

        return self::query()
            ->whereIn('point_id', $pointIds)
            ->where('status', self::STATUS_ACTIVE)
            ->whereIn('decision', self::MARKING)
            ->pluck('decision', 'point_id')
            ->map(fn (string $decision) => self::LABELS[$decision])
            ->all();
    }

    /**
     * O ponto é referenciado por uma anotação ativa (como anotado ou como original de
     * uma duplicada).
     */
    public static function references(int $pointId): bool
    {
        return self::isAvailable() && self::query()
            ->where('status', self::STATUS_ACTIVE)
            ->whereIn('decision', self::MARKING)
            ->where(fn ($query) => $query->where('point_id', $pointId)->orWhere('duplicate_of_point_id', $pointId))
            ->exists();
    }

    /**
     * Anotações ativas por tipo.
     *
     * @return array<string, int>
     */
    public static function activeCounts(): array
    {
        if (! self::isAvailable()) {
            return [];
        }

        return self::query()->where('status', self::STATUS_ACTIVE)->whereIn('decision', self::MARKING)
            ->toBase()->selectRaw('decision, count(*) as total')->groupBy('decision')->orderBy('decision')
            ->pluck('total', 'decision')->map(fn ($total) => (int) $total)->all();
    }

    /**
     * @return BelongsTo<Point, $this>
     */
    public function point(): BelongsTo
    {
        return $this->belongsTo(Point::class);
    }

    /**
     * @return BelongsTo<Point, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(Point::class, 'duplicate_of_point_id');
    }

    /**
     * @return BelongsTo<AfdCorrectionBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(AfdCorrectionBatch::class, 'afd_correction_batch_id');
    }

    /**
     * @return BelongsTo<AfdPointDecision, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_decision_id');
    }
}
