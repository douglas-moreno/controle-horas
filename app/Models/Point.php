<?php

namespace App\Models;

use Database\Factories\PointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Point extends Model
{
    /** @use HasFactory<PointFactory> */
    use HasFactory;

    protected $fillable = [
        'pis',
        'date',
        'time',
        'type',
    ];

    protected $casts = [
        'date' => 'date',
        'time' => 'datetime:H:i',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'pis', 'pis');
    }
}
