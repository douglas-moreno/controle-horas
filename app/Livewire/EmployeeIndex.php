<?php

namespace App\Livewire;

use App\Models\Employee;
use Livewire\Component;
use Livewire\WithPagination;
use WireUi\Traits\WireUiActions;

class EmployeeIndex extends Component
{
    use WireUiActions;
    use WithPagination;

    public $search;
    public $filterEmployee = 'without_recision_date';

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingFilterEmployee()
    {
        $this->resetPage();
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();

        $this->notification()->success(
            $title = 'Funcionário Excluído',
            $description = 'O funcionário foi excluído com sucesso.'
        );
    }   

    public function render()
    {
        $employees = Employee::query()
        ->when($this->filterEmployee, function ($query) {
            if ($this->filterEmployee === 'without_recision_date') {
                $query->whereNull('recision_date')->orWhere('recision_date', '');
            } else {
                $query->WhereNot('recision_date',"");
            }
        })->when($this->search, function ($query) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('pis', 'like', '%' . $this->search . '%')
                  ->orWhere('position', 'like', '%' . $this->search . '%');
            });
        })->orderBy('name')
        ->paginate(25);

        return view('livewire.employee-index', [
            'employees' => $employees,
        ]);
    }
}
