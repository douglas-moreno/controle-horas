<?php

namespace App\Models;

use App\Enums\BenefitPeriodStatus;
use Database\Factories\BenefitPeriodStatusChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BenefitPeriodStatusChange extends Model
{
    /** @use HasFactory<BenefitPeriodStatusChangeFactory> */
    use HasFactory;

    /**
     * Registro somente de inserção: não há coluna updated_at.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'benefit_period_id',
        'from_status',
        'to_status',
        'reason',
    ];

    /**
     * O histórico é somente de inserção: registros existentes não são alterados nem
     * excluídos individualmente. A remoção só ocorre em cascata com a competência.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('O histórico de status da competência não pode ser alterado.'));
        static::deleting(fn () => throw new LogicException('O histórico de status da competência não pode ser excluído.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => BenefitPeriodStatus::class,
            'to_status' => BenefitPeriodStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<BenefitPeriod, $this>
     */
    public function benefitPeriod(): BelongsTo
    {
        return $this->belongsTo(BenefitPeriod::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
