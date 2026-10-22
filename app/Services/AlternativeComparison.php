<?php

namespace App\Services;

use App\Enums\CalendarDayType;
use App\Enums\TimesheetDayStatus;
use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Comparação entre a regra oficial e a alternativa (fase 7.5 de
 * docs/espelho-ponto-regra-alternativa.md), usada pela tela e pelo CSV.
 *
 * Não calcula nada: lê os campos oficiais e a chave `alternative` produzidos pelo
 * TimesheetMirror para cada funcionário com marcações no período. Os funcionários
 * aparecem pelo código pseudonimizado (A8), sem nome nem PIS. Com a regra
 * alternativa desligada, nenhum dado de comparação é produzido.
 *
 * Dia divergente: com extra alternativo calculado, extra ou atraso diferente do
 * oficial (null é um valor próprio, ex.: atraso oficial não calculado em dia de 3
 * marcações); sem extra alternativo (conferência, sem marcação ou sem jornada),
 * somente quando o oficial não exige conferência. Ausências que as duas regras
 * deixam sem cálculo não são divergências.
 *
 * Cada comparação registra no log a configuração de jornada usada (D4), o período,
 * a superfície e o usuário, ligada ou desligada.
 */
class AlternativeComparison
{
    public function __construct(
        private TimesheetMirror $mirror,
        private AlternativeTimesheetRule $rule,
        private AlternativeEmployeeCode $codes,
    ) {}

    /**
     * @return array{
     *     enabled: bool,
     *     start: string,
     *     end: string,
     *     configuration: array{id: string, descriptor: string, schedule: array<int, array<string, string>>, entry_tolerance_minutes: int},
     *     employees: list<array<string, mixed>>,
     *     days: list<array<string, mixed>>,
     *     totals: array<string, int>|null,
     * }
     */
    public function compare(CarbonInterface $start, CarbonInterface $end, string $surface): array
    {
        $enabled = $this->rule->isEnabled();
        $configuration = $this->configuration();
        $employees = [];
        $days = [];

        if ($enabled && $start->toDateString() <= $end->toDateString()) {
            foreach ($this->employeesWithPoints($start, $end) as $employee) {
                $code = $this->codes->for($employee->id);
                $mirror = $this->mirror->build($employee->points, $start, $end);
                $rows = array_map(fn (array $day) => $this->row($code, $day), $mirror['days']);

                $employees[] = $this->summary($code, $mirror['totals'], $rows);
                array_push($days, ...$rows);
            }

            usort($employees, fn (array $a, array $b) => $a['code'] <=> $b['code']);
            usort($days, fn (array $a, array $b) => [$a['code'], $a['date']] <=> [$b['code'], $b['date']]);
        }

        $comparison = [
            'enabled' => $enabled,
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'configuration' => $configuration,
            'employees' => $employees,
            'days' => $days,
            'totals' => $enabled ? $this->totals($employees) : null,
        ];

        Log::info('Comparação da regra alternativa', [
            'surface' => $surface,
            'alternative_rule_enabled' => $enabled,
            'start' => $comparison['start'],
            'end' => $comparison['end'],
            'user_id' => auth()->id(),
            'configuration_id' => $configuration['id'],
            'configuration' => $configuration['descriptor'],
            'employees' => count($employees),
        ]);

        return $comparison;
    }

    /**
     * Configuração de jornada usada no cálculo (D4): jornada por dia da semana ISO e
     * tolerância de entrada da regra oficial, com um identificador curto.
     *
     * @return array{id: string, descriptor: string, schedule: array<int, array<string, string>>, entry_tolerance_minutes: int}
     */
    public function configuration(): array
    {
        $schedule = config('timesheet.schedule', []);
        ksort($schedule);
        $tolerance = (int) config('timesheet.entry_tolerance_minutes', 0);

        $days = array_map(
            fn (int $weekday, array $times) => $weekday.'='.implode('-', [$times['entry'] ?? '', $times['break_start'] ?? '', $times['break_end'] ?? '', $times['exit'] ?? '']),
            array_keys($schedule),
            $schedule,
        );
        $descriptor = 'jornada '.implode(';', $days).'; tolerancia_entrada_oficial='.$tolerance;

        return [
            'id' => substr(hash('sha256', $descriptor), 0, 12),
            'descriptor' => $descriptor,
            'schedule' => $schedule,
            'entry_tolerance_minutes' => $tolerance,
        ];
    }

    /**
     * @return iterable<Employee>
     */
    private function employeesWithPoints(CarbonInterface $start, CarbonInterface $end): iterable
    {
        $between = [$start->format('Y-m-d'), $end->format('Y-m-d')];

        return Employee::query()
            ->whereHas('points', fn ($query) => $query->whereBetween('date', $between))
            ->with(['points' => fn ($query) => $query->whereBetween('date', $between)->orderBy('date')->orderBy('time')])
            ->get();
    }

    /**
     * @param  array<string, mixed>  $day
     * @return array<string, mixed>
     */
    private function row(string $code, array $day): array
    {
        $alternative = $day['alternative'];

        return [
            'code' => $code,
            'date' => $day['date'],
            'display_date' => $day['display_date'],
            'day_type' => $this->dayTypeLabel($day['day_type']),
            'times' => $alternative['considered_times'],
            'official_status' => $this->statusLabel($day['status']),
            'official_needs_review' => $day['needs_review'],
            'official_overtime_minutes' => $day['overtime_minutes'],
            'official_overtime' => $day['overtime'],
            'official_delay_minutes' => $day['delay_minutes'],
            'official_delay' => $day['delay'],
            'alternative_status' => $this->statusLabel($alternative['status']),
            'alternative_needs_review' => $alternative['needs_review'],
            'alternative_review_reason' => $alternative['review_reason']?->value,
            'alternative_review_reason_label' => $alternative['review_reason_label'],
            'alternative_overtime_minutes' => $alternative['overtime_minutes'],
            'alternative_overtime' => $alternative['overtime'],
            'alternative_delay_minutes' => $alternative['delay_minutes'],
            'alternative_delay' => $alternative['delay'],
            'divergent' => $alternative['overtime_minutes'] === null
                ? ! $day['needs_review']
                : $day['overtime_minutes'] !== $alternative['overtime_minutes'] || $day['delay_minutes'] !== $alternative['delay_minutes'],
        ];
    }

    /**
     * @param  array<string, mixed>  $totals
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(string $code, array $totals, array $rows): array
    {
        return [
            'code' => $code,
            'official_overtime_minutes' => $totals['overtime_minutes'],
            'alternative_overtime_minutes' => $totals['alternative']['overtime_minutes'],
            'official_delay_minutes' => $totals['delay_minutes'],
            'alternative_delay_minutes' => $totals['alternative']['delay_minutes'],
            'official_review_days' => $totals['review_days'],
            'alternative_review_days' => $totals['alternative']['review_days'],
            'divergent_days' => count(array_filter($rows, fn (array $row) => $row['divergent'])),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $employees
     * @return array<string, int>
     */
    private function totals(array $employees): array
    {
        $keys = [
            'official_overtime_minutes', 'alternative_overtime_minutes',
            'official_delay_minutes', 'alternative_delay_minutes',
            'official_review_days', 'alternative_review_days', 'divergent_days',
        ];

        return array_combine($keys, array_map(fn (string $key) => array_sum(array_column($employees, $key)), $keys));
    }

    private function dayTypeLabel(CalendarDayType $type): string
    {
        return match ($type) {
            CalendarDayType::BusinessDay => 'Dia útil',
            CalendarDayType::Saturday => 'Sábado',
            CalendarDayType::Sunday => 'Domingo',
            CalendarDayType::Holiday => 'Feriado',
        };
    }

    private function statusLabel(TimesheetDayStatus $status): string
    {
        return match ($status) {
            TimesheetDayStatus::Regular => 'Calculado',
            TimesheetDayStatus::NonWorkingDay => 'Sem trabalho',
            default => $status->label(),
        };
    }
}
