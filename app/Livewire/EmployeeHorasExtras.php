<?php

namespace App\Livewire;

use App\Models\Employee;
use Livewire\Component;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class EmployeeHorasExtras extends Component
{

    public $employee;
    public $groups;
    public $startDate;
    public $endDate;

    public function mount($employee)
    {
        $this->employee = Employee::where('id', $employee)
            ->with('points')
            ->firstOrFail();

        // Inicializa com primeiro e último dia do mês atual
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->endOfMonth()->format('Y-m-d');

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
        $times = collect($points)->sortBy('time')->pluck('time')->values();

        return [
            'entrada' => $times->get(0) ? Carbon::parse($times->get(0))->format('H:i') : '',
            'almoco_inicio' => $times->get(1) ? Carbon::parse($times->get(1))->format('H:i') : '',
            'almoco_fim' => $times->get(2) ? Carbon::parse($times->get(2))->format('H:i') : '',
            'saida' => $times->get(3) ? Carbon::parse($times->get(3))->format('H:i') : ''
        ];
    }

    public function render()
    {
        return view('livewire.employee-horas-extras', [
            'formatTimeValues' => fn($points) => $this->formatTimeValues($points)
        ]);
    }
}
