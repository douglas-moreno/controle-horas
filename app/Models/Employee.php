<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'pis',
        'name',
        'position',
        'recision_date',
    ];

    public function points(): HasMany
    {
        return $this->hasMany(Point::class);
    }
}
