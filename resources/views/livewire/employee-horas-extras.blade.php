<div>
    <div class="flex justify-between items-center mb-4">
        <div>
            <h1 class="text-2xl font-bold">Funcionário</h1>
            <h2 class="text-lg text-gray-600">{{ $employee->name }} - {{ $employee->position }}</h2>
        </div>
        <div>
            <x-ui-button href="{{ route('employees.index') }}" icon="arrow-left">Voltar para Funcionários</x-ui-button>
        </div>
    </div>

    <div class="flex space-x-4 mb-4">
        <x-ui-datetime-picker
            label="Data Inicial"
            wire:model.live="startDate"
            without-time
            display-format="DD/MM/YYYY"
            timezone="America/Sao_Paulo"
        />
        <x-ui-datetime-picker
            label="Data Final"
            wire:model.live="endDate"
            without-time
            display-format="DD/MM/YYYY"
            timezone="America/Sao_Paulo"
        />
    </div>

    <div class="bg-white shadow rounded-lg p-6">
        <table class="table-auto w-full border-collapse border border-gray-200">
            <thead>
                <tr>
                    <th class="uppercase">Data</th>
                    <th class="uppercase">Entrada</th>
                    <th class="uppercase">Almoço Início</th>
                    <th class="uppercase">Almoço Fim</th>
                    <th class="uppercase">Saída</th>
                </tr>
            </thead>
            <tbody>
                @if($groups->isEmpty())
                    <tr>
                        <td colspan="5" class="text-center p-4 text-gray-500">Nenhum ponto registrado.</td>
                    </tr>
                @else
                    @foreach($groups as $date => $points)
                        @php
                            $times = $formatTimeValues($points);
                            $displayDate = \Illuminate\Support\Carbon::parse($date)->format('d/m/Y');
                        @endphp
                        <tr class="border-t border-gray-200">
                            <td class="text-lg p-2">{{ $displayDate }}</td>
                            <td class="text-lg p-2">{{ $times['entrada'] }}</td>
                            <td class="text-lg p-2">{{ $times['almoco_inicio'] }}</td>
                            <td class="text-lg p-2">{{ $times['almoco_fim'] }}</td>
                            <td class="text-lg p-2">{{ $times['saida'] }}</td>
                        </tr>
                    @endforeach
                @endif
            </tbody>
        </table>
    </div>
</div>
