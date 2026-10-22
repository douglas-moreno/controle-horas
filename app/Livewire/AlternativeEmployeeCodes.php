<?php

namespace App\Livewire;

use App\Models\Employee;
use App\Services\AlternativeEmployeeCode;
use App\Services\AlternativeTimesheetRule;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Correspondência código → funcionário da comparação da regra alternativa (A8).
 * Disponível somente em tela autenticada; nunca vai para o CSV. Com a regra
 * alternativa desligada, não exibe dados.
 */
class AlternativeEmployeeCodes extends Component
{
    public string $search = '';

    /**
     * @return Collection<int, array{code: string, name: string, position: ?string, terminated: bool}>
     */
    #[Computed]
    public function codes(): Collection
    {
        if (! app(AlternativeTimesheetRule::class)->isEnabled()) {
            return collect();
        }

        $codes = app(AlternativeEmployeeCode::class);
        $search = mb_strtolower(trim($this->search));

        return Employee::query()
            ->orderBy('name')
            ->get(['id', 'name', 'position', 'recision_date'])
            ->map(fn (Employee $employee) => [
                'code' => $codes->for($employee->id),
                'name' => $employee->name,
                'position' => $employee->position,
                'terminated' => filled($employee->recision_date),
            ])
            ->filter(fn (array $row) => $search === ''
                || str_contains(mb_strtolower($row['name']), $search)
                || str_contains(mb_strtolower($row['code']), $search))
            ->values();
    }

    public function render(): View
    {
        return view('livewire.alternative-employee-codes', [
            'enabled' => app(AlternativeTimesheetRule::class)->isEnabled(),
        ]);
    }
}
