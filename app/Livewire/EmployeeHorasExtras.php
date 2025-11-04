<?php

namespace App\Livewire;

use App\Models\Employee;
use Livewire\Component;

class EmployeeHorasExtras extends Component
{

    public $employee;

    public function mount($employee)
    {
        $this->employee = Employee::where('id', $employee)
            ->with('points')
            ->firstOrFail();
    }
    
    public function render()
    {
        return view('livewire.employee-horas-extras');
    }
}
