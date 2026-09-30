<div class="space-y-4">
    <div class="flex items-center justify-between">
        <div class="flex items-center space-x-2">
            <x-ui-icon name="banknotes" class="w-5 h-5" />
            <span class="text-2xl font-semibold">Valores VR / VD</span>
        </div>
        <div>
            <x-ui-button class="transition-all hover:duration-300 hover:scale-110" icon="plus" wire:click="openCreateModal">Nova Vigência</x-ui-button>
        </div>
    </div>

    <p class="text-sm text-gray-500">
        Valores diários globais, iguais para todos os funcionários. Um novo valor é sempre uma nova vigência, que começa no dia 01 do mês informado; o histórico anterior é preservado.
    </p>

    <div class="flex flex-wrap gap-4 mt-6">
        @foreach ($currentRates as $benefitType => $currentRate)
            <div class="rounded border border-gray-200 p-4 min-w-48">
                <div class="text-sm uppercase text-gray-500">{{ strtoupper($benefitType) }} vigente em {{ $today->format('d/m/Y') }}</div>
                @if ($currentRate)
                    <div class="text-2xl font-semibold">R$ {{ number_format($currentRate->amount, 2, ',', '.') }}</div>
                    <div class="text-sm text-gray-500">desde {{ $currentRate->valid_from->format('m/Y') }}</div>
                @else
                    <div class="text-lg font-semibold text-red-600">Sem valor vigente</div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="overflow-x-auto">
        <table class="table-auto w-full border-collapse border border-gray-200 mt-6">
            <thead>
                <tr>
                    <th class="uppercase">Tipo</th>
                    <th class="uppercase">Valor Diário</th>
                    <th class="uppercase">Vigência</th>
                    <th class="uppercase">Situação</th>
                    <th class="uppercase">Ações</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rates as $rate)
                    <tr class="border-t border-gray-200" wire:key="rate-{{ $rate->id }}">
                        <td class="text-lg p-2 text-center">{{ $rate->benefit_type->label() }}</td>
                        <td class="text-lg p-2 text-right">R$ {{ number_format($rate->amount, 2, ',', '.') }}</td>
                        <td class="text-lg p-2 text-center">{{ $rate->valid_from->format('m/Y') }}</td>
                        <td class="p-2 text-center">
                            @if ($currentRates[$rate->benefit_type->value]?->is($rate))
                                <x-ui-badge label="Vigente" color="green" />
                            @elseif ($rate->valid_from->greaterThan($today))
                                <x-ui-badge label="Futura" color="blue" />
                            @else
                                <x-ui-badge label="Encerrada" color="gray" />
                            @endif
                        </td>
                        <td class="flex justify-center gap-2 p-2">
                            @unless ($rate->calculations_exists)
                                <x-ui-button class="hover:transition-all hover:duration-300 hover:scale-110" sm red wire:click="destroy({{ $rate->id }})" wire:confirm="Confirma excluir esta vigência?"><x-ui-icon name="trash" class="w-4 h-4" />Excluir</x-ui-button>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center p-4 text-gray-500">Nenhum valor cadastrado.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-ui-modal-card wire:model="showCreateModal" title="Nova Vigência de VR / VD">
        <div class="space-y-4">
            <x-ui-select
                wire:model="benefitType"
                :options="$benefitTypeOptions"
                option-label="name"
                option-value="id"
                label="Tipo"
                :clearable="false"
            />
            <x-ui-input wire:model="amount" label="Valor diário (R$)" placeholder="27,50" inputmode="decimal" />
            <x-ui-input wire:model="validFromMonth" type="month" label="Início da vigência (mês/ano)" hint="A vigência começa no dia 01 do mês informado." />
        </div>
        <x-slot name="footer">
            <div class="flex justify-end space-x-2">
                <x-ui-button secondary wire:click="$set('showCreateModal', false)" label="Cancelar" class="hover:transition-all hover:duration-300 hover:scale-110" />
                <x-ui-button primary wire:click="createRate" label="Salvar Vigência" class="hover:transition-all hover:duration-300 hover:scale-110" />
            </div>
        </x-slot>
    </x-ui-modal-card>
</div>
