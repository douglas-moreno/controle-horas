<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\TransportFare;
use App\Models\TransportRoute;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cria e encerra trechos do itinerário de VT. Um funcionário pode ter vários trechos
 * simultâneos (ex.: CPTM + SP); o que não pode se sobrepor é o mesmo trecho (mesma
 * tarifa) para o mesmo funcionário. Mudar o itinerário é encerrar e criar outro trecho;
 * a tarifa de um trecho existente nunca é trocada.
 */
class TransportRouteRegistrar
{
    public function overlaps(Employee $employee, TransportFare $transportFare, CarbonImmutable $startsOn, ?CarbonImmutable $endsOn, ?int $ignoreId = null): bool
    {
        return $employee->transportRoutes()
            ->where('transport_fare_id', $transportFare->id)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->when($endsOn !== null, fn ($query) => $query->where('starts_on', '<=', $endsOn->toDateString()))
            ->where(fn ($query) => $query
                ->whereNull('ends_on')
                ->orWhere('ends_on', '>=', $startsOn->toDateString()))
            ->exists();
    }

    /**
     * @throws InvalidArgumentException para viagens inválidas, fim anterior ao início ou sobreposição
     */
    public function register(Employee $employee, TransportFare $transportFare, int $tripsPerDay, CarbonImmutable $startsOn, ?CarbonImmutable $endsOn, ?string $notes = null, ?int $userId = null): TransportRoute
    {
        if ($tripsPerDay < 1 || $tripsPerDay > 255) {
            throw new InvalidArgumentException('A quantidade de viagens por dia deve estar entre 1 e 255.');
        }

        $this->ensureValidRange($startsOn, $endsOn);

        return DB::transaction(function () use ($employee, $transportFare, $tripsPerDay, $startsOn, $endsOn, $notes, $userId) {
            if ($this->overlaps($employee, $transportFare, $startsOn, $endsOn)) {
                throw new InvalidArgumentException('Já existe um trecho '.$transportFare->name.' que se sobrepõe a este período.');
            }

            $transportRoute = new TransportRoute([
                'trips_per_day' => $tripsPerDay,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'notes' => $notes,
            ]);
            $transportRoute->employee()->associate($employee);
            $transportRoute->transportFare()->associate($transportFare);
            $transportRoute->created_by = $userId;
            $transportRoute->updated_by = $userId;
            $transportRoute->save();

            return $transportRoute;
        });
    }

    /**
     * Encerra o trecho na data informada, sem criar uma nova linha.
     *
     * @throws InvalidArgumentException quando o fim é anterior ao início ou há sobreposição
     */
    public function end(TransportRoute $transportRoute, CarbonImmutable $endsOn, ?int $userId = null): TransportRoute
    {
        $this->ensureValidRange($transportRoute->starts_on, $endsOn);

        if ($this->overlaps($transportRoute->employee, $transportRoute->transportFare, $transportRoute->starts_on, $endsOn, $transportRoute->id)) {
            throw new InvalidArgumentException('O encerramento faria este trecho se sobrepor a outro trecho da mesma tarifa.');
        }

        $transportRoute->ends_on = $endsOn;
        $transportRoute->updated_by = $userId;
        $transportRoute->save();

        return $transportRoute;
    }

    private function ensureValidRange(CarbonImmutable $startsOn, ?CarbonImmutable $endsOn): void
    {
        if ($endsOn !== null && $endsOn->lessThan($startsOn)) {
            throw new InvalidArgumentException('O fim da vigência não pode ser anterior ao início.');
        }
    }
}
