<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\BenefitType;
use Carbon\CarbonInterface;
use Database\Factories\EmployeeBenefitFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeBenefit extends Model
{
    /** @use HasFactory<EmployeeBenefitFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'benefit_type',
        'starts_on',
        'ends_on',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'benefit_type' => BenefitType::class,
            'starts_on' => DateOnly::class,
            'ends_on' => DateOnly::class,
        ];
    }

    /**
     * Vigências que cobrem a data: início até a data e fim em aberto ou a partir dela.
     *
     * @param  Builder<EmployeeBenefit>  $query
     */
    public function scopeActiveOn(Builder $query, CarbonInterface $date): void
    {
        $query->where('starts_on', '<=', $date->toDateString())
            ->where(fn (Builder $query) => $query
                ->whereNull('ends_on')
                ->orWhere('ends_on', '>=', $date->toDateString()));
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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
