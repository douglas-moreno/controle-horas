<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Data pura (sem horário) persistida sempre como Y-m-d.
 *
 * O cast nativo `immutable_date` grava "Y-m-d H:i:s" e, com Carbon na entrada, preserva
 * o horário. No SQLite isso quebra comparações textuais de vigência
 * (`valid_from <= '2026-10-01'`); no MySQL a coluna DATE truncaria. Este cast mantém
 * o mesmo valor nos dois bancos.
 *
 * @implements CastsAttributes<CarbonImmutable|null, CarbonInterface|string|null>
 */
class DateOnly implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        return CarbonImmutable::parse($value)->toDateString();
    }
}
