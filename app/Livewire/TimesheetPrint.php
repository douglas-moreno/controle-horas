<?php

namespace App\Livewire;

use App\Models\Employee;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class TimesheetPrint extends Component
{
    public int $mes;

    public int $ano;

    public string $startDate = '';

    public string $endDate = '';

    /** @var array<int, string> */
    public array $employeeIds = [];

    public function mount(): void
    {
        $this->mes = now()->month;
        $this->ano = now()->year;
        $this->setPeriodFromMonth($this->ano, $this->mes);
    }

    public function periodoAnterior(): void
    {
        $this->mes--;
        if ($this->mes < 1) {
            $this->mes = 12;
            $this->ano--;
        }
        $this->setPeriodFromMonth($this->ano, $this->mes);
    }

    public function periodoProximo(): void
    {
        $this->mes++;
        if ($this->mes > 12) {
            $this->mes = 1;
            $this->ano++;
        }
        $this->setPeriodFromMonth($this->ano, $this->mes);
    }

    private function setPeriodFromMonth(int $ano, int $mes): void
    {
        // período: 26 do mês anterior até 25 do mês informado
        $this->startDate = Carbon::create($ano, $mes - 1, 26)->format('Y-m-d');
        $this->endDate = Carbon::create($ano, $mes, 25)->format('Y-m-d');
    }

    /**
     * @return Collection<int, Employee>
     */
    #[Computed]
    public function employees(): Collection
    {
        return Employee::query()
            ->where(fn ($query) => $query->whereNull('recision_date')->orWhere('recision_date', ''))
            ->orderBy('name')
            ->get(['id', 'name', 'position']);
    }

    public function selectAll(): void
    {
        $this->employeeIds = $this->employees->pluck('id')->map(fn (int $id) => (string) $id)->all();
    }

    public function clearSelection(): void
    {
        $this->employeeIds = [];
    }

    public function isAllSelected(): bool
    {
        return $this->employees->isNotEmpty() && count($this->employeeIds) === $this->employees->count();
    }

    /**
     * URL da página de impressão, ou null quando o formulário ainda não está completo.
     */
    public function printUrl(): ?string
    {
        if (empty($this->employeeIds) || ! $this->startDate || ! $this->endDate) {
            return null;
        }

        return route('reports.timesheet.print', [
            'start' => Carbon::parse($this->startDate)->format('Y-m-d'),
            'end' => Carbon::parse($this->endDate)->format('Y-m-d'),
            'employees' => array_values($this->employeeIds),
        ]);
    }

    public function render(): View
    {
        return view('livewire.timesheet-print', [
            'printUrl' => $this->printUrl(),
        ]);
    }
}
