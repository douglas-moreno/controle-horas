<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\BenefitType;
use Database\Factories\BenefitRateFactory;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenefitRate extends Model
{
    /** @use HasFactory<BenefitRateFactory> */
    use HasFactory;

    protected $fillable = [
        'benefit_type',
        'amount',
        'valid_from',
    ];

    /**
     * Vigências com início até a última competência fechada fazem parte do histórico
     * fechado: não são alteradas nem excluídas. Uma mudança é sempre uma nova vigência.
     */
    protected static function booted(): void
    {
        $guard = function (self $model): void {
            if ($model->isProtectedByClosedPeriod()) {
                throw new DomainException('Este valor de VR/VD é usado por competência fechada e não pode ser alterado nem excluído. Cadastre uma nova vigência.');
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    public function isProtectedByClosedPeriod(): bool
    {
        $lastClosed = BenefitPeriod::lastClosedCompetence();
        $validFrom = $this->getOriginal('valid_from') ?? $this->valid_from;

        return $lastClosed !== null
            && $validFrom !== null
            && $validFrom->toDateString() <= $lastClosed->toDateString();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'benefit_type' => BenefitType::class,
            'amount' => 'decimal:2',
            'valid_from' => DateOnly::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<BenefitCalculation, $this>
     */
    public function calculations(): HasMany
    {
        return $this->hasMany(BenefitCalculation::class);
    }
}
