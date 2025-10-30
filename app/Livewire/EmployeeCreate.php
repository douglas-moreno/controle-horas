<?php

namespace App\Livewire;

use App\Models\Employee;
use Livewire\Component;
use WireUi\Traits\WireUiActions;

class EmployeeCreate extends Component
{
    use WireUiActions;

    public $name;
    public $pis;
    public $position;

    public function createEmployee()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'pis' => 'required|string|max:20|unique:employees,pis',
            'position' => 'required|string|max:255',
        ]);

        Employee::create([
            'name' => $this->name,
            'pis' => $this->pis,
            'position' => $this->position,
        ]);

        $this->reset(['name', 'pis', 'position']);

        $this->notification()->success(
            $title = 'Funcionário Criado',
            $description = 'O funcionário foi criado com sucesso.'
        );

        $this->redirect(route('employees.index'));
    }

    public function render()
    {
        return view('livewire.employee-create');
    }
}
