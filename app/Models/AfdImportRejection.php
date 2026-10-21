<?php

namespace App\Models;

use App\Enums\AfdRejectionReason;
use Database\Factories\AfdImportRejectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marcação do AFD rejeitada individualmente: somente REP, NSR e motivo. Nenhum PIS,
 * nome ou conteúdo da linha é guardado; a linha pode ser localizada no AFD pelo NSR.
 */
class AfdImportRejection extends Model
{
    /** @use HasFactory<AfdImportRejectionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'nsr',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nsr' => 'integer',
            'reason' => AfdRejectionReason::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AfdImport, $this>
     */
    public function afdImport(): BelongsTo
    {
        return $this->belongsTo(AfdImport::class);
    }

    /**
     * @return BelongsTo<AfdRep, $this>
     */
    public function rep(): BelongsTo
    {
        return $this->belongsTo(AfdRep::class, 'afd_rep_id');
    }
}
