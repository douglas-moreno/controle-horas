<div class="space-y-4">
    <div class="flex items-center justify-between">
        <div class="flex items-center space-x-2">
            <x-ui-icon name="calendar" class="w-5 h-5" />
            <span class="text-2xl font-semibold">Competências</span>
        </div>
        <div class="flex items-end gap-2">
            @if ($nextCompetence === null)
                <x-ui-input wire:model="firstCompetenceMonth" type="month" label="Primeira competência (mês/ano)" />
            @endif
            <x-ui-button class="transition-all hover:duration-300 hover:scale-110" icon="plus" wire:click="createNext">
                {{ $nextCompetence ? 'Criar Próxima Competência ('.$nextCompetence->format('m/Y').')' : 'Criar Primeira Competência' }}
            </x-ui-button>
        </div>
    </div>

    <p class="text-sm text-gray-500">
        A competência é o mês de uso do benefício. Os eventos realizados considerados são os do mês anterior, independentemente do período de apuração do ponto.
    </p>

    <div class="overflow-x-auto">
        <table class="table-auto w-full border-collapse border border-gray-200 mt-6">
            <thead>
                <tr>
                    <th class="uppercase">Competência</th>
                    <th class="uppercase">Status</th>
                    <th class="uppercase">Eventos Realizados</th>
                    <th class="uppercase">Dias-base</th>
                    <th class="uppercase">Calculada em</th>
                    <th class="uppercase">Fechada em</th>
                    <th class="uppercase">Ações</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($periods as $period)
                    <tr class="border-t border-gray-200" wire:key="period-{{ $period->id }}">
                        <td class="text-lg p-2 text-center">{{ $period->competence->format('m/Y') }}</td>
                        <td class="p-2 text-center">
                            <x-ui-badge :label="$period->status->label()" :color="match ($period->status->value) { 'open' => 'blue', 'calculated' => 'amber', default => 'green' }" />
                        </td>
                        <td class="text-lg p-2 text-center">{{ $period->windowStart()->format('d/m') }} – {{ $period->windowEnd()->format('d/m/Y') }}</td>
                        <td class="text-lg p-2 text-center">{{ $period->business_days ?? '—' }}</td>
                        <td class="text-lg p-2 text-center">{{ $period->calculated_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="text-lg p-2 text-center">{{ $period->closed_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="flex justify-center gap-2 p-2">
                            <x-ui-button class="hover:transition-all hover:duration-300 hover:scale-110" sm href="{{ route('benefits.periods.adjustments', $period) }}"><x-ui-icon name="adjustments-horizontal" class="w-4 h-4" />Ajustes</x-ui-button>
                            @if (in_array($period->id, $deletablePeriodIds, true))
                                <x-ui-button
                                    class="hover:transition-all hover:duration-300 hover:scale-110"
                                    sm
                                    red
                                    wire:click="destroy({{ $period->id }})"
                                    wire:confirm="Excluir a competência {{ $period->competence->format('m/Y') }}? Esta ação remove a competência e seu histórico de status."
                                ><x-ui-icon name="trash" class="w-4 h-4" />Excluir</x-ui-button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center p-4 text-gray-500">Nenhuma competência criada.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
