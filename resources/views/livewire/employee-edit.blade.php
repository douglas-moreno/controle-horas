<div>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Editar Funcionário {{$name}} </h1>
        </div>
    </div>
    <div class="mt-6 space-y-4">
        <!-- Form content for editing an employee will go here -->
        <x-ui-input label="Nome" type="text" wire:model="name" placeholder="Nome do Funcionário" />
        <x-ui-input label="PIS" type="text" wire:model="pis" placeholder="Número do PIS" />
        <x-ui-input label="Função" type="text" wire:model="position" placeholder="Função do Funcionário" />
        <x-ui-datetime-picker requires-confirmation clearable without-time timezone="America/Sao_Paulo" display-format="DD/MM/YYYY" label="Data de Rescisão" wire:model.live="recision_date" placeholder="Selecione a Data de Rescisão" />
    </div>
    <div class="mt-6 flex justify-between">
        <x-ui-button wire:click="updateEmployee">Salvar Alterações</x-ui-button>
        <x-ui-button warning href="{{ route('employees.index') }}">Cancelar</x-ui-button>
    </div>
</div>
