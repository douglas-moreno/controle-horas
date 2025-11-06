<?php

namespace App\Livewire;

use App\Models\Employee;
use App\Models\Point;
use Livewire\Component;
use WireUi\Traits\WireUiActions;

class EmployeePointsEdit extends Component
{
    use WireUiActions;

    public $employee;
    public $date;
    public $points;
    public $showAddPointModal = false;

    public $newPointTime;

    public function addPoint() 
    {
        $this->showAddPointModal = true;
    }

    public function saveNewPoint()
    {
        $this->validate([
            'newPointTime' => 'required|date_format:H:i',
        ]);

        $newPoint = Point::create([
            'pis' => $this->employee->pis,
            'date' => $this->date,
            'time' => $this->newPointTime . ':00',
            'type' => 'manual',
        ]);

        $this->points->push($newPoint);
        $this->showAddPointModal = false;
        $this->newPointTime = null;

        $this->notification()->success(
            $title = 'Ponto Adicionado',
            $description = 'O ponto foi adicionado com sucesso.'
        );
    }

    public function removePoint($pointId)
    {
        $point = Point::find($pointId);
        if ($point) {
            $point->delete();
            $this->points = $this->points->filter(function ($p) use ($pointId) {
                return $p->id !== $pointId;
            });

            $this->notification()->success(
                $title = 'Ponto Removido',
                $description = 'O ponto foi removido com sucesso.'
            );
        }
    }

    public function mount($employee, $date)
    {
        $this->employee = Employee::find($employee);
        $this->date = $date;

        $this->points = Point::where('pis', $this->employee->pis)
            ->whereDate('date', $date)
            ->orderBy('time', 'asc')
            ->get();
    }

    public function render()
    {
        return view('livewire.employee-points-edit', [
            'employee' => $this->employee,
            'date' => $this->date,
            'points' => $this->points,
        ]);
    }
}
