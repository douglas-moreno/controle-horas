<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\TransportRoute;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Valor diário do VT: Σ(preço vigente da tarifa × viagens por dia) dos trechos vigentes
 * na data de referência (01/M para a competência M). Não multiplica por dias, não
 * decide participação e não grava nada.
 *
 * Um trecho sem preço vigente não é tratado como zero: fica fora da soma e sua tarifa
 * é listada em `missing_prices`, para quem consulta decidir (o fechamento bloqueia).
 *
 * @phpstan-type DailyAmountItem array{route_id: int, fare_id: int, fare_name: string, fare_cents: int, trips_per_day: int, daily_cents: int}
 * @phpstan-type DailyAmount array{daily_cents: int, items: list<DailyAmountItem>, missing_prices: list<int>}
 */
class TransportDailyAmount
{
    public function __construct(private TransportFarePriceResolver $priceResolver) {}

    /**
     * @return DailyAmount
     */
    public function forEmployee(Employee $employee, CarbonImmutable $referenceDate): array
    {
        return $this->forEmployees(collect([$employee]), $referenceDate)->get($employee->id);
    }

    /**
     * Resolve vários funcionários com consultas fixas (trechos, tarifas e preços),
     * independentemente da quantidade de funcionários.
     *
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, DailyAmount> chave = id do funcionário
     */
    public function forEmployees(Collection $employees, CarbonImmutable $referenceDate): Collection
    {
        $routesByEmployee = TransportRoute::query()
            ->with('transportFare')
            ->whereIn('employee_id', $employees->map(fn (Employee $employee) => $employee->getKey())->all())
            ->activeOn($referenceDate)
            ->orderBy('id')
            ->get()
            ->groupBy('employee_id');

        $fares = $routesByEmployee->flatten()->pluck('transportFare')->unique('id')->values();
        $prices = $this->priceResolver->forDateMany($fares, $referenceDate);

        return $employees->mapWithKeys(function (Employee $employee) use ($routesByEmployee, $prices) {
            $items = [];
            $missingPrices = [];

            foreach ($routesByEmployee->get($employee->id, collect()) as $route) {
                $price = $prices->get($route->transport_fare_id);

                if ($price === null) {
                    $missingPrices[] = $route->transport_fare_id;

                    continue;
                }

                $fareCents = Money::toCents($price->amount);

                $items[] = [
                    'route_id' => $route->id,
                    'fare_id' => $route->transport_fare_id,
                    'fare_name' => $route->transportFare->name,
                    'fare_cents' => $fareCents,
                    'trips_per_day' => $route->trips_per_day,
                    'daily_cents' => $fareCents * $route->trips_per_day,
                ];
            }

            return [$employee->id => [
                'daily_cents' => array_sum(array_column($items, 'daily_cents')),
                'items' => $items,
                'missing_prices' => array_values(array_unique($missingPrices)),
            ]];
        });
    }
}
