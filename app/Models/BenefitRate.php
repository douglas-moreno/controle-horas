<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\BenefitType;
use Database\Factories\BenefitRateFactory;
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
