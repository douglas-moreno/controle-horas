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

    /**
     * @return HasMany<EmployeeBenefit, $this>
     */
    public function employeeBenefits(): HasMany
    {
        return $this->hasMany(EmployeeBenefit::class);
    }

    /**
     * @return HasMany<TransportRoute, $this>
     */
    public function transportRoutes(): HasMany
    {
        return $this->hasMany(TransportRoute::class);
    }

    /**
     * @return HasMany<BenefitAdjustment, $this>
     */
    public function benefitAdjustments(): HasMany
    {
        return $this->hasMany(BenefitAdjustment::class);
    }

    /**
     * @return HasMany<BenefitPeriodEmployee, $this>
     */
    public function benefitPeriodEmployees(): HasMany
    {
        return $this->hasMany(BenefitPeriodEmployee::class);
    }

    /**
     * Indica se o funcionário possui registros do módulo de benefícios, que impedem a
     * exclusão física. Batidas de ponto não contam como histórico de benefício.
     */
    public function hasBenefitHistory(): bool
    {
        return $this->employeeBenefits()->exists()
            || $this->transportRoutes()->exists()
            || $this->benefitAdjustments()->exists()
            || $this->benefitPeriodEmployees()->exists();
    }
}
