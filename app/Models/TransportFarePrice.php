<?php

namespace App\Models;

use App\Casts\DateOnly;
use Database\Factories\TransportFarePriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportFarePrice extends Model
{
    /** @use HasFactory<TransportFarePriceFactory> */
    use HasFactory;

    protected $fillable = [
        'transport_fare_id',
        'amount',
        'valid_from',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'valid_from' => DateOnly::class,
        ];
    }

    /**
     * @return BelongsTo<TransportFare, $this>
     */
    public function transportFare(): BelongsTo
    {
        return $this->belongsTo(TransportFare::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
