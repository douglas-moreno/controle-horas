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

    <div class="mb-4 grid grid-cols-6 gap-4">
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
        <div class="bg-purple-50 p-4 rounded-lg border border-purple-200">
            <div class="text-sm text-gray-600 mb-1">Feriado</div>
            <div class="text-2xl font-bold text-purple-700">{{ $totalHolidayHours ?? '00:00' }}</div>
        </div>
        <div class="bg-green-50 p-4 rounded-lg border border-green-200">
            <div class="text-sm text-gray-600 mb-1">Total</div>
            <div class="text-2xl font-bold text-green-700">{{ $totalExtraHours ?? '00:00' }}</div>
        </div>
        <div class="bg-amber-50 p-4 rounded-lg border border-amber-200">
            <div class="text-sm text-gray-600 mb-1">Atraso</div>
            <div class="text-2xl font-bold text-amber-700">{{ $totalDelayHours }}</div>
            @if ($reviewDays > 0)
                <div class="text-xs text-amber-700 mt-1">{{ $reviewDays }} {{ $reviewDays === 1 ? 'dia' : 'dias' }} para conferência</div>
            @endif
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
                    <th class="uppercase">Atraso</th>
                    <th class="uppercase">Ocorrência</th>
                </tr>
            </thead>
            <tbody>
                @if (empty($days))
                    <tr>
                        <td colspan="9" class="text-center p-4 text-gray-500">Nenhum dia no período.</td>
                    </tr>
                @else
                    @foreach ($days as $day)
                        <tr wire:key="day-{{ $day['date'] }}" @class(['border-t border-gray-200', 'bg-yellow-50' => $day['is_highlighted']])>
                            <td class="text-lg p-2">
                                <div class="flex items-center space-x-2">
                                    <x-ui-button
                                        href="{{ route('points-edit', ['employee' => $employee->id, 'date' => $day['date']]) }}"
                                        icon="pencil"
                                        small
                                        class="hover:transition-all hover:duration-300 hover:scale-110"
                                    />
                                    @if ($day['has_manual_punch'])
                                        <div class="mt-1">
                                            <x-ui-badge label="Manual" color="orange" />
                                        </div>
                                    @endif
                                    @if ($day['holiday'])
                                        <div class="mt-1">
                                            <x-ui-badge :label="$day['holiday']" color="purple" />
                                        </div>
                                    @endif
                                </div>
                            </td>
                            <td class="text-lg text-center p-2">{{ $day['display_date'] }}</td>
                            <td class="text-lg text-center p-2">{{ $day['entrada'] }}</td>
                            <td class="text-lg text-center p-2">{{ $day['almoco_inicio'] }}</td>
                            <td class="text-lg text-center p-2">{{ $day['almoco_fim'] }}</td>
                            <td class="text-lg text-center p-2">{{ $day['saida'] }}</td>
                            <td class="text-lg text-center p-2 font-bold text-green-600">{{ $day['overtime'] }}</td>
                            <td class="text-lg text-center p-2 font-bold text-amber-600">{{ $day['delay'] }}</td>
                            <td class="text-center p-2">
                                @if ($day['status_label'])
                                    <x-ui-badge :label="$day['status_label']" :color="$day['needs_review'] ? 'amber' : 'gray'" />
                                @endif
                            </td>
                        </tr>
                    @endforeach
                @endif
            </tbody>
        </table>
    </div>
</div>
