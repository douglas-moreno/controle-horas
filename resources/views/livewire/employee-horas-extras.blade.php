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

        <x-ui-button wire:click="periodoAnterior" icon="arrow-long-left" secondary class="mt-6 hover:transition-all hover:duration-300 hover:scale-110" />
        
        <x-ui-button wire:click="periodoProximo" icon="arrow-long-right" secondary class="mt-6 hover:transition-all hover:duration-300 hover:scale-110" />

        <x-ui-datetime-picker
            label="Data Final"
            wire:model.live="endDate"
            without-time
            display-format="DD/MM/YYYY"
            timezone="America/Sao_Paulo"
        />
    </div>

    <div class="mb-4 grid grid-cols-4 gap-4">
        <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
            <div class="text-sm text-gray-600 mb-1">Seg - Sex</div>
            <div class="text-2xl font-bold text-blue-700">{{ $totalWeekdayHours ?? '00:00' }}</div>
        </div>
        <div class="bg-orange-50 p-4 rounded-lg border border-orange-200">
            <div class="text-sm text-gray-600 mb-1">Sábado</div>
            <div class="text-2xl font-bold text-orange-700">{{ $totalSaturdayHours ?? '00:00' }}</div>
        </div>
        <div class="bg-red-50 p-4 rounded-lg border border-red-200">
            <div class="text-sm text-gray-600 mb-1">Domingo</div>
            <div class="text-2xl font-bold text-red-700">{{ $totalSundayHours ?? '00:00' }}</div>
        </div>
        <div class="bg-green-50 p-4 rounded-lg border border-green-200">
            <div class="text-sm text-gray-600 mb-1">Total</div>
            <div class="text-2xl font-bold text-green-700">{{ $totalExtraHours ?? '00:00' }}</div>
        </div>
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
                    <th class="uppercase">Hora Extra</th>
                </tr>
            </thead>
            <tbody>
                @if($groups->isEmpty())
                    <tr>
                        <td colspan="7" class="text-center p-4 text-gray-500">Nenhum ponto registrado.</td>
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

                            // detecta se há algum ponto marcado manualmente (campo type === 'manual')
                            $isManual = collect($points)->contains(function($p) {
                                return isset($p->type) && $p->type === 'manual';
                            });

                            // Apenas destaque de fim de semana; manual NÃO altera o background (apenas mostra badge)
                            $rowClass = $dt->isWeekend() ? 'bg-yellow-50' : '';
                        @endphp
                        <tr class="border-t border-gray-200 {{ $rowClass }}">
                            <td class="text-lg p-2">
                                <div class="flex items-center space-x-2">
                                    <x-ui-button 
                                        href="{{ route('points-edit', ['employee' => $employee->id, 'date' => $date]) }}" 
                                        icon="pencil" 
                                        small
                                        class="hover:transition-all hover:duration-300 hover:scale-110"
                                    />
                                    @if($isManual)
                                        <div class="mt-1">
                                            <x-ui-badge label="Manual" color="orange" />
                                        </div>
                                    @endif
                                </div>
                            </td>
                            <td class="text-lg text-center p-2">{{ $displayDate }}</td>
                            <td class="text-lg text-center p-2">{{ $times['entrada'] }}</td>
                            <td class="text-lg text-center p-2">{{ $times['almoco_inicio'] }}</td>
                            <td class="text-lg text-center p-2">{{ $times['almoco_fim'] }}</td>
                            <td class="text-lg text-center p-2">{{ $times['saida'] }}</td>
                            <td class="text-lg text-center p-2 font-bold text-green-600">{{ $times['hora_extra'] }}</td>
                        </tr>
                    @endforeach
                @endif
            </tbody>
        </table>
    </div>
</div>
