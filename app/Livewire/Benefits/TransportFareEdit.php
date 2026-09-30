<?php

namespace App\Livewire\Benefits;

use App\Models\BenefitPeriod;
use App\Models\TransportFare;
use App\Models\TransportFarePrice;
use App\Services\Money;
use App\Services\TransportFarePriceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Livewire\Component;
use WireUi\Traits\WireUiActions;

class TransportFareEdit extends Component
{
    use WireUiActions;

    public TransportFare $transportFare;

    public string $name = '';

    public string $operator = '';

    public bool $isActive = true;

    public bool $showPriceModal = false;

    public string $newPriceAmount = '';

    /**
     * Início da vigência do novo preço (qualquer data). O cálculo de uma competência
     * sempre usa o preço vigente no dia 01 do mês.
     */
    public ?string $newPriceValidFrom = null;

    public function mount(TransportFare $transportFare): void
    {
        $this->transportFare = $transportFare;
        $this->name = $transportFare->name;
        $this->operator = (string) $transportFare->operator;
        $this->isActive = $transportFare->is_active;
    }

    public function updateFare(): void
    {
        $this->name = trim($this->name);
        $this->operator = trim($this->operator);

        $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('transport_fares', 'name')->ignore($this->transportFare->id)],
            'operator' => ['nullable', 'string', 'max:100'],
            'isActive' => ['boolean'],
        ], [
            'name.required' => 'Informe o nome da tarifa.',
            'name.max' => 'O nome deve ter no máximo 100 caracteres.',
            'name.unique' => 'Já existe uma tarifa com este nome.',
            'operator.max' => 'A operadora deve ter no máximo 100 caracteres.',
        ]);

        $this->transportFare->update([
            'name' => $this->name,
            'operator' => $this->operator !== '' ? $this->operator : null,
            'is_active' => $this->isActive,
        ]);

        $this->notification()->success(
            $title = 'Tarifa Atualizada',
            $description = 'Os dados da tarifa foram atualizados com sucesso.'
        );
    }

    public function openPriceModal(): void
    {
        $this->resetValidation();
        $this->reset(['newPriceAmount', 'newPriceValidFrom']);
        $this->showPriceModal = true;
    }

    /**
     * Um novo preço é sempre uma nova linha: o preço anterior permanece no histórico.
     * Preços com início até a última competência fechada são imutáveis (guarda no model).
     */
    public function createPrice(): void
    {
        $this->newPriceAmount = str_replace(',', '.', trim($this->newPriceAmount));

        $this->validate([
            'newPriceAmount' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'newPriceValidFrom' => ['required', 'date'],
        ], [
            'newPriceAmount.required' => 'Informe o preço da tarifa.',
            'newPriceAmount.regex' => 'Informe um valor válido com até duas casas decimais (ex.: 5,40).',
            'newPriceValidFrom.required' => 'Informe o início da vigência.',
            'newPriceValidFrom.date' => 'Informe uma data válida.',
        ]);

        $validFrom = CarbonImmutable::parse($this->newPriceValidFrom)->toDateString();
        $duplicateMessage = 'Já existe um preço desta tarifa com vigência em '.CarbonImmutable::parse($validFrom)->format('d/m/Y').'.';

        if ($this->transportFare->prices()->where('valid_from', $validFrom)->exists()) {
            $this->addError('newPriceValidFrom', $duplicateMessage);

            return;
        }

        $price = new TransportFarePrice([
            'amount' => Money::fromCents(Money::toCents($this->newPriceAmount)),
            'valid_from' => $validFrom,
        ]);
        $price->transportFare()->associate($this->transportFare);
        $price->created_by = auth()->id();

        try {
            $price->save();
        } catch (UniqueConstraintViolationException) {
            $this->addError('newPriceValidFrom', $duplicateMessage);

            return;
        }

        $this->showPriceModal = false;

        $this->notification()->success(
            $title = 'Preço Cadastrado',
            $description = 'O novo preço vale a partir de '.$price->valid_from->format('d/m/Y').'.'
        );
    }

    public function render(): View
    {
        $today = CarbonImmutable::today();

        return view('livewire.benefits.transport-fare-edit', [
            'prices' => $this->transportFare->prices()->orderByDesc('valid_from')->get(),
            'currentPrice' => app(TransportFarePriceResolver::class)->forDate($this->transportFare, $today),
            'today' => $today,
            'lastClosedCompetence' => BenefitPeriod::lastClosedCompetence(),
        ]);
    }
}
