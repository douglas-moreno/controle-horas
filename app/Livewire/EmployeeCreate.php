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
    public $recision_date;

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'pis' => 'required|string|max:20|unique:employees,pis',
            'recision_date' => 'nullable|date',
        ]);

        Employee::create([
            'name' => $this->name,
            'pis' => $this->pis,
            'recision_date' => $this->recision_date,
        ]);

        $this->reset(['name', 'pis', 'recision_date']);

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
