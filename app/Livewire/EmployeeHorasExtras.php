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
    }

    public function updatePoints()
    {
        $this->groups = $this->getGroupedPoints();
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

        return [
            'entrada' => $entrada,
            'almoco_inicio' => $almoco_inicio,
            'almoco_fim' => $almoco_fim,
            'saida' => $saida,
        ];
    }

    public function render()
    {
        return view('livewire.employee-horas-extras', [
            'formatTimeValues' => fn($points) => $this->formatTimeValues($points)
        ]);
    }
}
