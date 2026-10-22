<div>
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-bold">Comparação — regra alternativa</h1>
            <p class="text-sm text-gray-600">Somente para comparação. Os valores oficiais, a impressão e os relatórios não mudam.</p>
        </div>
        <div class="space-x-2">
            <x-ui-button secondary icon="identification" label="Correspondência de códigos" href="{{ route('reports.alternative-comparison.codes') }}" />
            @if ($exportUrl)
                <x-ui-button primary icon="arrow-down-tray" label="Exportar CSV" href="{{ $exportUrl }}" />
            @else
                <x-ui-button primary icon="arrow-down-tray" label="Exportar CSV" disabled />
            @endif
        </div>
    </div>

    <div class="flex space-x-4 mb-4">
        <x-ui-datetime-picker label="Data Inicial" wire:model.live="startDate" without-time display-format="DD/MM/YYYY" timezone="America/Sao_Paulo" />
        <x-ui-button class="mt-6 hover:transition-all hover:duration-300 hover:scale-110" wire:click="periodoAnterior" icon="arrow-long-left" secondary />
        <x-ui-button class="mt-6 hover:transition-all hover:duration-300 hover:scale-110" wire:click="periodoProximo" icon="arrow-long-right" secondary />
        <x-ui-datetime-picker label="Data Final" wire:model.live="endDate" without-time display-format="DD/MM/YYYY" timezone="America/Sao_Paulo" />
    </div>

    @if ($this->comparison === null)
        <p class="p-4 text-center text-gray-500">Informe um período válido.</p>
    @elseif (! $this->comparison['enabled'])
        <p class="p-4 text-center text-gray-500">A regra alternativa está desligada. Nenhum dado de comparação é produzido.</p>
    @else
        @php($comparison = $this->comparison)

        <div class="bg-white shadow rounded-lg p-4 mb-4 text-sm text-gray-700">
            <p>Período: <strong>{{ \Illuminate\Support\Carbon::parse($comparison['start'])->format('d/m/Y') }}</strong> — <strong>{{ \Illuminate\Support\Carbon::parse($comparison['end'])->format('d/m/Y') }}</strong></p>
            <p>Configuração de jornada usada (<span class="font-mono">{{ $comparison['configuration']['id'] }}</span>): <span class="font-mono">{{ $comparison['configuration']['descriptor'] }}</span></p>
            <p class="text-xs text-gray-500">A configuração atual não necessariamente reproduz a jornada histórica do período.</p>
        </div>

        @if ($comparison['employees'] === [])
            <p class="p-4 text-center text-gray-500">Nenhuma marcação no período.</p>
        @else
            <div class="bg-white shadow rounded-lg p-4 mb-4 overflow-x-auto">
                <h2 class="text-lg font-semibold mb-2">Totais por funcionário</h2>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left border-b">
                            <th class="p-2">Código</th>
                            <th class="p-2 text-center">Extra oficial</th>
                            <th class="p-2 text-center">Extra alternativo</th>
                            <th class="p-2 text-center">Atraso oficial</th>
                            <th class="p-2 text-center">Atraso alternativo</th>
                            <th class="p-2 text-center">Conferência oficial</th>
                            <th class="p-2 text-center">Conferência alternativa</th>
                            <th class="p-2 text-center">Dias divergentes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($comparison['employees'] as $employee)
                            <tr wire:key="employee-{{ $employee['code'] }}" class="border-t border-gray-200">
                                <td class="p-2 font-mono">{{ $employee['code'] }}</td>
                                <td class="p-2 text-center">{{ \App\Services\OvertimeCalculator::format($employee['official_overtime_minutes']) }}</td>
                                <td class="p-2 text-center">{{ \App\Services\OvertimeCalculator::format($employee['alternative_overtime_minutes']) }}</td>
                                <td class="p-2 text-center">{{ \App\Services\OvertimeCalculator::format($employee['official_delay_minutes']) }}</td>
                                <td class="p-2 text-center">{{ \App\Services\OvertimeCalculator::format($employee['alternative_delay_minutes']) }}</td>
                                <td class="p-2 text-center">{{ $employee['official_review_days'] }}</td>
                                <td class="p-2 text-center">{{ $employee['alternative_review_days'] }}</td>
                                <td class="p-2 text-center">{{ $employee['divergent_days'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 font-bold" data-testid="comparison-totals">
                            <td class="p-2">Total</td>
                            <td class="p-2 text-center">{{ \App\Services\OvertimeCalculator::format($comparison['totals']['official_overtime_minutes']) }}</td>
                            <td class="p-2 text-center">{{ \App\Services\OvertimeCalculator::format($comparison['totals']['alternative_overtime_minutes']) }}</td>
                            <td class="p-2 text-center">{{ \App\Services\OvertimeCalculator::format($comparison['totals']['official_delay_minutes']) }}</td>
                            <td class="p-2 text-center">{{ \App\Services\OvertimeCalculator::format($comparison['totals']['alternative_delay_minutes']) }}</td>
                            <td class="p-2 text-center">{{ $comparison['totals']['official_review_days'] }}</td>
                            <td class="p-2 text-center">{{ $comparison['totals']['alternative_review_days'] }}</td>
                            <td class="p-2 text-center">{{ $comparison['totals']['divergent_days'] }}</td>
                        </tr>
                    </tfoot>
                </table>
                <p class="mt-2 text-xs text-gray-500">Dias sem cálculo alternativo (conferência, sem marcação ou sem jornada) não entram nos totais alternativos e aparecem como conferência.</p>
            </div>

            <div class="bg-white shadow rounded-lg p-4 overflow-x-auto">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="text-lg font-semibold">{{ $onlyDivergent ? 'Dias divergentes' : 'Todos os dias' }} <span class="text-sm font-normal text-gray-600">({{ $days->count() }})</span></h2>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model.live="onlyDivergent" class="rounded border-gray-300" />
                        Somente dias divergentes
                    </label>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left border-b">
                            <th class="p-2" rowspan="2">Código</th>
                            <th class="p-2" rowspan="2">Data</th>
                            <th class="p-2" rowspan="2">Marcações</th>
                            <th class="p-2 text-center border-l" colspan="3">Oficial</th>
                            <th class="p-2 text-center border-l" colspan="3">Alternativa</th>
                        </tr>
                        <tr class="text-left border-b">
                            <th class="p-2 border-l">Situação</th>
                            <th class="p-2 text-center">Extra</th>
                            <th class="p-2 text-center">Atraso</th>
                            <th class="p-2 border-l">Situação / motivo</th>
                            <th class="p-2 text-center">Extra</th>
                            <th class="p-2 text-center">Atraso</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($days as $day)
                            <tr wire:key="day-{{ $day['code'] }}-{{ $day['date'] }}" class="border-t border-gray-200">
                                <td class="p-2 font-mono">{{ $day['code'] }}</td>
                                <td class="p-2">{{ $day['display_date'] }} <span class="text-xs text-gray-500">{{ $day['day_type'] }}</span></td>
                                <td class="p-2 font-mono">{{ implode(' ', $day['times']) }}</td>
                                <td class="p-2 border-l">{{ $day['official_status'] }}</td>
                                <td class="p-2 text-center">{{ $day['official_overtime_minutes'] === null ? '—' : $day['official_overtime'] }}</td>
                                <td class="p-2 text-center">{{ $day['official_delay_minutes'] === null ? '—' : $day['official_delay'] }}</td>
                                <td class="p-2 border-l">
                                    @if ($day['alternative_needs_review'])
                                        <x-ui-badge :label="$day['alternative_status']" color="amber" />
                                    @else
                                        {{ $day['alternative_status'] }}
                                    @endif
                                    @if ($day['alternative_review_reason_label'])
                                        <span class="block text-xs text-gray-600">{{ $day['alternative_review_reason_label'] }}</span>
                                    @endif
                                </td>
                                <td class="p-2 text-center">{{ $day['alternative_overtime_minutes'] === null ? '—' : $day['alternative_overtime'] }}</td>
                                <td class="p-2 text-center">{{ $day['alternative_delay_minutes'] === null ? '—' : $day['alternative_delay'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="p-4 text-center text-gray-500">Nenhum dia divergente no período.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-gray-500">— = não calculado. Dia divergente: com cálculo alternativo, extra ou atraso diferente do oficial; sem cálculo alternativo, quando o oficial calculou o dia sem conferência.</p>
            </div>
        @endif
    @endif
</div>
