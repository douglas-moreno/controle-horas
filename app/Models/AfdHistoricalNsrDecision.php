<?php

namespace App\Models;

use Database\Factories\AfdHistoricalNsrDecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Decisão do RH sobre uma pendência histórica do AFD (`afd_historical_nsrs`), em
 * histórico append-only: a decisão nunca é apagada nem editada; a única mudança
 * permitida é `active` → `superseded`, quando uma decisão posterior (revisão ou
 * revogação) a substitui por `supersedes_decision_id`. No máximo uma decisão ativa por
 * pendência (coluna gerada única `active_key`).
 *
 * Guarda quem decidiu (`decided_by`, `decided_role`, `decided_at`), a justificativa, a
 * referência à evidência documental (tipo, referência e SHA-256; o documento fica fora
 * do banco) e a consequência prevista calculada pela simulação, com o hash usado para
 * detectar mudança de estado entre simulação e gravação. A proteção vale para o
 * Eloquent; atualizações diretas pelo query builder não passam por ela.
 */
class AfdHistoricalNsrDecision extends Model
{
    /** @use HasFactory<AfdHistoricalNsrDecisionFactory> */
    use HasFactory;

    public const DECISION_RECOVER = 'recover';

    public const DECISION_RETAIN_MANUAL = 'retain_manual';

    public const DECISION_DISCARD = 'discard';

    public const DECISION_DEFER = 'defer';

    public const DECISION_REVOKE = 'revoke';

    public const DECISIONS = [self::DECISION_RECOVER, self::DECISION_RETAIN_MANUAL, self::DECISION_DISCARD, self::DECISION_DEFER, self::DECISION_REVOKE];

    /**
     * Decisões permitidas por classificação da pendência (revogação depende só do estado).
     * C2 é tratada pelo lote de recuperação; X e C1/C3 não têm ponto manual a manter.
     *
     * @var array<string, list<string>>
     */
    public const PERMISSIONS = [
        'C1' => [self::DECISION_RECOVER, self::DECISION_DISCARD, self::DECISION_DEFER],
        'C3' => [self::DECISION_RECOVER, self::DECISION_DISCARD, self::DECISION_DEFER],
        'E1' => [self::DECISION_RECOVER, self::DECISION_RETAIN_MANUAL, self::DECISION_DISCARD, self::DECISION_DEFER],
        'E2a' => [self::DECISION_RECOVER, self::DECISION_RETAIN_MANUAL, self::DECISION_DISCARD, self::DECISION_DEFER],
        'E2b' => [self::DECISION_RECOVER, self::DECISION_RETAIN_MANUAL, self::DECISION_DISCARD, self::DECISION_DEFER],
        'E2c' => [self::DECISION_RECOVER, self::DECISION_RETAIN_MANUAL, self::DECISION_DISCARD, self::DECISION_DEFER],
        'X' => [self::DECISION_RECOVER, self::DECISION_DISCARD, self::DECISION_DEFER],
    ];

    /**
     * Classificações cuja recuperação exige evidência documental (além do descarte).
     */
    public const RECOVER_REQUIRES_EVIDENCE = ['C1', 'C3', 'X'];

    /**
     * Estado da pendência que cada decisão produz (revogação: volta a `pending`).
     *
     * @var array<string, string>
     */
    public const RESULTING_STATUS = [
        self::DECISION_RECOVER => AfdHistoricalNsr::STATUS_RECOVERED,
        self::DECISION_RETAIN_MANUAL => AfdHistoricalNsr::STATUS_MANUAL_RETAINED,
        self::DECISION_DISCARD => AfdHistoricalNsr::STATUS_DISCARDED,
        self::DECISION_DEFER => AfdHistoricalNsr::STATUS_PENDING,
        self::DECISION_REVOKE => AfdHistoricalNsr::STATUS_PENDING,
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'afd_historical_nsr_id',
        'afd_correction_batch_id',
        'decision',
        'status_before',
        'status_after',
        'related_manual_point_id',
        'recovered_point_id',
        'decided_by',
        'decided_role',
        'decided_at',
        'justification',
        'evidence_type',
        'evidence_reference',
        'evidence_sha256',
        'expected_consequence',
        'expected_consequence_sha256',
        'acknowledged_warnings',
        'review_due_on',
        'review_notes',
        'supersedes_decision_id',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'related_manual_point_id' => 'integer',
            'recovered_point_id' => 'integer',
            'decided_at' => 'immutable_datetime',
            'expected_consequence' => 'array',
            'acknowledged_warnings' => 'array',
            'review_due_on' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $decision) {
            $changed = array_diff(array_keys($decision->getDirty()), ['status', 'updated_at']);

            if ($changed !== [] || $decision->getOriginal('status') !== self::STATUS_ACTIVE || $decision->status !== self::STATUS_SUPERSEDED) {
                throw new LogicException('Decisão do RH não pode ser editada: o histórico é append-only (só active → superseded).');
            }
        });

        static::deleting(function () {
            throw new LogicException('Decisão do RH não pode ser apagada: o histórico é append-only.');
        });
    }

    /**
     * @return BelongsTo<AfdHistoricalNsr, $this>
     */
    public function historicalNsr(): BelongsTo
    {
        return $this->belongsTo(AfdHistoricalNsr::class, 'afd_historical_nsr_id');
    }

    /**
     * @return BelongsTo<AfdCorrectionBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(AfdCorrectionBatch::class, 'afd_correction_batch_id');
    }

    /**
     * @return BelongsTo<AfdHistoricalNsrDecision, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_decision_id');
    }
}
