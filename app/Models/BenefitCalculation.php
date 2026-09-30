<?php

namespace App\Models;

use App\Enums\BenefitType;
use Database\Factories\BenefitCalculationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BenefitCalculation extends Model
{
    /** @use HasFactory<BenefitCalculationFactory> */
    use HasFactory;

    protected $fillable = [
        'benefit_period_employee_id',
        'benefit_type',
        'base_days',
        'positive_days',
        'negative_days',
        'carried_in_days',
        'carried_from_calculation_id',
        'raw_days',
        'final_days',
        'carried_out_days',
        'unit_amount',
        'total_amount',
        'benefit_rate_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'benefit_type' => BenefitType::class,
            'base_days' => 'integer',
            'positive_days' => 'integer',
            'negative_days' => 'integer',
            'carried_in_days' => 'integer',
            'raw_days' => 'integer',
            'final_days' => 'integer',
            'carried_out_days' => 'integer',
            'unit_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<BenefitPeriodEmployee, $this>
     */
    public function benefitPeriodEmployee(): BelongsTo
    {
        return $this->belongsTo(BenefitPeriodEmployee::class);
    }

    /**
     * Cálculo da competência anterior cujo saldo negativo foi aplicado aqui.
     *
     * @return BelongsTo<BenefitCalculation, $this>
     */
    public function carriedFromCalculation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'carried_from_calculation_id');
    }

    /**
     * Cálculo da competência seguinte que aplicou o saldo negativo gerado aqui.
     *
     * @return HasOne<BenefitCalculation, $this>
     */
    public function carriedToCalculation(): HasOne
    {
        return $this->hasOne(self::class, 'carried_from_calculation_id');
    }

    /**
     * @return BelongsTo<BenefitRate, $this>
     */
    public function benefitRate(): BelongsTo
    {
        return $this->belongsTo(BenefitRate::class);
    }

    /**
     * @return HasMany<BenefitCalculationTransportItem, $this>
     */
    public function transportItems(): HasMany
    {
        return $this->hasMany(BenefitCalculationTransportItem::class);
    }
}
