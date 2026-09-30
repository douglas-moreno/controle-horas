<?php

namespace App\Models;

use App\Enums\BenefitType;
use Database\Factories\BenefitAdjustmentImpactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitAdjustmentImpact extends Model
{
    /** @use HasFactory<BenefitAdjustmentImpactFactory> */
    use HasFactory;

    protected $fillable = [
        'benefit_adjustment_id',
        'benefit_type',
        'quantity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'benefit_type' => BenefitType::class,
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BenefitAdjustment, $this>
     */
    public function benefitAdjustment(): BelongsTo
    {
        return $this->belongsTo(BenefitAdjustment::class);
    }
}
