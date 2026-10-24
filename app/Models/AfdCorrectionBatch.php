<?php

namespace App\Models;

use Database\Factories\AfdCorrectionBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lote auditável da correção histórica do importador AFD (AfdHistoricalCorrection):
 * registro das pendências históricas, quarentena de pontos indevidos, recuperação
 * de marcações ausentes ou retenção de marcações novas por decisão do RH. Guarda o SHA-256 do manifesto e do AFD, a identidade do banco
 * em que foi aplicado e quem aplicou ou reverteu. Nunca é apagado: a reversão só muda
 * o estado para `reverted`.
 */
class AfdCorrectionBatch extends Model
{
    /** @use HasFactory<AfdCorrectionBatchFactory> */
    use HasFactory;

    public const KIND_REGISTRATION = 'registration';

    public const KIND_QUARANTINE = 'quarantine';

    public const KIND_RECOVERY = 'recovery';

    public const KIND_WITHHOLDING = 'withholding';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REVERTED = 'reverted';

    protected $fillable = [
        'kind',
        'status',
        'manifest_sha256',
        'afd_sha256',
        'rep_serial',
        'expected_count',
        'applied_count',
        'target',
        'applied_by',
        'applied_at',
        'reverted_by',
        'reverted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_count' => 'integer',
            'applied_count' => 'integer',
            'target' => 'array',
            'applied_at' => 'immutable_datetime',
            'reverted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<AfdPointQuarantine, $this>
     */
    public function quarantines(): HasMany
    {
        return $this->hasMany(AfdPointQuarantine::class);
    }

    /**
     * @return HasMany<AfdRecoveredPoint, $this>
     */
    public function recoveredPoints(): HasMany
    {
        return $this->hasMany(AfdRecoveredPoint::class);
    }

    /**
     * @return HasMany<AfdHistoricalNsr, $this>
     */
    public function historicalNsrs(): HasMany
    {
        return $this->hasMany(AfdHistoricalNsr::class);
    }
}
