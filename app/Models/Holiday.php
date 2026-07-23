<?php

namespace App\Models;

use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory;

    protected $fillable = [
        'date',
        'description',
    ];

    /**
     * Feriados carregados uma única vez por request, evitando uma consulta por
     * dia calculado nos relatórios.
     *
     * @var Collection<string, string>|null
     */
    protected static ?Collection $cachedHolidays = null;

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCachedHolidays());
        static::deleted(fn () => static::flushCachedHolidays());
    }

    /**
     * Mantém a coluna gravada como data pura (Y-m-d), garantindo que buscas e
     * a validação de duplicidade comparem exatamente o mesmo formato.
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => Carbon::parse($value),
            set: fn (mixed $value) => Carbon::parse($value)->format('Y-m-d'),
        );
    }

    public static function isHoliday(mixed $date): bool
    {
        $key = static::dateKey($date);

        return $key !== null && static::cachedHolidays()->has($key);
    }

    public static function descriptionFor(mixed $date): ?string
    {
        $key = static::dateKey($date);

        return $key === null ? null : static::cachedHolidays()->get($key);
    }

    /**
     * @return Collection<string, string>
     */
    public static function cachedHolidays(): Collection
    {
        return static::$cachedHolidays ??= static::query()
            ->orderBy('date')
            ->get(['date', 'description'])
            ->mapWithKeys(fn (self $holiday) => [
                $holiday->date->format('Y-m-d') => $holiday->description,
            ]);
    }

    public static function flushCachedHolidays(): void
    {
        static::$cachedHolidays = null;
    }

    private static function dateKey(mixed $date): ?string
    {
        if (empty($date)) {
            return null;
        }

        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
