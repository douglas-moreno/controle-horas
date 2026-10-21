<?php

namespace App\Models;

use Database\Factories\AfdRepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Relógio (REP) identificado pelo número de fabricação do cabeçalho do AFD. A linha
 * é a âncora da trava das importações do REP e guarda o cursor: `processed_to_nsr`
 * é o maior NSR já examinado (importado, existente ou rejeitado). Null antes da
 * primeira importação concluída.
 */
class AfdRep extends Model
{
    /** @use HasFactory<AfdRepFactory> */
    use HasFactory;

    protected $fillable = [
        'rep_serial',
        'processed_to_nsr',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processed_to_nsr' => 'integer',
        ];
    }

    /**
     * @return HasMany<AfdImport, $this>
     */
    public function imports(): HasMany
    {
        return $this->hasMany(AfdImport::class);
    }

    /**
     * @return HasMany<AfdImportRejection, $this>
     */
    public function rejections(): HasMany
    {
        return $this->hasMany(AfdImportRejection::class);
    }
}
