<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\JustificationHistoryAction;
use Database\Factories\PointJustificationHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Operação registrada sobre uma justificativa do controle de ponto.
 *
 * Append-only: nunca editada nem apagada pelo Eloquent. Se o usuário for removido,
 * `performed_by` fica nulo (pela chave estrangeira) e o nome copiado continua.
 */
class PointJustificationHistory extends Model
{
    /** @use HasFactory<PointJustificationHistoryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'point_justification_id',
        'action',
        'old_values',
        'new_values',
        'description',
        'manual_point_id',
        'affected_competence',
        'closed_period',
        'performed_by',
        'performed_by_name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => JustificationHistoryAction::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'manual_point_id' => 'integer',
            'affected_competence' => DateOnly::class,
            'closed_period' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Histórico de justificativa não pode ser editado: o registro é append-only.');
        });

        static::deleting(function () {
            throw new LogicException('Histórico de justificativa não pode ser apagado: o registro é append-only.');
        });
    }

    /**
     * @return BelongsTo<PointJustification, $this>
     */
    public function justification(): BelongsTo
    {
        return $this->belongsTo(PointJustification::class, 'point_justification_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
