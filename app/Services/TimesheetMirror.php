<?php

namespace App\Services;

use App\Enums\CalendarDayType;
use App\Enums\TimesheetDayStatus;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Point;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Monta o espelho de ponto de um funcionário: uma linha por dia do período com as
 * quatro marcações exibidas, horas extras (regra vigente do OvertimeCalculator),
 * atraso e totalizadores. A tela de horas extras e a impressão usam este serviço,
 * garantindo os mesmos valores nos dois formatos.
 *
 * Distribuição das marcações (ordenadas por horário):
 * 1 → entrada; 2 → entrada e saída; 3 → entrada, início do almoço e saída;
 * 4 ou mais → entrada, início e fim do almoço e saída (somente as 4 primeiras).
 *
 * Atraso, somente em dia útil com jornada configurada:
 * - 2 ou 4 marcações: a primeira é a entrada e a última exibida é a saída.
 *   Atraso na entrada = minutos de jornada entre a entrada prevista e a primeira
 *   marcação, menos a tolerância. Saída antecipada = minutos de jornada entre a
 *   última marcação e a saída prevista, sem tolerância. O intervalo previsto não
 *   conta como jornada nas duas parcelas. Atraso do dia = soma das parcelas.
 * - 0 marcações: sem marcação; 1 ou 3: incompletas; 5 ou mais: excedentes. Nesses
 *   casos não se sabe qual marcação falta ou sobra, então o atraso não é calculado
 *   e o dia é sinalizado para conferência.
 * - Sábado, domingo e feriado não têm jornada: não há atraso, e dias com marcação
 *   são sinalizados para conferência.
 *
 * Somente lê `points` e `holidays`; não grava nada.
 */
class TimesheetMirror
{
    public function __construct(
        private BusinessCalendar $calendar,
        private OvertimeCalculator $overtime,
    ) {}

    /**
     * Espelho do funcionário no período, consultando as marcações do intervalo.
     *
     * @return array{days: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function forEmployee(Employee $employee, CarbonInterface $start, CarbonInterface $end): array
    {
        $points = $employee->points()
            ->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->orderBy('date')
            ->orderBy('time')
            ->get();

        return $this->build($points, $start, $end);
    }

    /**
     * Espelho a partir de marcações já carregadas. Uma linha por dia do período,
     * inclusive os dias sem marcação. Período invertido resulta em espelho vazio.
     *
     * @param  Collection<int, Point>  $points
     * @return array{days: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function build(Collection $points, CarbonInterface $start, CarbonInterface $end): array
    {
        $pointsByDate = $points->groupBy(fn (Point $point) => Carbon::parse($point->date)->format('Y-m-d'));

        $days = [];

        if ($start->toDateString() <= $end->toDateString()) {
            foreach ($this->calendar->days($start, $end) as $date => $dayType) {
                $days[] = $this->day(CarbonImmutable::parse($date), $dayType, $pointsByDate->get($date, collect()));
            }
        }

        return ['days' => $days, 'totals' => $this->totals($days)];
    }

    /**
     * Distribui as marcações do dia nas quatro colunas exibidas.
     *
     * @param  Collection<int, Point>  $points
     * @return array{entrada: string, almoco_inicio: string, almoco_fim: string, saida: string}
     */
    public function distribute(Collection $points): array
    {
        $times = $this->times($points);

        $slots = match (true) {
            $times->count() === 1 => ['entrada' => 0],
            $times->count() === 2 => ['entrada' => 0, 'saida' => 1],
            $times->count() === 3 => ['entrada' => 0, 'almoco_inicio' => 1, 'saida' => 2],
            $times->count() >= 4 => ['entrada' => 0, 'almoco_inicio' => 1, 'almoco_fim' => 2, 'saida' => 3],
            default => [],
        };

        $result = ['entrada' => '', 'almoco_inicio' => '', 'almoco_fim' => '', 'saida' => ''];

        foreach ($slots as $column => $index) {
            $result[$column] = $times->get($index);
        }

        return $result;
    }

    /**
     * Formata minutos para exibição; null (não calculado) vira texto vazio.
     */
    public static function format(?int $minutes): string
    {
        return $minutes === null ? '' : OvertimeCalculator::format($minutes);
    }

    /**
     * @param  Collection<int, Point>  $points
     * @return array<string, mixed>
     */
    private function day(CarbonImmutable $date, CalendarDayType $dayType, Collection $points): array
    {
        $slots = $this->distribute($points);
        $punchCount = $points->count();
        $schedule = $dayType === CalendarDayType::BusinessDay ? $this->schedule($date) : null;
        $status = $this->status($dayType, $schedule, $punchCount);

        $lateEntry = null;
        $earlyLeave = null;

        if ($status === TimesheetDayStatus::Regular) {
            $tolerance = max(0, (int) config('timesheet.entry_tolerance_minutes', 0));
            $lateEntry = max(0, $this->scheduledMinutesBetween($schedule, $schedule['entry'], $slots['entrada']) - $tolerance);
            $earlyLeave = $this->scheduledMinutesBetween($schedule, $slots['saida'], $schedule['exit']);
        }

        $delay = $lateEntry === null ? null : $lateEntry + $earlyLeave;
        $overtime = $this->overtime->minutes($date, $slots);

        return [
            'date' => $date->format('Y-m-d'),
            'display_date' => $this->displayDate($date),
            'day_type' => $dayType,
            'holiday' => Holiday::descriptionFor($date->format('Y-m-d')),
            'is_highlighted' => $dayType !== CalendarDayType::BusinessDay,
            'punch_count' => $punchCount,
            'has_manual_punch' => $points->contains(fn (Point $point) => $point->type === 'manual'),
            ...$slots,
            'status' => $status,
            'status_label' => $status->label(),
            'needs_review' => $status->needsReview(),
            'overtime_minutes' => $overtime,
            'overtime' => OvertimeCalculator::format($overtime),
            'late_entry_minutes' => $lateEntry,
            'early_leave_minutes' => $earlyLeave,
            'delay_minutes' => $delay,
            'delay' => self::format($delay),
        ];
    }

    /**
     * @param  array{entry: string, break_start: string, break_end: string, exit: string}|null  $schedule
     */
    private function status(CalendarDayType $dayType, ?array $schedule, int $punchCount): TimesheetDayStatus
    {
        if ($dayType !== CalendarDayType::BusinessDay) {
            return $punchCount > 0 ? TimesheetDayStatus::NonWorkingDayWorked : TimesheetDayStatus::NonWorkingDay;
        }

        return match (true) {
            $schedule === null => TimesheetDayStatus::NoSchedule,
            $punchCount === 0 => TimesheetDayStatus::NoPunches,
            $punchCount > 4 => TimesheetDayStatus::ExcessPunches,
            $punchCount % 2 === 1 => TimesheetDayStatus::OddPunches,
            default => TimesheetDayStatus::Regular,
        };
    }

    /**
     * Jornada prevista do dia da semana, ou null quando não configurada.
     *
     * @return array{entry: string, break_start: string, break_end: string, exit: string}|null
     */
    private function schedule(CarbonInterface $date): ?array
    {
        $schedule = config('timesheet.schedule.'.$date->dayOfWeekIso);

        if ($schedule === null) {
            return null;
        }

        foreach (['entry', 'break_start', 'break_end', 'exit'] as $key) {
            if (! is_string($schedule[$key] ?? null) || ! preg_match('/^\d{2}:\d{2}$/', $schedule[$key])) {
                throw new InvalidArgumentException("Jornada configurada inválida: o campo {$key} deve estar no formato H:i.");
            }
        }

        if (! ($schedule['entry'] <= $schedule['break_start'] && $schedule['break_start'] <= $schedule['break_end'] && $schedule['break_end'] <= $schedule['exit'])) {
            throw new InvalidArgumentException('Jornada configurada inválida: os horários devem estar em ordem crescente.');
        }

        return $schedule;
    }

    /**
     * Minutos de jornada prevista (fora do intervalo) entre dois horários H:i.
     *
     * @param  array{entry: string, break_start: string, break_end: string, exit: string}  $schedule
     */
    private function scheduledMinutesBetween(array $schedule, string $from, string $to): int
    {
        $from = $this->toMinutes($from);
        $to = $this->toMinutes($to);

        $overlap = fn (string $periodStart, string $periodEnd): int => max(0, min($to, $this->toMinutes($periodEnd)) - max($from, $this->toMinutes($periodStart)));

        return $overlap($schedule['entry'], $schedule['break_start']) + $overlap($schedule['break_end'], $schedule['exit']);
    }

    private function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    /**
     * @param  Collection<int, Point>  $points
     * @return Collection<int, string>
     */
    private function times(Collection $points): Collection
    {
        return $points
            ->map(fn (Point $point) => $point->time === null ? '' : Carbon::parse($point->time)->format('H:i'))
            ->sort()
            ->values();
    }

    /**
     * Data como "26/06/2026 Sex".
     */
    private function displayDate(CarbonInterface $day): string
    {
        $dayAbbrev = $day->copy()->locale('pt_BR')->translatedFormat('D');
        $dayAbbrev = mb_strtoupper(mb_substr($dayAbbrev, 0, 1)).mb_substr($dayAbbrev, 1);

        return $day->format('d/m/Y').' '.$dayAbbrev;
    }

    /**
     * Totais do período. Cada total é a soma dos valores diários exibidos; dias sem
     * atraso calculado contam como zero e aparecem em `review_days`.
     *
     * @param  list<array<string, mixed>>  $days
     * @return array<string, mixed>
     */
    private function totals(array $days): array
    {
        $days = collect($days);

        $overtimeBy = fn (CalendarDayType $type): int => $days->where('day_type', $type)->sum('overtime_minutes');

        $totals = [
            'overtime_minutes' => $days->sum('overtime_minutes'),
            'business_day_overtime_minutes' => $overtimeBy(CalendarDayType::BusinessDay),
            'saturday_overtime_minutes' => $overtimeBy(CalendarDayType::Saturday),
            'sunday_overtime_minutes' => $overtimeBy(CalendarDayType::Sunday),
            'holiday_overtime_minutes' => $overtimeBy(CalendarDayType::Holiday),
            'late_entry_minutes' => $days->sum(fn (array $day) => $day['late_entry_minutes'] ?? 0),
            'early_leave_minutes' => $days->sum(fn (array $day) => $day['early_leave_minutes'] ?? 0),
            'delay_minutes' => $days->sum(fn (array $day) => $day['delay_minutes'] ?? 0),
            'review_days' => $days->where('needs_review', true)->count(),
        ];

        return [
            ...$totals,
            'overtime' => OvertimeCalculator::format($totals['overtime_minutes']),
            'delay' => OvertimeCalculator::format($totals['delay_minutes']),
        ];
    }
}
