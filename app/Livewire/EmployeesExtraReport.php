<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Employee;
use Illuminate\Support\Carbon;
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
            if($property === 'minutesFilter' && (!is_numeric($this->minutesFilter) || $this->minutesFilter < 0)) {
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
                ->groupBy(fn($p) => Carbon::parse($p->date)->format('Y-m-d'));

            $totalMinutes = 0;

            foreach ($groups as $date => $points) {
                $times = collect($points)->sortBy('time')->pluck('time')->values();
                $count = $times->count();

                $fmt = fn($v) => empty($v) ? '' : Carbon::parse($v)->format('H:i');

                $entrada = '';
                $almoco_inicio = '';
                $almoco_fim = '';
                $saida = '';

                // Mapeia os pontos conforme a contagem
                if ($count === 1) {
                    $entrada = $fmt($times->get(0));
                } elseif ($count === 2) {
                    // Primeiro -> Entrada, Segundo -> Saída (pula almoço)
                    $entrada = $fmt($times->get(0));
                    $saida = $fmt($times->get(1));
                } elseif ($count === 3) {
                    // Entrada, Almoço Início, Saída
                    $entrada = $fmt($times->get(0));
                    $almoco_inicio = $fmt($times->get(1));
                    $saida = $fmt($times->get(2));
                } elseif ($count >= 4) {
                    // Entrada, Almoço Início, Almoço Fim, Saída (apenas os primeiros 4)
                    $entrada = $fmt($times->get(0));
                    $almoco_inicio = $fmt($times->get(1));
                    $almoco_fim = $fmt($times->get(2));
                    $saida = $fmt($times->get(3));
                }

                $extra = $this->calculateExtraMinutes($entrada, $almoco_inicio, $almoco_fim, $saida, $points);
                $totalMinutes += $extra;
            }

            if ($totalMinutes > (int)$this->minutesFilter) {
                $results[] = [
                    'employee' => $employee,
                    'minutes' => $totalMinutes,
                    'hours' => $this->minutesToTime($totalMinutes),
                ];
            }
        }

        usort($results, fn($a, $b) => $b['minutes'] <=> $a['minutes']);

        $this->results = $results;
    }

    // reuse da lógica anterior para minutos extras por dia
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
            if ($end < $start) $end->addDay();

            $total = $start->diffInMinutes($end);

            if ($isWeekend) {
                if (!empty($almocoInicio) && !empty($almocoFim)) {
                    $almocoStart = Carbon::parse($date->format('Y-m-d') . ' ' . $almocoInicio);
                    $almocoEnd = Carbon::parse($date->format('Y-m-d') . ' ' . $almocoFim);
                    if ($almocoEnd < $almocoStart) $almocoEnd->addDay();
                    $total -= $almocoStart->diffInMinutes($almocoEnd);
                } elseif ($total > 360) {
                    $total -= 60;
                }
                return max(0, (int)$total);
            }

            if (!empty($almocoInicio) && !empty($almocoFim)) {
                $almocoStart = Carbon::parse($date->format('Y-m-d') . ' ' . $almocoInicio);
                $almocoEnd = Carbon::parse($date->format('Y-m-d') . ' ' . $almocoFim);
                if ($almocoEnd < $almocoStart) $almocoEnd->addDay();
                $total -= $almocoStart->diffInMinutes($almocoEnd);
            } elseif ($total > 360) {
                $total -= 60;
            }

            if ($date->isFriday()) {
                $extraMinutes = max(0, $total - 480); // 8h
            } else {
                $extraMinutes = max(0, $total - 540); // 9h
            }

            return max(0, (int)$extraMinutes);
        } catch (\Exception $e) {
            // \Log::error("Error calculating extra minutes (report): " . $e->getMessage());
            $this->notification()->error(
                $title = 'Erro ao Calcular Minutos Extras',
                $description = 'Ocorreu um erro ao calcular os minutos extras: ' . $e->getMessage()
            );
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
