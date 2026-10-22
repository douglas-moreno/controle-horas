<?php

namespace App\Services;

use App\Enums\AlternativeReviewReason;
use App\Enums\CalendarDayType;
use App\Enums\TimesheetDayStatus;
use Carbon\CarbonInterface;

/**
 * Regra alternativa de horas extras e atrasos (docs/espelho-ponto-regra-alternativa.md),
 * calculada em paralelo à regra oficial e somente para comparação. Não altera
 * marcações, dados nem o OvertimeCalculator; `evaluate()` é uma função pura.
 *
 * Ordem, somente em dia útil com jornada:
 * 1. proteção de turno noturno: marcação entre 22:00 e 04:59 ou mais de 14h entre a
 *    1ª e a última marcação → conferência;
 * 2. deduplicação por minuto (A1);
 * 3. quantidade: 0 → sem marcação (P7); ímpar ou mais de 4 → conferência (P5);
 * 4. qualquer par [1ª, 2ª] / [3ª, 4ª] sem sobreposição com as janelas → conferência (A4, R7);
 * 5. limites do almoço aprovados (R8, D5) → conferência;
 * 6. almoço deslocado, total ou parcial (R9, D2) → conferência;
 * 7. presunção do almoço 12:00–13:00: marcações em [12:00, 13:00) são desconsideradas
 *    e o trabalho efetivo é [1ª, última] menos o intervalo previsto. Em dia de 2 marcações
 *    fora da P1, a saída dentro do almoço após entrada anterior às 12:00 vale como 12:00,
 *    e a entrada dentro do almoço antes de saída posterior às 13:00 vale como 13:00;
 *    qualquer outra combinação vai para conferência;
 * 8. extra = chegada antecipada + após a saída prevista, sem tolerância (P3); atraso =
 *    entrada tardia + saída antecipada, cada uma zerada até 5 min e integral acima
 *    (P2, R2). Ausências entre a 2ª e a 3ª marcação não contam (R3). Sem compensação.
 *
 * Sábado, domingo e feriado repetem o extra oficial recebido, sem atraso.
 */
class AlternativeTimesheetRule
{
    public const THRESHOLD_MINUTES = 5;

    public const NIGHT_FROM = '22:00';

    public const NIGHT_UNTIL = '04:59';

    public const MAX_SPAN_MINUTES = 840;

    /**
     * Pares de 2 marcações enviados para conferência por decisão explícita (R8, D5).
     *
     * @var list<array{0: string, 1: string}>
     */
    public const LUNCH_BOUNDARY_PAIRS = [
        ['11:50', '13:00'],
        ['11:59', '13:00'],
        ['12:00', '13:01'],
    ];

    /**
     * Indica se a comparação está ligada (`timesheet.alternative_rule.enabled`, desligada por padrão).
     */
    public function isEnabled(): bool
    {
        return config('timesheet.alternative_rule.enabled') === true;
    }

    /**
     * Avalia o dia usando a jornada configurada para o dia da semana. Com a
     * comparação desligada, nenhum dado alternativo é produzido (null).
     *
     * @param  list<string>  $times  marcações brutas do dia (H:i ou H:i:s)
     */
    public function forDay(CarbonInterface $date, CalendarDayType $dayType, array $times, int $officialOvertimeMinutes): ?AlternativeDayResult
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $schedule = $dayType === CalendarDayType::BusinessDay
            ? config('timesheet.schedule.'.$date->dayOfWeekIso)
            : null;

        return $this->evaluate($dayType, $schedule, $times, $officialOvertimeMinutes);
    }

    /**
     * @param  array{entry: string, break_start: string, break_end: string, exit: string}|null  $schedule
     * @param  list<string>  $times  marcações brutas do dia (H:i ou H:i:s)
     */
    public function evaluate(CalendarDayType $dayType, ?array $schedule, array $times, int $officialOvertimeMinutes): AlternativeDayResult
    {
        $times = $this->normalize($times);

        if ($dayType !== CalendarDayType::BusinessDay) {
            return new AlternativeDayResult(
                status: $times === [] ? TimesheetDayStatus::NonWorkingDay : TimesheetDayStatus::NonWorkingDayWorked,
                overtimeMinutes: $officialOvertimeMinutes,
                consideredTimes: array_values(array_unique($times)),
                usesOfficialOvertime: true,
            );
        }

        if (! $this->isValidSchedule($schedule)) {
            return new AlternativeDayResult(TimesheetDayStatus::NoSchedule, consideredTimes: $times);
        }

        if ($this->looksLikeNightShift($times)) {
            return $this->review(AlternativeReviewReason::NightShiftGuard, array_values(array_unique($times)));
        }

        $times = array_values(array_unique($times));
        $count = count($times);

        $status = match (true) {
            $count === 0 => TimesheetDayStatus::NoPunches,
            $count > 4 => TimesheetDayStatus::ExcessPunches,
            $count % 2 === 1 => TimesheetDayStatus::OddPunches,
            default => null,
        };

        if ($status !== null) {
            return new AlternativeDayResult($status, consideredTimes: $times);
        }

        $minutes = array_map($this->toMinutes(...), $times);
        [$entry, $breakStart, $breakEnd, $exit] = array_map($this->toMinutes(...), [
            $schedule['entry'], $schedule['break_start'], $schedule['break_end'], $schedule['exit'],
        ]);
        $windows = [[$entry, $breakStart], [$breakEnd, $exit]];
        $pairs = array_chunk($minutes, 2);

        foreach ($pairs as $pair) {
            if ($this->overlap([$pair], $windows) === 0) {
                return $this->review(AlternativeReviewReason::PairOutsideWindows, $times);
            }
        }

        if ($count === 2 && in_array([$times[0], $times[1]], self::LUNCH_BOUNDARY_PAIRS, true)) {
            return $this->review(AlternativeReviewReason::LunchBoundary, $times);
        }

        if ($count === 4) {
            $workedInBreak = $this->overlap($pairs, [[$breakStart, $breakEnd]]) > 0;
            $lunchInsideBreak = $minutes[1] >= $breakStart && $minutes[2] <= $breakEnd;

            if ($workedInBreak && ! $lunchInsideBreak) {
                return $this->review(AlternativeReviewReason::ShiftedLunch, $times);
            }
        }

        $first = $minutes[0];
        $last = $minutes[$count - 1];
        $presumedLunch = $first <= $breakStart && $last >= $breakEnd;
        $insideLunch = fn (int $minute): bool => $minute >= $breakStart && $minute < $breakEnd;

        if ($count === 2 && ! $presumedLunch && ($insideLunch($first) || $insideLunch($last))) {
            if ($first < $breakStart && $insideLunch($last)) {
                $last = $breakStart;
            } elseif ($insideLunch($first) && $last > $breakEnd) {
                $first = $breakEnd;
            } else {
                return $this->review(AlternativeReviewReason::LunchIntervalPunch, $times);
            }
        }

        $worked = [[$first, $last]];
        $earlyArrival = $this->overlap($worked, [[0, $entry]]);
        $afterExit = $this->overlap($worked, [[$exit, 24 * 60]]);
        $lateEntry = $this->applyThreshold($this->overlap([[$entry, $first]], $windows));
        $earlyLeave = $this->applyThreshold($this->overlap([[$last, $exit]], $windows));

        return new AlternativeDayResult(
            status: TimesheetDayStatus::Regular,
            overtimeMinutes: $earlyArrival + $afterExit,
            delayMinutes: $lateEntry + $earlyLeave,
            earlyArrivalMinutes: $earlyArrival,
            afterExitMinutes: $afterExit,
            lateEntryMinutes: $lateEntry,
            earlyLeaveMinutes: $earlyLeave,
            consideredTimes: $times,
        );
    }

    /**
     * Converte para H:i, descarta vazios e ordena; não altera o array recebido.
     *
     * @param  list<string>  $times
     * @return list<string>
     */
    private function normalize(array $times): array
    {
        $normalized = array_values(array_filter(
            array_map(fn (?string $time) => substr((string) $time, 0, 5), $times),
            fn (string $time) => preg_match('/^\d{2}:\d{2}$/', $time) === 1,
        ));
        sort($normalized);

        return $normalized;
    }

    /**
     * @param  list<string>  $times
     */
    private function looksLikeNightShift(array $times): bool
    {
        if ($times === []) {
            return false;
        }

        foreach ($times as $time) {
            if ($time >= self::NIGHT_FROM || $time <= self::NIGHT_UNTIL) {
                return true;
            }
        }

        return $this->toMinutes(end($times)) - $this->toMinutes($times[0]) > self::MAX_SPAN_MINUTES;
    }

    private function applyThreshold(int $minutes): int
    {
        return $minutes <= self::THRESHOLD_MINUTES ? 0 : $minutes;
    }

    /**
     * Minutos em comum entre dois conjuntos de intervalos [início, fim].
     *
     * @param  array<int, array<int, int>>  $intervals
     * @param  array<int, array<int, int>>  $others
     */
    private function overlap(array $intervals, array $others): int
    {
        $total = 0;

        foreach ($intervals as [$start, $end]) {
            foreach ($others as [$otherStart, $otherEnd]) {
                $total += max(0, min($end, $otherEnd) - max($start, $otherStart));
            }
        }

        return $total;
    }

    /**
     * @param  list<string>  $times
     */
    private function review(AlternativeReviewReason $reason, array $times): AlternativeDayResult
    {
        return new AlternativeDayResult(TimesheetDayStatus::OutsideSchedule, $reason, consideredTimes: $times);
    }

    private function isValidSchedule(?array $schedule): bool
    {
        if ($schedule === null) {
            return false;
        }

        foreach (['entry', 'break_start', 'break_end', 'exit'] as $key) {
            if (! is_string($schedule[$key] ?? null) || preg_match('/^\d{2}:\d{2}$/', $schedule[$key]) !== 1) {
                return false;
            }
        }

        return $schedule['entry'] <= $schedule['break_start']
            && $schedule['break_start'] <= $schedule['break_end']
            && $schedule['break_end'] <= $schedule['exit'];
    }

    private function toMinutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
