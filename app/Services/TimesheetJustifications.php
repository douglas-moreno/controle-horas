<?php

namespace App\Services;

use App\Enums\JustificationTreatment;
use App\Models\Employee;
use App\Models\PointJustification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Efeito das justificativas ativas no espelho de ponto (F5.1) e nas sugestões de
 * benefícios (F5.3). Somente lê `point_justifications` e o catálogo; não grava nada e
 * não altera marcações.
 *
 * Carga: uma consulta por chamada, com o motivo, agrupando por funcionário e data. Cada
 * justificativa entra na sua data e, quando termina no dia seguinte, também na data
 * seguinte (a parte depois da meia-noite). Canceladas nunca entram.
 *
 * Abono do atraso (`allowance()`, função pura), somente com tratamento de abono integral
 * ou parcial e somente sobre o atraso já calculado pelo espelho:
 * - trechos atrasados: entrada prevista → 1ª marcação e última marcação → saída prevista,
 *   em minutos de jornada (fora do intervalo previsto);
 * - período da justificativa: dia inteiro, intervalo (com a virada da meia-noite) ou
 *   nenhum (marcação única: não abona atraso);
 * - integral: minutos do período dentro dos trechos atrasados;
 * - parcial: o menor entre os minutos informados e esse valor (primeiro a entrada,
 *   depois a saída);
 * - limites: o abono da entrada não passa do atraso na entrada (já descontada a
 *   tolerância), o da saída não passa da saída antecipada; sem saldo negativo nem crédito.
 *
 * Conferência (`justifiesAbsence()`): dia útil sem marcação coberto por justificativa
 * ativa de dia inteiro, com qualquer tratamento suportado, deixa de exigir conferência.
 * Justificativa parcial (intervalo) não basta.
 *
 * Sugestões de benefícios (`suppressesAbsenceSuggestion()`, F5.3): somente dia inteiro
 * com abono integral ou parcial dispensa a sugestão de ausência injustificada.
 */
class TimesheetJustifications
{
    private const DAY_MINUTES = 1440;

    /**
     * Justificativas ativas de vários funcionários, por funcionário e data (Y-m-d).
     *
     * @param  list<int>  $employeeIds
     * @return array<int, array<string, list<PointJustification>>>
     */
    public function forEmployees(array $employeeIds, CarbonInterface $start, CarbonInterface $end): array
    {
        if ($employeeIds === [] || $start->toDateString() > $end->toDateString()) {
            return [];
        }

        $justifications = PointJustification::query()
            ->active()
            ->with('reason')
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('occurrence_date', [CarbonImmutable::parse($start->toDateString())->subDay()->toDateString(), $end->toDateString()])
            ->orderBy('occurrence_date')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();

        $grouped = [];

        foreach ($justifications as $justification) {
            $date = $justification->occurrence_date->toDateString();
            $employeeId = (int) $justification->employee_id;

            $grouped[$employeeId][$date][] = $justification;

            if ($justification->ends_next_day) {
                $grouped[$employeeId][CarbonImmutable::parse($date)->addDay()->toDateString()][] = $justification;
            }
        }

        return $grouped;
    }

    /**
     * Justificativas ativas do funcionário, por data (Y-m-d).
     *
     * @return array<string, list<PointJustification>>
     */
    public function forEmployee(Employee $employee, CarbonInterface $start, CarbonInterface $end): array
    {
        return $this->forEmployees([(int) $employee->getKey()], $start, $end)[(int) $employee->getKey()] ?? [];
    }

    /**
     * Abono do atraso de um dia com atraso calculado.
     *
     * @param  array{entry: string, break_start: string, break_end: string, exit: string}  $schedule
     * @param  list<PointJustification>  $justifications  justificativas ativas que atingem a data
     * @return array{late_entry: int, early_leave: int, total: int, by_justification: array<int, int>}
     */
    public function allowance(CarbonImmutable $date, array $schedule, string $firstPunch, string $lastPunch, int $lateEntry, int $earlyLeave, array $justifications): array
    {
        $lateSegment = [$this->toMinutes($schedule['entry']), $this->toMinutes($firstPunch)];
        $earlySegment = [$this->toMinutes($lastPunch), $this->toMinutes($schedule['exit'])];

        $lateAllowed = 0;
        $earlyAllowed = 0;
        $byJustification = [];

        foreach ($justifications as $justification) {
            if (! $this->allowsDelay($justification)) {
                continue;
            }

            $range = $this->rangeOn($justification, $date);

            if ($range === null) {
                continue;
            }

            $late = min($this->scheduledOverlap($schedule, $lateSegment, $range), $lateEntry - $lateAllowed);
            $early = min($this->scheduledOverlap($schedule, $earlySegment, $range), $earlyLeave - $earlyAllowed);

            if ($justification->treatment === JustificationTreatment::PartialAllowance) {
                $limit = (int) $justification->allowed_minutes;
                $late = min($late, $limit);
                $early = min($early, $limit - $late);
            }

            $lateAllowed += $late;
            $earlyAllowed += $early;
            $byJustification[(int) $justification->getKey()] = $late + $early;
        }

        return [
            'late_entry' => $lateAllowed,
            'early_leave' => $earlyAllowed,
            'total' => $lateAllowed + $earlyAllowed,
            'by_justification' => $byJustification,
        ];
    }

    /**
     * Justificativa ativa de dia inteiro na própria data, que tira da conferência o dia útil
     * sem marcação, ou null.
     *
     * @param  list<PointJustification>  $justifications
     */
    public function justifiesAbsence(CarbonImmutable $date, array $justifications): ?PointJustification
    {
        foreach ($justifications as $justification) {
            if ($justification->start_time === null
                && $justification->occurrence_date->toDateString() === $date->toDateString()
                && $justification->treatment->isSupported()) {
                return $justification;
            }
        }

        return null;
    }

    /**
     * Justificativa ativa de dia inteiro na própria data, com abono integral ou parcial,
     * que dispensa a sugestão de ausência injustificada (F5.3), ou null. "Não abonar",
     * intervalo, marcação única e a parte de outra data (virada da meia-noite) não bastam.
     *
     * @param  list<PointJustification>  $justifications
     */
    public function suppressesAbsenceSuggestion(CarbonImmutable $date, array $justifications): ?PointJustification
    {
        foreach ($justifications as $justification) {
            if ($justification->start_time === null
                && $justification->occurrence_date->toDateString() === $date->toDateString()
                && $this->allowsDelay($justification)) {
                return $justification;
            }
        }

        return null;
    }

    /**
     * Dados de apresentação de uma justificativa no dia.
     *
     * @return array{id: int, reason: string, category: string, treatment: string, treatment_label: string, period: string, allowed_minutes: ?int, allowed_delay_minutes: int}
     */
    public function present(PointJustification $justification, int $allowedDelayMinutes = 0): array
    {
        return [
            'id' => (int) $justification->getKey(),
            'reason' => $justification->reason->name,
            'category' => $justification->reason->category->label(),
            'treatment' => $justification->treatment->value,
            'treatment_label' => $justification->treatment->label(),
            'period' => $this->describePeriod($justification),
            'allowed_minutes' => $justification->allowed_minutes,
            'allowed_delay_minutes' => $allowedDelayMinutes,
        ];
    }

    /**
     * Texto de uma justificativa do dia, igual na tela e na impressão:
     * "Abonado — motivo, período: HH:MM", "Não abonado — motivo, período" ou
     * "Justificativa — motivo, período" (abono sem efeito no atraso do dia).
     *
     * @param  array{reason: string, treatment: string, period: string, allowed_delay_minutes: int}  $presented  resultado de present()
     */
    public function note(array $presented): string
    {
        $subject = $presented['reason'].', '.$presented['period'];

        return match (true) {
            $presented['allowed_delay_minutes'] > 0 => 'Abonado — '.$subject.': '.OvertimeCalculator::format($presented['allowed_delay_minutes']),
            $presented['treatment'] === JustificationTreatment::NoAllowance->value => 'Não abonado — '.$subject,
            default => 'Justificativa — '.$subject,
        };
    }

    private function allowsDelay(PointJustification $justification): bool
    {
        return in_array($justification->treatment, [JustificationTreatment::FullAllowance, JustificationTreatment::PartialAllowance], true);
    }

    /**
     * Período [início, fim) em minutos do dia que a justificativa ocupa na data, ou null
     * (marcação única, ou data não atingida).
     *
     * @return array{0: int, 1: int}|null
     */
    private function rangeOn(PointJustification $justification, CarbonImmutable $date): ?array
    {
        $occurrence = $justification->occurrence_date->toDateString();
        $isOccurrenceDate = $occurrence === $date->toDateString();

        if ($justification->start_time === null) {
            return $isOccurrenceDate ? [0, self::DAY_MINUTES] : null;
        }

        if ($justification->end_time === null) {
            return null;
        }

        $start = $this->toMinutes($justification->start_time);
        $end = $this->toMinutes($justification->end_time);

        if ($isOccurrenceDate) {
            return [$start, $justification->ends_next_day ? self::DAY_MINUTES : $end];
        }

        $isNextDay = $justification->ends_next_day && CarbonImmutable::parse($occurrence)->addDay()->toDateString() === $date->toDateString();

        return $isNextDay ? [0, $end] : null;
    }

    /**
     * Minutos de jornada prevista (fora do intervalo) na interseção do trecho com o período.
     *
     * @param  array{entry: string, break_start: string, break_end: string, exit: string}  $schedule
     * @param  array{0: int, 1: int}  $segment
     * @param  array{0: int, 1: int}  $range
     */
    private function scheduledOverlap(array $schedule, array $segment, array $range): int
    {
        $from = max($segment[0], $range[0]);
        $to = min($segment[1], $range[1]);

        if ($to <= $from) {
            return 0;
        }

        $overlap = fn (string $periodStart, string $periodEnd): int => max(0, min($to, $this->toMinutes($periodEnd)) - max($from, $this->toMinutes($periodStart)));

        return $overlap($schedule['entry'], $schedule['break_start']) + $overlap($schedule['break_end'], $schedule['exit']);
    }

    private function describePeriod(PointJustification $justification): string
    {
        return match (true) {
            $justification->start_time === null => 'dia inteiro',
            $justification->end_time === null => 'marcação às '.substr($justification->start_time, 0, 5),
            default => substr($justification->start_time, 0, 5).'–'.substr($justification->end_time, 0, 5).($justification->ends_next_day ? ' (dia seguinte)' : ''),
        };
    }

    private function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }
}
