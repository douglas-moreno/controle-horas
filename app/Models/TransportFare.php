<?php

namespace App\Models;

use Database\Factories\TransportFareFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportFare extends Model
{
    /** @use HasFactory<TransportFareFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'operator',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<TransportFarePrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(TransportFarePrice::class);
    }

    /**
     * @return HasMany<TransportRoute, $this>
     */
    public function routes(): HasMany
    {
        return $this->hasMany(TransportRoute::class);
    }

    /**
     * @return HasMany<BenefitCalculationTransportItem, $this>
     */
    public function calculationItems(): HasMany
    {
        return $this->hasMany(BenefitCalculationTransportItem::class);
    }
}
