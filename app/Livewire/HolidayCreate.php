<?php

namespace App\Livewire;

use App\Models\Holiday;
use Illuminate\Support\Carbon;
use Livewire\Component;
use WireUi\Traits\WireUiActions;

class HolidayCreate extends Component
{
    use WireUiActions;

    public $date;

    public $description;

    public function createHoliday()
    {
        $this->date = $this->normalizedDate();

        $this->validate([
            'date' => 'required|date|unique:holidays,date',
            'description' => 'required|string|max:255',
        ], [
            'date.unique' => 'Já existe um feriado cadastrado nesta data.',
        ]);

        Holiday::create([
            'date' => $this->date,
            'description' => trim($this->description),
        ]);

        $this->reset(['date', 'description']);

        $this->notification()->success(
            $title = 'Feriado Criado',
            $description = 'O feriado foi criado com sucesso.'
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
        return view('livewire.holiday-create');
    }
}
