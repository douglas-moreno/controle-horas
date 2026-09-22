<div>
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-bold">Espelho de Ponto</h1>
            <p class="text-sm text-gray-600">Período: <strong>{{ \Illuminate\Support\Carbon::parse($startDate)->format('d/m/Y') }}</strong> — <strong>{{ \Illuminate\Support\Carbon::parse($endDate)->format('d/m/Y') }}</strong></p>
        </div>
        <div>
            @if ($printUrl)
                <x-ui-button primary icon="printer" label="Imprimir" href="{{ $printUrl }}" target="_blank" class="hover:transition-all hover:duration-300 hover:scale-110" />
            @else
                <x-ui-button primary icon="printer" label="Imprimir" disabled />
            @endif
        </div>
    </div>

    <div class="flex space-x-4 mb-4">
        <x-ui-datetime-picker label="Data Inicial" wire:model.live="startDate" without-time display-format="DD/MM/YYYY" timezone="America/Sao_Paulo" />
        <x-ui-button class="mt-6 hover:transition-all hover:duration-300 hover:scale-110" wire:click="periodoAnterior" icon="arrow-long-left" secondary />
        <x-ui-button class="mt-6 hover:transition-all hover:duration-300 hover:scale-110" wire:click="periodoProximo" icon="arrow-long-right" secondary />
        <x-ui-datetime-picker label="Data Final" wire:model.live="endDate" without-time display-format="DD/MM/YYYY" timezone="America/Sao_Paulo" />
    </div>

    <div class="bg-white shadow rounded-lg p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-lg font-semibold">
                Funcionários ativos
                <span class="text-sm font-normal text-gray-600">({{ count($employeeIds) }} de {{ $this->employees->count() }} selecionados)</span>
            </h2>
            <div class="space-x-2">
                <x-ui-button sm secondary label="Selecionar todos" wire:click="selectAll" :disabled="$this->isAllSelected()" />
                <x-ui-button sm flat label="Limpar seleção" wire:click="clearSelection" :disabled="empty($employeeIds)" />
            </div>
        </div>

        @if ($this->employees->isEmpty())
            <p class="p-4 text-center text-gray-500">Nenhum funcionário ativo encontrado.</p>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-2">
                @foreach ($this->employees as $employee)
                    <label wire:key="employee-{{ $employee->id }}" class="flex items-center gap-2 p-2 rounded border border-gray-200 cursor-pointer hover:bg-gray-50">
                        <input type="checkbox" value="{{ $employee->id }}" wire:model.live="employeeIds" class="rounded border-gray-300" />
                        <span>
                            <span class="font-medium">{{ $employee->name }}</span>
                            <span class="block text-xs text-gray-500">{{ $employee->position }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        @endif
    </div>
</div>
