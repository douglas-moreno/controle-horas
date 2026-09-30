<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\BenefitPeriodStatus;
use Database\Factories\BenefitPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenefitPeriod extends Model
{
    /** @use HasFactory<BenefitPeriodFactory> */
    use HasFactory;

    protected $fillable = [
        'competence',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'competence' => DateOnly::class,
            'status' => BenefitPeriodStatus::class,
            'business_days' => 'integer',
            'calculated_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<BenefitPeriodStatusChange, $this>
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(BenefitPeriodStatusChange::class);
    }

    /**
     * @return HasMany<BenefitAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(BenefitAdjustment::class);
    }

    /**
     * @return HasMany<BenefitPeriodEmployee, $this>
     */
    public function periodEmployees(): HasMany
    {
        return $this->hasMany(BenefitPeriodEmployee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function calculatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
