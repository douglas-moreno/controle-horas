<div>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Criar Funcionário</h1>
        </div>
    </div>
    <div class="mt-6 space-y-4">
        <!-- Form content for creating an employee will go here -->
        <x-ui-input label="Nome" type="text" wire:model="name" placeholder="Nome do Funcionário" />
        <x-ui-input label="PIS" type="text" wire:model="pis" placeholder="Número do PIS" />
        <x-ui-input label="Função" type="text" wire:model="position" placeholder="Função do Funcionário" />
    </div>
    <div class="mt-6 flex justify-between">
        <x-ui-button wire:click="createEmployee">Salvar Funcionário</x-ui-button>
        <x-ui-button warning href="{{ route('employees.index') }}">Cancelar</x-ui-button>
    </div>
</div>
