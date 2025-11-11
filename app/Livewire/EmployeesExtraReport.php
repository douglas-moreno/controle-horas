<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class EmployeesExtraReport extends Component
{
    public $mes;
    public $ano;
    public $startDate;
    public $endDate;
    public $minutesFilter = 3600;

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
        if (in_array($property, ['startDate', 'endDate'])) {
            $this->loadResults();
        }

        if ($property === 'minutesFilter') {
            if (empty($this->minutesFilter) || !is_numeric($this->minutesFilter) || $this->minutesFilter < 0) {
                $this->minutesFilter = 3600; // padrão 60 horas
            }
            $this->loadResults();
        }
    }

    private function loadResults(): void
    {
        $start = Carbon::createFromFormat('Y-m-d', $this->startDate)->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $this->endDate)->endOfDay();

        // Carrega todos os funcionários com pontos no intervalo (eager load)
        $employees = Employee::with(['points' => function ($q) use ($start, $end) {
            $q->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')]);
        }])->get();

        $results = [];

        foreach ($employees as $employee) {
            // agrupa por data e soma minutos extras por dia usando a mesma regra
            $groups = collect($employee->points)
                ->groupBy(fn($p) => Carbon::parse($p->date)->format('Y-m-d'));

            $totalMinutes = 0;

            foreach ($groups as $date => $points) {
                // ordena horários do dia
                $times = collect($points)->sortBy('time')->pluck('time')->values();

                // extrai 4 primeiros horários possíveis (como no outro componente)
                $fmt = function ($value) {
                    return empty($value) ? '' : Carbon::parse($value)->format('H:i');
                };

                $entrada = $fmt($times->get(0) ?? null);
                $almoco_inicio = $fmt($times->get(1) ?? null);
                $almoco_fim = $fmt($times->get(2) ?? null);
                $saida = $fmt($times->get(3) ?? null);

                // calcula minutos extras do dia (reutiliza lógica)
                $extra = $this->calculateExtraMinutes($entrada, $almoco_inicio, $almoco_fim, $saida, $points);
                $totalMinutes += $extra;
            }

            // só inclui funcionários com mais de 60 horas
            if ($totalMinutes > (int) $this->minutesFilter ?? 3600) {
                $results[] = [
                    'employee' => $employee,
                    'minutes' => $totalMinutes,
                    'hours' => $this->minutesToTime($totalMinutes),
                ];
            }
        }

        // ordenar por minutos decrescentes
        usort($results, fn($a, $b) => $b['minutes'] <=> $a['minutes']);

        $this->results = $results;
    }

    // Copiado/adaptado da lógica existente para retornar minutos extras de um dia
    private function calculateExtraMinutes($entrada, $almocoInicio, $almocoFim, $saida, $points): int
    {
        if (empty($entrada) || empty($saida)) {
            return 0;
        }

        try {
            $date = Carbon::parse($points->first()->date);
            $isWeekend = $date->isWeekend();

            $start = Carbon::parse($date->format('Y-m-d') . ' ' . $entrada);
            $end = Carbon::parse($date->format('Y-m-d') . ' ' . $saida);
            if ($end < $start) {
                $end->addDay();
            }

            $total = $start->diffInMinutes($end);

            // Final de semana: desconta almoço se marcado, senão desconta 1h se >6h
            if ($isWeekend) {
                if (!empty($almocoInicio) && !empty($almocoFim)) {
                    $almocoStart = Carbon::parse($date->format('Y-m-d') . ' ' . $almocoInicio);
                    $almocoEnd = Carbon::parse($date->format('Y-m-d') . ' ' . $almocoFim);
                    if ($almocoEnd < $almocoStart) $almocoEnd->addDay();
                    $lunchMinutes = $almocoStart->diffInMinutes($almocoEnd);
                    $total -= $lunchMinutes;
                } elseif ($total > 360) {
                    $total -= 60;
                }
                return max(0, (int)$total);
            }

            // Dias úteis: desconta almoço se marcado
            if (!empty($almocoInicio) && !empty($almocoFim)) {
                $almocoStart = Carbon::parse($date->format('Y-m-d') . ' ' . $almocoInicio);
                $almocoEnd = Carbon::parse($date->format('Y-m-d') . ' ' . $almocoFim);
                if ($almocoEnd < $almocoStart) $almocoEnd->addDay();
                $total -= $almocoStart->diffInMinutes($almocoEnd);
            } elseif ($total > 360) {
                $total -= 60;
            }

            // Regras por dia: sexta 9h, seg-qui 10h
            if ($date->isFriday()) {
                $extraMinutes = max(0, $total - 480); // 8h = 480 min
            } else {
                $extraMinutes = max(0, $total - 540); // 9h = 540 min
            }

            return max(0, (int)$extraMinutes);
        } catch (\Exception $e) {
            \Log::error("Error calculating extra minutes (report): " . $e->getMessage());
            return 0;
        }
    }

    private function minutesToTime(int $minutes): string
    {
        if ($minutes <= 0) return '00:00';
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return sprintf('%02d:%02d', $hours, $mins);
    }

    public function render()
    {
        return view('livewire.employees-extra-report');
    }
}
