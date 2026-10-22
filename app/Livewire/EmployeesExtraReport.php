<?php

namespace App\Livewire;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Point;
use App\Services\OvertimeCalculator;
use App\Services\TimesheetMirror;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;
use WireUi\Traits\WireUiActions;

class EmployeesExtraReport extends Component
{
    use WireUiActions;

    public $mes;

    public $ano;

    public $startDate;

    public $endDate;

    // filtro configurável em minutos (padrão 2400 = 40 horas)
    public $minutesFilter = 2400;

    // resultados: array de ['employee' => Employee, 'minutes' => int, 'hours' => 'HH:MM']
    public $results = [];

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
        if (in_array($property, ['startDate', 'endDate', 'minutesFilter'])) {
            if ($property === 'minutesFilter' && (! is_numeric($this->minutesFilter) || $this->minutesFilter < 0)) {
                $this->minutesFilter = 2400;
            }
            $this->loadResults();
        }
    }

    private function loadResults(): void
    {
        $start = Carbon::createFromFormat('Y-m-d', $this->startDate)->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $this->endDate)->endOfDay();

        // Carrega funcionários com pontos no intervalo (eager load pontos filtrados)
        $employees = Employee::with(['points' => function ($q) use ($start, $end) {
            $q->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
                ->orderBy('date')->orderBy('time');
        }])->get();

        $results = [];

        foreach ($employees as $employee) {
            $groups = collect($employee->points)
                ->groupBy(fn ($p) => Carbon::parse($p->date)->format('Y-m-d'));

            $totalMinutes = 0;
            $holidayMinutes = 0;

            foreach ($groups as $date => $points) {
                $extra = $this->calculateExtraMinutes($date, $points);
                $totalMinutes += $extra;

                if (Holiday::isHoliday($date)) {
                    $holidayMinutes += $extra;
                }
            }

            if ($totalMinutes > (int) $this->minutesFilter) {
                $results[] = [
                    'employee' => $employee,
                    'minutes' => $totalMinutes,
                    'hours' => $this->minutesToTime($totalMinutes),
                    'holiday_minutes' => $holidayMinutes,
                    'holiday_hours' => $this->minutesToTime($holidayMinutes),
                ];
            }
        }

        usort($results, fn ($a, $b) => $b['minutes'] <=> $a['minutes']);

        $this->results = $results;
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
            $this->notification()->error(
                $title = 'Erro ao Calcular Minutos Extras',
                $description = 'Ocorreu um erro ao calcular os minutos extras: '.$e->getMessage()
            );

            return 0;
        }
    }

    private function minutesToTime(int $minutes): string
    {
        return OvertimeCalculator::format($minutes);
    }

    public function render()
    {
        return view('livewire.employees-extra-report');
    }
}
