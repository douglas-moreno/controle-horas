<?php

namespace App\Livewire;

use App\Models\Holiday;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use WireUi\Traits\WireUiActions;

class HolidayIndex extends Component
{
    use WireUiActions;
    use WithPagination;

    public $search;

    public $year;

    public function mount(): void
    {
        $this->year = (string) now()->year;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingYear(): void
    {
        $this->resetPage();
    }

    public function destroy(Holiday $holiday): void
    {
        $holiday->delete();

        $this->notification()->success(
            $title = 'Feriado Excluído',
            $description = 'O feriado foi excluído com sucesso.'
        );
    }

    public function render()
    {
        $holidays = Holiday::query()
            ->when($this->year, fn ($query) => $query->whereYear('date', $this->year))
            ->when($this->search, fn ($query) => $query->where('description', 'like', '%'.$this->search.'%'))
            ->orderBy('date')
            ->paginate(25);

        $years = Holiday::query()
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->format('Y'))
            ->unique()
            ->values();

        return view('livewire.holiday-index', [
            'holidays' => $holidays,
            'years' => $years,
        ]);
    }
}
