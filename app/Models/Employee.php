<?php

namespace App\Models;

use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    protected $fillable = [
        'pis',
        'name',
        'position',
        'recision_date',
    ];

    public function points(): HasMany
    {
        return $this->hasMany(Point::class, 'pis', 'pis');
    }
}
