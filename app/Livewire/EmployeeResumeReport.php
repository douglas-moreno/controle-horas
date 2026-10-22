<?php

namespace App\Livewire;

use App\Exports\EmployeeResumeReportExport;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Point;
use App\Services\OvertimeCalculator;
use App\Services\TimesheetMirror;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EmployeeResumeReport extends Component
{
    public $mes;

    public $ano;

    public $startDate;

    public $endDate;

    public $results = [];

    public string $sortField = 'total_minutes';

    public string $sortDirection = 'desc';

    public function mount()
    {
        $this->mes = now()->month;
        $this->ano = now()->year;
        $this->setPeriodFromMonth($this->ano, $this->mes);
        $this->loadResults();
    }

    public function periodoAnterior()
    {
        $this->mes--;
        if ($this->mes < 1) {
            $this->mes = 12;
            $this->ano--;
        }
        $this->setPeriodFromMonth($this->ano, $this->mes);
        $this->loadResults();
    }

    public function periodoProximo()
    {
        $this->mes++;
        if ($this->mes > 12) {
            $this->mes = 1;
            $this->ano++;
        }
        $this->setPeriodFromMonth($this->ano, $this->mes);
        $this->loadResults();
    }

    private function setPeriodFromMonth(int $ano, int $mes): void
    {
        // período: 26 do mês anterior até 25 do mês informado
        $this->startDate = Carbon::create($ano, $mes - 1, 26)->format('Y-m-d');
        $this->endDate = Carbon::create($ano, $mes, 25)->format('Y-m-d');
    }

    public function updated($property)
    {
        if (in_array($property, ['startDate', 'endDate'])) {
            $this->loadResults();
        }
    }

    public function loadResults()
    {
        $start = Carbon::createFromFormat('Y-m-d', $this->startDate)->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $this->endDate)->endOfDay();

        // Carrega apenas funcionários ativos (sem data de rescisão) e com pontos no período
        $employees = Employee::where(function ($q) {
            $q->whereNull('recision_date')->orWhere('recision_date', '');
        })->with(['points' => function ($q) use ($start, $end) {
            $q->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
                ->orderBy('date')->orderBy('time');
        }])->get();

        $results = [];

        foreach ($employees as $employee) {
            // agrupa por dia
            $groups = collect($employee->points)->groupBy(fn ($p) => Carbon::parse($p->date)->format('Y-m-d'));

            $weekdayMinutes = 0;
            $saturdayMinutes = 0;
            $sundayMinutes = 0;
            $holidayMinutes = 0;

            foreach ($groups as $date => $points) {
                $minutes = $this->calculateExtraMinutes($date, $points);

                $dt = Carbon::parse($date);

                // Feriado tem precedência sobre o dia da semana
                if (Holiday::isHoliday($dt)) {
                    $holidayMinutes += $minutes;
                } elseif ($dt->isSaturday()) {
                    $saturdayMinutes += $minutes;
                } elseif ($dt->isSunday()) {
                    $sundayMinutes += $minutes;
                } else {
                    $weekdayMinutes += $minutes;
                }
            }

            // 50% = Seg-Sex + Sábado; 100% = Domingo + Feriado
            $fiftyMinutes = $weekdayMinutes + $saturdayMinutes;
            $hundredMinutes = $sundayMinutes + $holidayMinutes;
            $total = $fiftyMinutes + $hundredMinutes;

            $results[] = [
                'employee' => $employee,
                'weekday_minutes' => $weekdayMinutes,
                'saturday_minutes' => $saturdayMinutes,
                'fifty_minutes' => $fiftyMinutes,
                'sunday_minutes' => $sundayMinutes,
                'holiday_minutes' => $holidayMinutes,
                'hundred_minutes' => $hundredMinutes,
                'total_minutes' => $total,
                'weekday_hours' => $this->minutesToTime($weekdayMinutes),
                'saturday_hours' => $this->minutesToTime($saturdayMinutes),
                'fifty_hours' => $this->minutesToTime($fiftyMinutes),
                'sunday_hours' => $this->minutesToTime($sundayMinutes),
                'holiday_hours' => $this->minutesToTime($holidayMinutes),
                'hundred_hours' => $this->minutesToTime($hundredMinutes),
                'total_hours' => $this->minutesToTime($total),
            ];
        }

        $this->results = $this->sortResults($results);
    }

    /**
     * Alterna a ordenação da tabela entre nome e total de horas extras.
     */
    public function sortBy(string $field): void
    {
        if (! in_array($field, ['name', 'total_minutes'], true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = $field === 'name' ? 'asc' : 'desc';
        }

        $this->loadResults();
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array<string, mixed>>
     */
    private function sortResults(array $results): array
    {
        $collator = class_exists(\Collator::class) ? new \Collator('pt_BR') : null;

        usort($results, function (array $a, array $b) use ($collator): int {
            if ($this->sortField === 'name') {
                $comparison = $collator instanceof \Collator
                    ? (int) $collator->compare($a['employee']->name, $b['employee']->name)
                    : strcasecmp($a['employee']->name, $b['employee']->name);
            } else {
                $comparison = $a['total_minutes'] <=> $b['total_minutes'];
            }

            return $this->sortDirection === 'asc' ? $comparison : -$comparison;
        });

        return $results;
    }

    /**
     * Minutos extras do dia pela regra vigente compartilhada (OvertimeCalculator).
     *
     * @param  Collection<int, Point>  $points
     */
    private function calculateExtraMinutes(string $date, Collection $points): int
    {
        try {
            return app(OvertimeCalculator::class)->minutes(Carbon::parse($date), app(TimesheetMirror::class)->distribute($points));
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function minutesToTime(int $minutes): string
    {
        return OvertimeCalculator::format($minutes);
    }

    public function exportToExcel(): BinaryFileResponse
    {
        $startFormatted = Carbon::createFromFormat('Y-m-d', $this->startDate)->format('d-m-Y');
        $endFormatted = Carbon::createFromFormat('Y-m-d', $this->endDate)->format('d-m-Y');
        $filename = "resumo_horas_extras_{$startFormatted}_{$endFormatted}.xlsx";

        return Excel::download(
            new EmployeeResumeReportExport($this->results, $this->startDate, $this->endDate),
            $filename
        );
    }

    public function render()
    {
        return view('livewire.employee-resume-report');
    }
}
