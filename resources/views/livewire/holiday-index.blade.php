<div class="space-y-4">
    <div class="flex items-center justify-between">
        <div class="flex items-center space-x-2">
            <x-ui-icon name="calendar-days" class="w-5 h-5" />
            <span class="text-2xl font-semibold">Feriados</span>
        </div>
        <div>
            <x-ui-button class="transition-all hover:duration-300 hover:scale-110" icon="plus" href="{{ route('holidays.create') }}">Adicionar Feriado</x-ui-button>
        </div>
    </div>

    <div class="flex space-x-4 mt-6">
        <x-ui-input label="Filtrar Feriados" type="search" wire:model.live="search" placeholder="Filtrar por Descrição" />
        <x-ui-select
            wire:model.live="year"
            :options="$years->map(fn ($year) => ['name' => $year, 'id' => $year])->all()"
            option-label="name"
            option-value="id"
            label="Ano"
            placeholder="Todos os anos"
        />
    </div>

    <div class="overflow-x-auto">
        <table class="table-auto w-full border-collapse border border-gray-200 mt-6">
            <thead>
                <tr>
                    <th class="uppercase">Data</th>
                    <th class="uppercase">Dia</th>
                    <th class="uppercase">Descrição</th>
                    <th class="uppercase">Ações</th>
                </tr>
            </thead>
            <tbody>
                @forelse($holidays as $holiday)
                    <tr class="border-t border-gray-200">
                        <td class="text-lg p-2">{{ $holiday->date->format('d/m/Y') }}</td>
                        <td class="text-lg p-2 uppercase">{{ $holiday->date->locale('pt_BR')->translatedFormat('D') }}</td>
                        <td class="text-lg p-2 uppercase">{{ $holiday->description }}</td>
                        <td class="flex justify-center gap-2 p-2">
                            <x-ui-button class="hover:transition-all hover:duration-300 hover:scale-110" sm href="{{ route('holidays.edit', $holiday) }}"><x-ui-icon name="pencil" class="w-4 h-4" />Editar</x-ui-button>
                            <x-ui-button class="hover:transition-all hover:duration-300 hover:scale-110" sm red wire:click="destroy({{ $holiday->id }})" wire:confirm="Confirma excluir registro?"><x-ui-icon name="trash" class="w-4 h-4" />Excluir</x-ui-button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center p-4 text-gray-500">Nenhum feriado cadastrado.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">
        {{ $holidays->links() }}
    </div>
</div>
