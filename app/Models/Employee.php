<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'position',
        'salary',
        'is_active',
    ];

    public function points(): HasMany
    {
        return $this->hasMany(Point::class);
    }
}
