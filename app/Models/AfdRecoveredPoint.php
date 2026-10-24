<?php

namespace App\Models;

use Database\Factories\AfdRecoveredPointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marcação recuperada do AFD por um lote de recuperação: o ponto gravado (id, REP e
 * NSR), a linha de origem com o SHA-256 do conteúdo e o hash canônico do ponto no
 * momento da gravação. A reversão apaga o ponto e marca o registro como `reverted`.
 */
class AfdRecoveredPoint extends Model
{
    /** @use HasFactory<AfdRecoveredPointFactory> */
    use HasFactory;

    public const STATUS_RECOVERED = 'recovered';

    public const STATUS_REVERTED = 'reverted';

    protected $fillable = [
        'point_id',
        'rep_serial',
        'nsr',
        'afd_line',
        'afd_line_sha256',
        'point_hash',
        'status',
        'reverted_by',
        'reverted_at',
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
            'reverted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AfdCorrectionBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(AfdCorrectionBatch::class, 'afd_correction_batch_id');
    }
}
