<?php

namespace App\Services;

use App\Enums\CalendarDayType;
use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Classifica dias do calendário a partir do cadastro de feriados existente.
 * Precedência: feriado > sábado/domingo > dia útil.
 */
class BusinessCalendar
{
    public function classify(CarbonInterface $date): CalendarDayType
    {
        $day = $this->toDate($date);

        return match (true) {
            Holiday::isHoliday($day->toDateString()) => CalendarDayType::Holiday,
            $day->isSaturday() => CalendarDayType::Saturday,
            $day->isSunday() => CalendarDayType::Sunday,
            default => CalendarDayType::BusinessDay,
        };
    }

    /**
     * Classifica cada dia do intervalo, incluindo as duas extremidades.
     *
     * @return Collection<string, CalendarDayType> chave no formato Y-m-d
     */
    public function days(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $start = $this->toDate($from);
        $end = $this->toDate($to);

        if ($start->greaterThan($end)) {
            throw new InvalidArgumentException('A data inicial deve ser igual ou anterior à data final.');
        }

        $days = collect();

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $days->put($day->toDateString(), $this->classify($day));
        }

        return $days;
    }

    /**
     * @return Collection<int, CarbonImmutable>
     */
    public function businessDays(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->days($from, $to)
            ->filter(fn (CalendarDayType $type) => $type === CalendarDayType::BusinessDay)
            ->keys()
            ->map(fn (string $date) => CarbonImmutable::parse($date))
            ->values();
    }

    public function businessDaysInMonth(CarbonInterface $month): int
    {
        $firstDay = $this->toDate($month)->startOfMonth();

        return $this->businessDays($firstDay, $firstDay->endOfMonth())->count();
    }

    public function yearHasHolidays(int $year): bool
    {
        return Holiday::cachedHolidays()
            ->keys()
            ->contains(fn (string $date) => str_starts_with($date, $year.'-'));
    }

    /**
     * Descarta horário e fuso para trabalhar somente com a data do calendário.
     */
    private function toDate(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString());
    }
}
