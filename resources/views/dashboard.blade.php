<x-layouts.app :title="__('Dashboard')">
    <div class="container mx-auto p-4">
        <p>{{ __('Controle de Horas') }}</p>
    </div>
    <div class="container mx-auto p-4">
        @livewire('employee-index')
    </div>
</x-layouts.app>
