<div class="space-y-4">
    <div class="flex items-center justify-between">
        <div class="flex items-center space-x-2">
            <x-ui-icon name="user-group" class="w-5 h-5" />
            <span class="text-2xl font-semibold">Funcionários</span>
        </div>
        <div class="flex items-center space-x-4">
            <form wire:submit.prevent="importPoints" class="flex items-center space-x-2">
                <div class="relative">
                    <input type="file" 
                        wire:model="file" 
                        class="border p-2 rounded file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:bg-blue-500 file:text-white hover:file:bg-blue-600"
                        {{ $importing ? 'disabled' : '' }}
                    >
                    <div wire:loading wire:target="file">
                        <span class="text-blue-500 text-sm block mt-1">Carregando arquivo...</span>
                    </div>
                    @error('file') 
                        <span class="text-red-500 text-sm block mt-1">
                            @if(str_contains($message, 'failed to upload'))
                                Arquivo muito grande. Tamanho máximo: 10MB
                            @else
                                {{ $message }}
                            @endif
                        </span>
                    @enderror
                </div>
                <x-ui-button 
                    :label="$importing ? 'Importando...' : 'Importar Pontos'" 
                    secondary 
                    icon="arrow-down-on-square" 
                    type="submit"
                    :disabled="$importing"
                    class="bg-blue-500 text-white rounded px-4 py-2" 
                />
            </form>
        </div>
        <div>
            <x-ui-button icon="plus" href="{{ route('employees.create') }}">Adicionar Funcionário</x-ui-button>
        </div>
    </div>

    <div class="flex space-x-4 mt-6">
        <x-ui-input label="Filtrar Funcionários" type="search" wire:model.live="search" placeholder="Filtrar por Nome | PIS | Função" />
        <x-ui-select wire:model.live="filterEmployee" :options="[
            ['name' => 'Ativos', 'id' => 'without_recision_date'],
            ['name' => 'Inativos', 'id' => 'with_recision_date'],
            ]" option-label='name' option-value="id" label="Filtrar Funcionários" />
    </div>

    <div class="overflow-x-auto">
        <table class="table-auto w-full border-collapse border border-gray-200 mt-6">
            <thead>
                <tr>
                    <th class="uppercase">Nome</th>
                    <th class="uppercase">PIS</th>
                    <th class="uppercase">Função</th>
                    <th class="uppercase">Status</th>
                    <th class="uppercase">Ações</th>
                </tr>
            </thead>
            <tbody>
                @foreach($employees as $employee)
                    <tr class="border-t border-gray-200">
                        <td class="text-lg p-2 uppercase">{{ $employee->name }}</td>
                        <td class="text-lg p-2">{{ $employee->pis }}</td>
                        <td class="text-lg p-2 uppercase">{{ $employee->position }}</td>
                        <td class="text-lg p-2">
                            @if ($employee->recision_date)
                                <x-ui-badge label="Inativo" color="red" />
                            @else
                                <x-ui-badge label="Ativo" color="green" />
                            @endif
                        </td>
                        <td class="flex justify-center gap-2 p-2">
                            <x-ui-button sm fuchsia wire:click="horasExtras({{ $employee }})"><x-ui-icon name="clock" class="w-4 h-4" />Horas</x-ui-button>
                            <x-ui-button sm href="{{ route('employees.edit', $employee) }}"><x-ui-icon name="pencil" class="w-4 h-4" />Editar</x-ui-button>
                            <x-ui-button sm red wire:click="destroy({{ $employee }})" wire:confirm="Confirma excluir registro?"><x-ui-icon name="trash" class="w-4 h-4" />Excluir</x-ui-button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">
        {{ $employees->links() }}
    </div>
</div>
