<?php

namespace App\Livewire;

use App\Models\Holiday;
use Illuminate\Support\Carbon;
use Livewire\Component;
use WireUi\Traits\WireUiActions;

class HolidayEdit extends Component
{
    use WireUiActions;

    public $holiday;

    public $date;

    public $description;

    public function mount($holiday): void
    {
        $this->holiday = Holiday::findOrFail($holiday);
        $this->date = $this->holiday->date->format('Y-m-d');
        $this->description = $this->holiday->description;
    }

    public function updateHoliday()
    {
        $this->date = $this->normalizedDate();

        $this->validate([
            'date' => 'required|date|unique:holidays,date,'.$this->holiday->id,
            'description' => 'required|string|max:255',
        ], [
            'date.unique' => 'Já existe um feriado cadastrado nesta data.',
        ]);

        $this->holiday->update([
            'date' => $this->date,
            'description' => trim($this->description),
        ]);

        $this->notification()->success(
            $title = 'Feriado Atualizado',
            $description = 'O feriado foi atualizado com sucesso.'
        );

        $this->redirect(route('holidays.index'));
    }

    private function normalizedDate(): ?string
    {
        if (empty($this->date)) {
            return null;
        }

        try {
            return Carbon::parse($this->date)->format('Y-m-d');
        } catch (\Throwable $e) {
            return $this->date;
        }
    }

    public function render()
    {
        return view('livewire.holiday-edit');
    }
}
