<x-layouts.app :title="__('Dashboard')">
    <div class="container mx-auto p-4">
        <h1 class="text-3xl font-bold mb-6">
            <x-ui-icon name="clock" class="w-6 h-6 inline-block mr-2" />
            {{ __('Controle de Horas') }}
        </h1>
    </div>
    <div class="container mx-auto p-4">
        @livewire('employee-index')
    </div>
</x-layouts.app>
