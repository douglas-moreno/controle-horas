<div>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Editar Feriado {{ $description }}</h1>
        </div>
    </div>
    <div class="mt-6 space-y-4">
        <x-ui-datetime-picker
            label="Data"
            wire:model="date"
            without-time
            clearable
            display-format="DD/MM/YYYY"
            timezone="America/Sao_Paulo"
            placeholder="Selecione a Data do Feriado"
        />
        <x-ui-input label="Descrição" type="text" wire:model="description" placeholder="Descrição do Feriado" />
    </div>
    <div class="mt-6 flex justify-between">
        <x-ui-button class="hover:transition-all hover:duration-300 hover:scale-110" wire:click="updateHoliday">Salvar Alterações</x-ui-button>
        <x-ui-button class="hover:transition-all hover:duration-300 hover:scale-110" warning href="{{ route('holidays.index') }}">Cancelar</x-ui-button>
    </div>
</div>
