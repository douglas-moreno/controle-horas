<?php

namespace App\Services;

use App\Models\TransportFare;
use App\Models\TransportFarePrice;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Resolve o preço de uma tarifa vigente em uma data.
 *
 * Vigente = maior `valid_from` que seja menor ou igual à data. A vigência é definida
 * exclusivamente por `valid_from`, nunca por `created_at`, e o preço anterior nunca é
 * sobrescrito: um reajuste é sempre uma nova linha.
 */
class TransportFarePriceResolver
{
    /**
     * Retorna null quando a tarifa não tem preço vigente na data.
     */
    public function forDate(TransportFare $transportFare, CarbonInterface $date): ?TransportFarePrice
    {
        return $transportFare->prices()
            ->where('valid_from', '<=', $date->toDateString())
            ->orderByDesc('valid_from')
            ->first();
    }

    /**
     * Preço vigente de várias tarifas com uma única consulta.
     *
     * @param  Collection<int, TransportFare>  $transportFares
     * @return Collection<int, TransportFarePrice|null> chave = id da tarifa
     */
    public function forDateMany(Collection $transportFares, CarbonInterface $date): Collection
    {
        $currentPrices = TransportFarePrice::query()
            ->whereIn('transport_fare_id', $transportFares->map(fn (TransportFare $transportFare) => $transportFare->getKey())->all())
            ->where('valid_from', '<=', $date->toDateString())
            ->orderByDesc('valid_from')
            ->get()
            ->unique('transport_fare_id')
            ->keyBy('transport_fare_id');

        return $transportFares->mapWithKeys(fn (TransportFare $transportFare) => [
            $transportFare->id => $currentPrices->get($transportFare->id),
        ]);
    }
}
