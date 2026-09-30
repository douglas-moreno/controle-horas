<?php

namespace App\Models;

use Database\Factories\BenefitCalculationTransportItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitCalculationTransportItem extends Model
{
    /** @use HasFactory<BenefitCalculationTransportItemFactory> */
    use HasFactory;

    protected $fillable = [
        'benefit_calculation_id',
        'transport_route_id',
        'transport_fare_id',
        'fare_name',
        'fare_amount',
        'trips_per_day',
        'daily_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fare_amount' => 'decimal:2',
            'trips_per_day' => 'integer',
            'daily_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<BenefitCalculation, $this>
     */
    public function calculation(): BelongsTo
    {
        return $this->belongsTo(BenefitCalculation::class, 'benefit_calculation_id');
    }

    /**
     * @return BelongsTo<TransportRoute, $this>
     */
    public function transportRoute(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class);
    }

    /**
     * @return BelongsTo<TransportFare, $this>
     */
    public function transportFare(): BelongsTo
    {
        return $this->belongsTo(TransportFare::class);
    }
}
