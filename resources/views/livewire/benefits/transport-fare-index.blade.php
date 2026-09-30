<div class="space-y-4">
    <div class="flex items-center justify-between">
        <div class="flex items-center space-x-2">
            <x-ui-icon name="truck" class="w-5 h-5" />
            <span class="text-2xl font-semibold">Tarifas de Transporte</span>
        </div>
        <div>
            <x-ui-button class="transition-all hover:duration-300 hover:scale-110" icon="plus" wire:click="openCreateModal">Adicionar Tarifa</x-ui-button>
        </div>
    </div>

    <div class="flex space-x-4 mt-6">
        <x-ui-input label="Filtrar Tarifas" type="search" wire:model.live="search" placeholder="Filtrar por Nome | Operadora" />
    </div>

    <div class="overflow-x-auto">
        <table class="table-auto w-full border-collapse border border-gray-200 mt-6">
            <thead>
                <tr>
                    <th class="uppercase">Nome</th>
                    <th class="uppercase">Operadora</th>
                    <th class="uppercase">Preço Vigente em {{ $today->format('d/m/Y') }}</th>
                    <th class="uppercase">Situação</th>
                    <th class="uppercase">Ações</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($transportFares as $transportFare)
                    @php($currentPrice = $currentPrices->get($transportFare->id))
                    <tr class="border-t border-gray-200" wire:key="fare-{{ $transportFare->id }}">
                        <td class="text-lg p-2">{{ $transportFare->name }}</td>
                        <td class="text-lg p-2">{{ $transportFare->operator }}</td>
                        <td class="text-lg p-2 text-right">
                            @if ($currentPrice)
                                R$ {{ number_format($currentPrice->amount, 2, ',', '.') }}
                                <span class="text-sm text-gray-500">desde {{ $currentPrice->valid_from->format('d/m/Y') }}</span>
                            @else
                                <span class="text-red-600">Sem preço vigente</span>
                            @endif
                        </td>
                        <td class="p-2 text-center">
                            @if ($transportFare->is_active)
                                <x-ui-badge label="Ativa" color="green" />
                            @else
                                <x-ui-badge label="Inativa" color="red" />
                            @endif
                        </td>
                        <td class="flex justify-center gap-2 p-2">
                            <x-ui-button class="hover:transition-all hover:duration-300 hover:scale-110" sm href="{{ route('benefits.fares.edit', $transportFare) }}"><x-ui-icon name="pencil" class="w-4 h-4" />Editar / Preços</x-ui-button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center p-4 text-gray-500">Nenhuma tarifa cadastrada.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-ui-modal-card wire:model="showCreateModal" title="Adicionar Tarifa">
        <div class="space-y-4">
            <x-ui-input wire:model="name" label="Nome" placeholder="Ex.: CPTM" />
            <x-ui-input wire:model="operator" label="Operadora (opcional)" placeholder="Ex.: CPTM" />
            <x-ui-checkbox wire:model="isActive" label="Tarifa ativa" />
        </div>
        <x-slot name="footer">
            <div class="flex justify-end space-x-2">
                <x-ui-button secondary wire:click="$set('showCreateModal', false)" label="Cancelar" class="hover:transition-all hover:duration-300 hover:scale-110" />
                <x-ui-button primary wire:click="createFare" label="Salvar Tarifa" class="hover:transition-all hover:duration-300 hover:scale-110" />
            </div>
        </x-slot>
    </x-ui-modal-card>
</div>
