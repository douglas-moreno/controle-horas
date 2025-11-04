<div>
    <div class="flex justify-between items-center mb-4">
        <div>
            <h1 class="text-2xl font-bold">Funcionário</h1>
            <h2 class="text-lg text-gray-600">{{ $employee->name }} - {{ $employee->position }}</h2>
        </div>
        <div>
            <x-ui-button class="hover:transition-all hover:duration-300 hover:scale-110" href="{{ route('employees.index') }}" icon="arrow-left">Voltar para Funcionários</x-ui-button>
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
                <tr class="bg-gray-100">
                    <th class="uppercase">Ação</th>
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
                        <td colspan="6" class="text-center p-4 text-gray-500">Nenhum ponto registrado.</td>
                    </tr>
                @else
                    @foreach($groups as $date => $points)
                        @php
                            $times = $formatTimeValues($points);
                            $dt = \Illuminate\Support\Carbon::parse($date);
                            $datePart = $dt->format('d/m/Y');
                            $dayAbbrev = $dt->locale('pt_BR')->translatedFormat('D'); // ex: "sab"
                            $dayAbbrev = mb_strlen($dayAbbrev) ? mb_strtoupper(mb_substr($dayAbbrev, 0, 1)) . mb_substr($dayAbbrev, 1) : $dayAbbrev; // "Sab"
                            $displayDate = $datePart . ' ' . $dayAbbrev;
                            $rowClass = $dt->isWeekend() ? 'bg-yellow-50' : '';
                        @endphp
                        <tr class="border-t border-gray-200 {{ $rowClass }}">
                            <td class="text-lg p-2">
                                <x-ui-button 
                                    href="{{ route('points-edit', ['employee' => $employee->id, 'date' => $date]) }}" 
                                    icon="pencil" 
                                    small
                                    class="hover:transition-all hover:duration-300 hover:scale-110"
                                />
                            </td>
                            <td class="text-lg text-center p-2">{{ $displayDate }}</td>
                            <td class="text-lg text-center p-2">{{ $times['entrada'] }}</td>
                            <td class="text-lg text-center p-2">{{ $times['almoco_inicio'] }}</td>
                            <td class="text-lg text-center p-2">{{ $times['almoco_fim'] }}</td>
                            <td class="text-lg text-center p-2">{{ $times['saida'] }}</td>
                        </tr>
                    @endforeach
                @endif
            </tbody>
        </table>
    </div>
</div>
