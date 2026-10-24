<?php

namespace App\Models;

use Database\Factories\AfdHistoricalNsrFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marcação (tipo 3) anterior ao cursor que o importador legado não gravou: pendência
 * histórica conhecida, com classificação da auditoria (C1, C2, C3, E1, E2a, E2b, E2c ou
 * X) e evidência. O estado só sai de `pending` por recuperação (`recovered`, com o
 * ponto gravado) ou por decisão documentada do RH (`manual_retained` ou
 * `discarded_by_approval`, com responsável, justificativa e data). Manter um ponto
 * manual não equivale a uma importação.
 *
 * Retenção (classificação R, estado `withheld`): marcação ainda não importada (NSR acima
 * do cursor) que o RH decidiu não importar, registrada antes da importação pela ação
 * `withhold` com responsável, justificativa, data e evidência da decisão. O importador
 * não a grava (conta em `withheld`) e avança o cursor sobre ela; depois disso a cobertura
 * a mostra como retida, aguardando decisão final, nunca como lacuna. A retenção não
 * confirma nem nega a legitimidade da marcação e não é conclusão sobre fraude ou erro.
 */
class AfdHistoricalNsr extends Model
{
    /** @use HasFactory<AfdHistoricalNsrFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RECOVERED = 'recovered';

    public const STATUS_MANUAL_RETAINED = 'manual_retained';

    public const STATUS_DISCARDED = 'discarded_by_approval';

    public const STATUS_WITHHELD = 'withheld';

    public const CLASSIFICATIONS = ['C1', 'C2', 'C3', 'E1', 'E2a', 'E2b', 'E2c', 'X'];

    /**
     * Marcação retida por decisão do RH; só é registrada pela ação `withhold`.
     */
    public const CLASSIFICATION_WITHHELD = 'R';

    /**
     * Classificações que podem ser recuperadas sem decisão do RH.
     */
    public const AUTOMATICALLY_RECOVERABLE = ['C2'];

    protected $fillable = [
        'rep_serial',
        'nsr',
        'afd_line',
        'afd_line_sha256',
        'classification',
        'evidence',
        'related_manual_point_id',
        'status',
        'recovered_point_id',
        'decided_by',
        'decision_justification',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nsr' => 'integer',
            'afd_line' => 'integer',
            'related_manual_point_id' => 'integer',
            'recovered_point_id' => 'integer',
            'decided_at' => 'immutable_datetime',
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
