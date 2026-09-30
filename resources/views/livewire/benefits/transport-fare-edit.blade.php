<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Editar Tarifa {{ $transportFare->name }}</h1>
        <x-ui-button warning href="{{ route('benefits.fares.index') }}" icon="arrow-left">Voltar para Tarifas</x-ui-button>
    </div>

    <div class="mt-6 space-y-4">
        <x-ui-input wire:model="name" label="Nome" placeholder="Ex.: CPTM" />
        <x-ui-input wire:model="operator" label="Operadora (opcional)" placeholder="Ex.: CPTM" />
        <x-ui-checkbox wire:model="isActive" label="Tarifa ativa" />
    </div>
    <div class="mt-6 flex justify-between">
        <x-ui-button class="hover:transition-all hover:duration-300 hover:scale-110" wire:click="updateFare">Salvar Alterações</x-ui-button>
    </div>

    <div class="mt-10 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-semibold">Histórico de Preços</h2>
            <p class="text-sm text-gray-500">
                Um reajuste é sempre um novo preço com nova vigência; os preços anteriores não são alterados.
                O cálculo de cada competência usa o preço vigente no dia 01 do mês.
            </p>
        </div>
        <x-ui-button class="transition-all hover:duration-300 hover:scale-110" icon="plus" wire:click="openPriceModal">Novo Preço</x-ui-button>
    </div>

    <div class="overflow-x-auto">
        <table class="table-auto w-full border-collapse border border-gray-200 mt-4">
            <thead>
                <tr>
                    <th class="uppercase">Valor</th>
                    <th class="uppercase">Vigência a partir de</th>
                    <th class="uppercase">Situação em {{ $today->format('d/m/Y') }}</th>
                    <th class="uppercase">Cadastrado em</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($prices as $price)
                    <tr class="border-t border-gray-200" wire:key="price-{{ $price->id }}">
                        <td class="text-lg p-2 text-right">R$ {{ number_format($price->amount, 2, ',', '.') }}</td>
                        <td class="text-lg p-2 text-center">{{ $price->valid_from->format('d/m/Y') }}</td>
                        <td class="p-2 text-center">
                            @if ($currentPrice?->is($price))
                                <x-ui-badge label="Vigente" color="green" />
                            @elseif ($price->valid_from->greaterThan($today))
                                <x-ui-badge label="Futuro" color="blue" />
                            @else
                                <x-ui-badge label="Encerrado" color="gray" />
                            @endif
                        </td>
                        <td class="text-lg p-2 text-center">{{ $price->created_at?->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center p-4 text-gray-500">Nenhum preço cadastrado.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-ui-modal-card wire:model="showPriceModal" title="Novo Preço — {{ $transportFare->name }}">
        <div class="space-y-4">
            <x-ui-input wire:model="newPriceAmount" label="Preço (R$)" placeholder="5,40" inputmode="decimal" />
            <x-ui-datetime-picker
                wire:model="newPriceValidFrom"
                label="Início da vigência"
                without-time
                display-format="DD/MM/YYYY"
                timezone="America/Sao_Paulo"
                placeholder="Selecione a data"
            />
        </div>
        <x-slot name="footer">
            <div class="flex justify-end space-x-2">
                <x-ui-button secondary wire:click="$set('showPriceModal', false)" label="Cancelar" class="hover:transition-all hover:duration-300 hover:scale-110" />
                <x-ui-button primary wire:click="createPrice" label="Salvar Preço" class="hover:transition-all hover:duration-300 hover:scale-110" />
            </div>
        </x-slot>
    </x-ui-modal-card>
</div>
