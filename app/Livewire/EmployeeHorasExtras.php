<?php

namespace App\Livewire;

use App\Models\Employee;
use Livewire\Component;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

class EmployeeHorasExtras extends Component
{
    public $employee;
    public $groups;
    public $mes;
    public $ano;

    public $totalWeekdayMinutes = 0;
    public $totalSaturdayMinutes = 0;
    public $totalSundayMinutes = 0;
    public $totalExtraMinutes = 0;

    public $totalWeekdayHours = '00:00';
    public $totalSaturdayHours = '00:00';
    public $totalSundayHours = '00:00';
    public $totalExtraHours = '00:00';

    #[Url()]
    public $startDate;

    #[Url()]
    public $endDate;

    public function periodoAnterior()
    {
        $this->mes--;
        if ($this->mes < 1) {
            $this->mes = 12;
            $this->ano--;
        }
        $this->startDate = Carbon::create($this->ano, $this->mes-1, 26)->format('Y-m-d');
        $this->endDate = Carbon::create($this->ano, $this->mes, 25)->format('Y-m-d');
        $this->updatePoints();
    }

    public function periodoProximo()
    {
        $this->mes++;
        if ($this->mes > 12) {
            $this->mes = 1;
            $this->ano++;
        }
        $this->startDate = Carbon::create($this->ano, $this->mes-1, 26)->format('Y-m-d');
        $this->endDate = Carbon::create($this->ano, $this->mes, 25)->format('Y-m-d');
        $this->updatePoints();
    }

    public function mount($employee)
    {
        $this->employee = Employee::where('id', $employee)
            ->with('points')
            ->firstOrFail();

        // Pegar o mês e ano atuais
        $this->mes = now()->month;
        $this->ano = now()->year;
        
        //Monta as datas de início e fim do mês atual
        if (!$this->startDate) {
            $this->startDate = Carbon::create($this->ano, $this->mes-1, 26)->format('Y-m-d');
        }
        if (! $this->endDate) {
            $this->endDate = Carbon::create($this->ano, $this->mes, 25)->format('Y-m-d');
        }

        $this->groups = $this->getGroupedPoints();
        $this->computeTotalExtra();
    }

    public function updatePoints()
    {
        $this->groups = $this->getGroupedPoints();
        $this->computeTotalExtra();
    }

    // Atualiza automaticamente quando startDate ou endDate mudarem
    public function updated($property)
    {
        if (in_array($property, ['startDate', 'endDate'])) {
            $this->updatePoints();
        }
    }

    private function getGroupedPoints(): Collection
    {
        // Usa a coleção já carregada para evitar problemas de formato/consulta
        $points = collect($this->employee->points ?? []);

        // Filtra por intervalo de datas utilizando Carbon (robusto para vários formatos)
        if ($this->startDate) {
            try {
                $start = Carbon::createFromFormat('Y-m-d', $this->startDate)->startOfDay();
                $points = $points->filter(fn($p) => Carbon::parse($p->date)->gte($start));
            } catch (\Throwable $e) {
                // Se a data estiver em outro formato, tenta parse genérico
                $start = Carbon::parse($this->startDate)->startOfDay();
                $points = $points->filter(fn($p) => Carbon::parse($p->date)->gte($start));
            }
        }

        if ($this->endDate) {
            try {
                $end = Carbon::createFromFormat('Y-m-d', $this->endDate)->endOfDay();
                $points = $points->filter(fn($p) => Carbon::parse($p->date)->lte($end));
            } catch (\Throwable $e) {
                $end = Carbon::parse($this->endDate)->endOfDay();
                $points = $points->filter(fn($p) => Carbon::parse($p->date)->lte($end));
            }
        }

        // Ordena e agrupa por data (Y-m-d)
        $points = $points->sortBy(function($p) {
            return Carbon::parse($p->date)->format('Y-m-d') . ' ' . ($p->time ?? '');
        });

        return $points->groupBy(function($p) {
            return Carbon::parse($p->date)->format('Y-m-d');
        })->sortKeys();
    }

    private function formatTimeValues($points): array
    {
        $times = collect($points)
            ->sortBy('time')
            ->pluck('time')
            ->values();

        $count = $times->count();

        // Helper para formatar hora (retorna '' se vazio)
        $fmt = function ($value) {
            if (empty($value)) {
                return '';
            }
            try {
                return Carbon::parse($value)->format('H:i');
            } catch (\Throwable $e) {
                return (string) $value;
            }
        };

        $entrada = '';
        $almoco_inicio = '';
        $almoco_fim = '';
        $saida = '';

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

        // calcula minutos extras para este dia
        $extraMinutes = $this->calculateExtraMinutes($entrada, $almoco_inicio, $almoco_fim, $saida, $points);

        $result = [
            'entrada' => $entrada,
            'almoco_inicio' => $almoco_inicio,
            'almoco_fim' => $almoco_fim,
            'saida' => $saida,
            'hora_extra_minutes' => $extraMinutes,
            'hora_extra' => $this->minutesToTime($extraMinutes)
        ];

        return $result;
    }

    // Novo: retorna minutos extras (inteiro) para um dia
    private function calculateExtraMinutes($entrada, $almocoInicio, $almocoFim, $saida, $points): int
    {
        if (empty($entrada) || empty($saida)) {
            return 0;
        }

        try {
            $date = Carbon::parse($points->first()->date);
            $isWeekend = $date->isWeekend();

            // Constrói Carbon com data para evitar problemas de comparação
            $start = Carbon::parse($date->format('Y-m-d') . ' ' . $entrada);
            $end = Carbon::parse($date->format('Y-m-d') . ' ' . $saida);
            if ($end < $start) {
                $end->addDay();
            }

            $total = $start->diffInMinutes($end);

            // Final de semana: soma tudo e subtrai 1h se passou de 6h (regra do usuário)
            if ($isWeekend) {
                if ($total > 360) {
                    $total -= 60;
                }
                return max(0, $total);
            }

            // Dias úteis: desconta almoço se marcado
            if (!empty($almocoInicio) && !empty($almocoFim)) {
                $almocoStart = Carbon::parse($date->format('Y-m-d') . ' ' . $almocoInicio);
                $almocoEnd = Carbon::parse($date->format('Y-m-d') . ' ' . $almocoFim);
                if ($almocoEnd < $almocoStart) {
                    $almocoEnd->addDay();
                }
                $almocoTime = $almocoStart->diffInMinutes($almocoEnd);
                $total -= $almocoTime;
            } elseif ($total > 360) {
                // sem marcação de almoço mas passou de 6h -> subtrai 1h padrão
                $total -= 60;
            }

            // Regras por dia da semana para horas extras (minutos)
            if ($date->isFriday()) {
                // sexta: padrão 9h = 540 minutos
                $extraMinutes = max(0, $total - 480);
            } else {
                // seg-qui: padrão 10h = 600 minutos
                $extraMinutes = max(0, $total - 540);
            }

            return max(0, (int) $extraMinutes);
        } catch (\Exception $e) {
            \Log::error("Error calculating extra minutes: " . $e->getMessage());
            return 0;
        }
    }

    private function computeTotalExtra(): void
    {
        $weekdayMinutes = 0;
        $saturdayMinutes = 0;
        $sundayMinutes = 0;

        if (is_iterable($this->groups)) {
            foreach ($this->groups as $date => $points) {
                $times = $this->formatTimeValues($points);
                $extraMinutes = (int) ($times['hora_extra_minutes'] ?? 0);

                $dt = Carbon::parse($date);
                if ($dt->isSaturday()) {
                    $saturdayMinutes += $extraMinutes;
                } elseif ($dt->isSunday()) {
                    $sundayMinutes += $extraMinutes;
                } else {
                    $weekdayMinutes += $extraMinutes;
                }
            }
        }

        $this->totalWeekdayMinutes = $weekdayMinutes;
        $this->totalSaturdayMinutes = $saturdayMinutes;
        $this->totalSundayMinutes = $sundayMinutes;
        $this->totalExtraMinutes = $weekdayMinutes + $saturdayMinutes + $sundayMinutes;

        $this->totalWeekdayHours = $this->minutesToTime($weekdayMinutes);
        $this->totalSaturdayHours = $this->minutesToTime($saturdayMinutes);
        $this->totalSundayHours = $this->minutesToTime($sundayMinutes);
        $this->totalExtraHours = $this->minutesToTime($this->totalExtraMinutes);
    }

    private function minutesToTime(int $minutes): string
    {
        if ($minutes <= 0) {
            return '00:00';
        }

        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        return sprintf('%02d:%02d', $hours, $mins);
    }

    public function render()
    {
        return view('livewire.employee-horas-extras', [
            'formatTimeValues' => fn($points) => $this->formatTimeValues($points)
        ]);
    }
}
