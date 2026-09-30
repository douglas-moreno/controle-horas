<div class="space-y-4">
    @php
        $brl = fn (int $cents) => 'R$ '.number_format($cents / 100, 2, ',', '.');
        $amount = fn (string $decimal) => $brl(\App\Services\Money::toCents($decimal));
    @endphp

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">Apuração — Competência {{ $period->competence->format('m/Y') }}</h1>
            <div class="mt-1 flex flex-wrap items-center gap-3 text-sm text-gray-500">
                <x-ui-badge :label="$period->status->label()" :color="match ($period->status->value) { 'open' => 'blue', 'calculated' => 'amber', default => 'green' }" />
                <span>Referência das vigências: {{ $period->referenceDate()->format('d/m/Y') }}</span>
                <span>Dias-base: {{ $period->business_days ?? '—' }}</span>
                <span>Calculada em: {{ $period->calculated_at?->format('d/m/Y H:i') ?? '—' }}</span>
            </div>
        </div>
        <div class="flex gap-2">
            <x-ui-button warning href="{{ route('benefits.periods.adjustments', $period) }}" icon="adjustments-horizontal">Ajustes</x-ui-button>
            <x-ui-button warning href="{{ route('benefits.periods.index') }}" icon="arrow-left">Voltar para Competências</x-ui-button>
            @if ($isEditable)
                <x-ui-button primary icon="calculator" wire:click="calculate">Calcular</x-ui-button>
            @endif
        </div>
    </div>

    @if ($period->status->value === 'open' && $periodEmployees->isEmpty())
        <p class="text-gray-500">A competência ainda não foi calculada.</p>
    @elseif ($period->status->value === 'open')
        <p class="text-amber-700">A prévia anterior foi descartada. Calcule novamente.</p>
    @endif

    @if ($issues !== [])
        <div class="rounded border border-red-300 bg-red-50 p-4">
            <h2 class="text-lg font-semibold text-red-800">Pendências de cálculo</h2>
            <p class="text-sm text-red-700">Situação atual da configuração. Pendências bloqueantes impedem o fechamento.</p>
            <ul class="mt-2 space-y-1">
                @foreach ($issues as $issue)
                    <li class="{{ $issue['severity'] === 'blocking' ? 'text-red-800' : 'text-amber-800' }}" wire:key="issue-{{ $loop->index }}">
                        {{ $issue['severity'] === 'blocking' ? 'Bloqueante' : 'Atenção' }}{{ $issue['benefit_type'] ? ' — '.strtoupper($issue['benefit_type']) : '' }}: {{ $issue['message'] }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="table-auto w-full border-collapse border border-gray-200 mt-2">
            <thead>
                <tr>
                    <th class="uppercase text-left p-2">Funcionário</th>
                    @foreach ($benefitTypes as $benefitType)
                        <th class="uppercase">{{ $benefitType->label() }} qtd</th>
                        <th class="uppercase">{{ $benefitType->label() }} valor</th>
                    @endforeach
                    <th class="uppercase">Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($periodEmployees as $periodEmployee)
                    @php($calculations = $periodEmployee->calculations->keyBy(fn ($calculation) => $calculation->benefit_type->value))
                    <tr class="border-t border-gray-200" wire:key="period-employee-{{ $periodEmployee->id }}">
                        <td class="p-2">{{ $periodEmployee->employee_name }}</td>
                        @foreach ($benefitTypes as $benefitType)
                            @php($calculation = $calculations->get($benefitType->value))
                            @if ($calculation === null)
                                <td class="p-2 text-center text-gray-400">—</td>
                                <td class="p-2 text-center text-gray-400">—</td>
                            @else
                                <td class="p-2 text-center">{{ $calculation->final_days }}</td>
                                <td class="p-2 text-right whitespace-nowrap">
                                    @if (in_array($periodEmployee->employee_id.':'.$benefitType->value, $issueKeys, true))
                                        <span class="text-red-700" title="Configuração incompleta">Pendente</span>
                                    @else
                                        {{ $amount($calculation->total_amount) }}
                                    @endif
                                </td>
                            @endif
                        @endforeach
                        <td class="p-2 text-right font-semibold whitespace-nowrap">{{ $brl($employeeTotals[$periodEmployee->id]) }}</td>
                    </tr>
                    <tr wire:key="period-employee-detail-{{ $periodEmployee->id }}">
                        <td colspan="{{ 2 + 2 * count($benefitTypes) }}" class="px-2 pb-2">
                            <details>
                                <summary class="cursor-pointer text-sm text-gray-500">Detalhar apuração</summary>
                                <div class="mt-2 grid gap-4 md:grid-cols-3">
                                    @foreach ($benefitTypes as $benefitType)
                                        @php($calculation = $calculations->get($benefitType->value))
                                        <div class="rounded border border-gray-200 p-3 text-sm">
                                            <div class="font-semibold">{{ $benefitType->label() }}</div>
                                            @if ($calculation === null)
                                                <div class="text-gray-500">Não elegível em {{ $period->referenceDate()->format('d/m/Y') }}.</div>
                                            @else
                                                <div>Base: {{ $calculation->base_days }}</div>
                                                <div>Positivos: +{{ $calculation->positive_days }}</div>
                                                <div>Negativos: -{{ $calculation->negative_days }}</div>
                                                <div>
                                                    Saldo herdado: {{ $calculation->carried_in_days }}
                                                    @if ($calculation->carriedFromCalculation)
                                                        (da competência {{ $calculation->carriedFromCalculation->benefitPeriodEmployee->benefitPeriod->competence->format('m/Y') }})
                                                    @endif
                                                </div>
                                                <div>Resultado bruto: {{ $calculation->raw_days }}</div>
                                                <div>Final: {{ $calculation->final_days }}</div>
                                                <div>Saldo gerado: {{ $calculation->carried_out_days }}</div>
                                                <div>Valor unitário: {{ $amount($calculation->unit_amount) }}</div>
                                                <div>Total: {{ $amount($calculation->total_amount) }}</div>
                                                @if ($benefitType->value === 'vt')
                                                    <div class="mt-2 font-semibold">Trechos</div>
                                                    @forelse ($calculation->transportItems as $item)
                                                        <div>{{ $item->fare_name }}: {{ $amount($item->fare_amount) }} × {{ $item->trips_per_day }} = {{ $amount($item->daily_amount) }}/dia</div>
                                                    @empty
                                                        <div class="text-red-700">Nenhum trecho com preço vigente.</div>
                                                    @endforelse
                                                @endif
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ 2 + 2 * count($benefitTypes) }}" class="text-center p-4 text-gray-500">Sem apuração.</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($periodEmployees->isNotEmpty())
                <tfoot>
                    <tr class="border-t-2 border-gray-300 font-semibold">
                        <td class="p-2">Totais</td>
                        @foreach ($benefitTypes as $benefitType)
                            <td class="p-2 text-center">{{ $totalDays[$benefitType->value] }}</td>
                            <td class="p-2 text-right whitespace-nowrap">{{ $brl($totalCents[$benefitType->value]) }}</td>
                        @endforeach
                        <td class="p-2 text-right whitespace-nowrap">{{ $brl($grandTotalCents) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
