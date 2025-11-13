<div>
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-bold">Relatório de Horas Extras</h1>
            <p class="text-sm text-gray-600">Período: <strong>{{ \Illuminate\Support\Carbon::parse($startDate)->format('d/m/Y') }}</strong> — <strong>{{ \Illuminate\Support\Carbon::parse($endDate)->format('d/m/Y') }}</strong></p>
        </div>
    </div>
    
    <div class="flex space-x-4 mb-4">
        <x-ui-datetime-picker label="Data Inicial" wire:model.live="startDate" without-time display-format="DD/MM/YYYY" timezone="America/Sao_Paulo" />
        <x-ui-button class="mt-6 hover:transition-all hover:duration-300 hover:scale-110" wire:click="periodoAnterior" icon="arrow-long-left" secondary />
        <x-ui-button class="mt-6 hover:transition-all hover:duration-300 hover:scale-110" wire:click="periodoProximo" icon="arrow-long-right" secondary />
        <x-ui-datetime-picker label="Data Final" wire:model.live="endDate" without-time display-format="DD/MM/YYYY" timezone="America/Sao_Paulo" />
        <x-ui-select 
            wire:model.live="minutesFilter" 
            :options="[
                ['name' => 'Mais de 40 horas', 'id' => 2400],
                ['name' => 'Mais de 50 horas', 'id' => 3000],
                ['name' => 'Mais de 60 horas', 'id' => 3600],
                ['name' => 'Mais de 70 horas', 'id' => 4200],
            ]" 
            option-label='name' 
            option-value="id" 
            label="Filtrar por Total de Hora Extra" 
            clearable=false
        />
    </div>

    <div class="bg-white shadow rounded-lg p-4">
        <table class="w-full table-auto border-collapse border border-gray-200">
            <thead>
                <tr class="bg-gray-100">
                    <th class="p-2 text-left">Funcionário</th>
                    <th class="p-2 text-left">PIS</th>
                    <th class="p-2 text-left">Função</th>
                    <th class="p-2 text-center">Total (HH:MM)</th>
                    <th class="p-2 text-center">Minutos</th>
                    <th class="p-2 text-center">Ação</th>
                </tr>
            </thead>
            <tbody>
                @forelse($results as $row)
                    <tr class="border-t border-gray-200">
                        <td class="p-2">{{ $row['employee']->name }}</td>
                        <td class="p-2">{{ $row['employee']->pis }}</td>
                        <td class="p-2">{{ $row['employee']->position }}</td>
                        <td class="p-2 text-center font-semibold text-green-700">{{ $row['hours'] }}</td>
                        <td class="p-2 text-center">{{ $row['minutes'] }}</td>
                        <td class="p-2 text-center">
                            <x-ui-button href="{{ route('employees.horas-extras', ['employee' => $row['employee']->id]) }}" sm icon="clock" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="p-4 text-center text-gray-500">Nenhum funcionário com mais de {{ $minutesFilter }} minutos de hora extra no período.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
