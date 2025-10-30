<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Funcionários</h1>
        <x-ui-button href="{{ route('employees.create') }}">Adicionar Funcionário</x-ui-button>
    </div>

    <div class="flex space-x-4">
        <x-ui-input label="Filtra" type="search" wire:model.live="search" placeholder="Filtrar por Nome | PIS | Função" />
        <x-ui-select wire:model.live="filterEmployee" :options="[
            ['name' => 'Ativos', 'id' => 'without_recision_date'],
            ['name' => 'Inativos', 'id' => 'with_recision_date'],
            ]" option-label='name' option-value="id" label="Filtrar Funcionários" />
    </div>

    <div>
        <table class="table-auto w-full border-collapse border border-gray-200">
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
                            <x-ui-button sm href="{{ route('employees.edit', $employee) }}">Editar</x-ui-button>
                            <x-ui-button sm red wire:click="destroy({{ $employee }})" wire:confirm="Confirma excluir registro?">Excluir</x-ui-button>
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
