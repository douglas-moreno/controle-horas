<?php

namespace App\Models;

use Database\Factories\AfdPointQuarantineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cópia integral de um ponto removido de `points` pela quarentena: as dez colunas
 * originais (id em `original_id`, timestamps em `original_*`), o hash canônico da linha
 * e a evidência no AFD (linha, NSR, tipo do registro e critério). A restauração devolve
 * a linha com o mesmo id e marca o registro como `restored`, sem apagá-lo.
 */
class AfdPointQuarantine extends Model
{
    /** @use HasFactory<AfdPointQuarantineFactory> */
    use HasFactory;

    public const STATUS_QUARANTINED = 'quarantined';

    public const STATUS_RESTORED = 'restored';

    protected $fillable = [
        'original_id',
        'pis',
        'date',
        'time',
        'type',
        'rep_serial',
        'nsr',
        'afd_import_id',
        'original_created_at',
        'original_updated_at',
        'row_hash',
        'afd_line',
        'afd_nsr',
        'afd_record_type',
        'criterion',
        'status',
        'restored_by',
        'restored_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'original_id' => 'integer',
            'afd_line' => 'integer',
            'afd_nsr' => 'integer',
            'restored_at' => 'immutable_datetime',
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
