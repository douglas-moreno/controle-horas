<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\AdjustmentStatus;
use Database\Factories\BenefitAdjustmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenefitAdjustment extends Model
{
    /** @use HasFactory<BenefitAdjustmentFactory> */
    use HasFactory;

    protected $fillable = [
        'benefit_period_id',
        'employee_id',
        'reason',
        'source',
        'starts_on',
        'ends_on',
        'days_count',
        'notes',
        'dedupe_key',
        'related_adjustment_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => AdjustmentReason::class,
            'source' => AdjustmentSource::class,
            'status' => AdjustmentStatus::class,
            'starts_on' => DateOnly::class,
            'ends_on' => DateOnly::class,
            'days_count' => 'integer',
            'reviewed_at' => 'immutable_datetime',
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
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return HasMany<BenefitAdjustmentImpact, $this>
     */
    public function impacts(): HasMany
    {
        return $this->hasMany(BenefitAdjustmentImpact::class);
    }

    /**
     * Ajuste original que este substitui ou corrige.
     *
     * @return BelongsTo<BenefitAdjustment, $this>
     */
    public function relatedAdjustment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_adjustment_id');
    }

    /**
     * Ajustes que substituem ou corrigem este.
     *
     * @return HasMany<BenefitAdjustment, $this>
     */
    public function relatedAdjustments(): HasMany
    {
        return $this->hasMany(self::class, 'related_adjustment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
