<?php

namespace App\Livewire;

use App\Models\Employee;
use Livewire\Component;
use WireUi\Traits\WireUiActions;

class EmployeeEdit extends Component
{
    use WireUiActions;

    public $employee;
    public $name;
    public $pis;
    public $recision_date;
    public $position;

    public function updateEmployee()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'pis' => 'required|string|max:20|unique:employees,pis,' . $this->employee->id,
            'recision_date' => 'nullable|date',
            'position' => 'required|string|max:100',
        ]);

        $this->employee->update([
            'name' => $this->name,
            'pis' => $this->pis,
            'recision_date' => $this->recision_date ?? "",
            'position' => $this->position,
        ]);

        $this->notification()->success(
            $title = 'Funcionário Atualizado',
            $description = 'Os dados do funcionário foram atualizados com sucesso.'
        );

        // $this->redirect(route('employees.index'));
    }

    public function mount($employee)
    {
        $this->employee = Employee::findOrFail($employee);
        $this->name = $this->employee->name;
        $this->pis = $this->employee->pis;
        $this->recision_date = $this->employee->recision_date;
        $this->position = $this->employee->position;
    }

    public function render()
    {
        return view('livewire.employee-edit');
    }
}
