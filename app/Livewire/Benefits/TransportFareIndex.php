<?php

namespace App\Livewire\Benefits;

use App\Models\TransportFare;
use App\Services\TransportFarePriceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use WireUi\Traits\WireUiActions;

class TransportFareIndex extends Component
{
    use WireUiActions;

    public string $search = '';

    public bool $showCreateModal = false;

    public string $name = '';

    public string $operator = '';

    public bool $isActive = true;

    public function openCreateModal(): void
    {
        $this->resetValidation();
        $this->reset(['name', 'operator', 'isActive']);
        $this->showCreateModal = true;
    }

    public function createFare(): void
    {
        $this->name = trim($this->name);
        $this->operator = trim($this->operator);

        $this->validate([
            'name' => ['required', 'string', 'max:100', 'unique:transport_fares,name'],
            'operator' => ['nullable', 'string', 'max:100'],
            'isActive' => ['boolean'],
        ], [
            'name.required' => 'Informe o nome da tarifa.',
            'name.max' => 'O nome deve ter no máximo 100 caracteres.',
            'name.unique' => 'Já existe uma tarifa com este nome.',
            'operator.max' => 'A operadora deve ter no máximo 100 caracteres.',
        ]);

        $transportFare = TransportFare::create([
            'name' => $this->name,
            'operator' => $this->operator !== '' ? $this->operator : null,
            'is_active' => $this->isActive,
        ]);

        $this->showCreateModal = false;

        $this->notification()->success(
            $title = 'Tarifa Criada',
            $description = 'Cadastre o preço da tarifa '.$transportFare->name.' na tela de edição.'
        );
    }

    public function render(): View
    {
        $today = CarbonImmutable::today();

        $transportFares = TransportFare::query()
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('operator', 'like', '%'.$this->search.'%')))
            ->orderBy('name')
            ->get();

        return view('livewire.benefits.transport-fare-index', [
            'transportFares' => $transportFares,
            'currentPrices' => app(TransportFarePriceResolver::class)->forDateMany($transportFares, $today),
            'today' => $today,
        ]);
    }
}
