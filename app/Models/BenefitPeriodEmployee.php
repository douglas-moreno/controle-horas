<?php

namespace App\Models;

use Database\Factories\BenefitPeriodEmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenefitPeriodEmployee extends Model
{
    /** @use HasFactory<BenefitPeriodEmployeeFactory> */
    use HasFactory;

    protected $fillable = [
        'benefit_period_id',
        'employee_id',
        'employee_name',
        'pis',
        'position',
    ];

    /**
     * @return BelongsTo<BenefitPeriod, $this>
     */
    public function benefitPeriod(): BelongsTo
    {
        return $this->belongsTo(BenefitPeriod::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return HasMany<BenefitCalculation, $this>
     */
    public function calculations(): HasMany
    {
        return $this->hasMany(BenefitCalculation::class);
    }
}
