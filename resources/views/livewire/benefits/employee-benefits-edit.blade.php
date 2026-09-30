<div class="space-y-4">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">Benefícios — {{ $employee->name }}</h1>
            <p class="text-sm text-gray-500">PIS: {{ $employee->pis }} · Cargo: {{ $employee->position }}</p>
        </div>
        <x-ui-button warning href="{{ route('employees.index') }}" icon="arrow-left">Voltar para Funcionários</x-ui-button>
    </div>

    <div class="mt-4 max-w-xs">
        <x-ui-datetime-picker
            wire:model.live="referenceDate"
            label="Data de referência da consulta"
            without-time
            display-format="DD/MM/YYYY"
            timezone="America/Sao_Paulo"
            :clearable="false"
            hint="Apenas para consulta nesta tela. O cálculo de cada competência usa o dia 01 do mês."
        />
    </div>

    {{-- Elegibilidade --}}
    <div class="mt-8 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-semibold">Elegibilidade</h2>
            <p class="text-sm text-gray-500">Cada benefício tem vigências próprias. Encerrar uma vigência não afeta os demais benefícios.</p>
        </div>
        <x-ui-button class="transition-all hover:duration-300 hover:scale-110" icon="plus" wire:click="openBenefitModal">Nova Vigência</x-ui-button>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        @foreach ($benefitTypes as $benefitType)
            <div class="rounded border border-gray-200 p-4" wire:key="benefit-type-{{ $benefitType->value }}">
                <div class="flex items-center justify-between">
                    <span class="text-lg font-semibold">{{ $benefitType->label() }}</span>
                    @if (in_array($benefitType, $eligibleTypes, true))
                        <x-ui-badge label="Elegível em {{ $reference->format('d/m/Y') }}" color="green" />
                    @else
                        <x-ui-badge label="Não elegível em {{ $reference->format('d/m/Y') }}" color="gray" />
                    @endif
                </div>
                <ul class="mt-3 space-y-2">
                    @forelse ($benefits->get($benefitType->value, collect()) as $employeeBenefit)
                        <li class="flex items-center justify-between gap-2" wire:key="benefit-{{ $employeeBenefit->id }}">
                            <div>
                                <div>{{ $employeeBenefit->starts_on->format('d/m/Y') }} → {{ $employeeBenefit->ends_on?->format('d/m/Y') ?? 'Em aberto' }}</div>
                                @if ($employeeBenefit->notes)
                                    <div class="text-sm text-gray-500">{{ $employeeBenefit->notes }}</div>
                                @endif
                            </div>
                            @if ($employeeBenefit->ends_on === null)
                                <x-ui-button sm warning wire:click="openEndBenefitModal({{ $employeeBenefit->id }})">Encerrar</x-ui-button>
                            @endif
                        </li>
                    @empty
                        <li class="text-gray-500">Sem vigências.</li>
                    @endforelse
                </ul>
            </div>
        @endforeach
    </div>

    {{-- Itinerário de VT --}}
    <div class="mt-10 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-semibold">Itinerário de VT</h2>
            <p class="text-sm text-gray-500">Para mudar o itinerário, encerre o trecho atual e cadastre o novo. Vários trechos podem valer ao mesmo tempo.</p>
        </div>
        <x-ui-button class="transition-all hover:duration-300 hover:scale-110" icon="plus" wire:click="openRouteModal">Novo Trecho</x-ui-button>
    </div>

    <div class="overflow-x-auto">
        <table class="table-auto w-full border-collapse border border-gray-200 mt-4">
            <thead>
                <tr>
                    <th class="uppercase">Tarifa</th>
                    <th class="uppercase">Operadora</th>
                    <th class="uppercase">Viagens/dia</th>
                    <th class="uppercase">Vigência</th>
                    <th class="uppercase">Preço em {{ $reference->format('d/m/Y') }}</th>
                    <th class="uppercase">Valor diário do trecho</th>
                    <th class="uppercase">Ações</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($routes as $route)
                    @php
                        $routePrice = $routePrices->get($route->transport_fare_id);
                        $isActiveOnReference = $route->starts_on->lessThanOrEqualTo($reference) && ($route->ends_on === null || $route->ends_on->greaterThanOrEqualTo($reference));
                    @endphp
                    <tr class="border-t border-gray-200 {{ $isActiveOnReference ? '' : 'text-gray-400' }}" wire:key="route-{{ $route->id }}">
                        <td class="text-lg p-2">
                            {{ $route->transportFare->name }}
                            @unless ($route->transportFare->is_active)
                                <x-ui-badge label="Tarifa inativa" color="red" />
                            @endunless
                        </td>
                        <td class="text-lg p-2">{{ $route->transportFare->operator }}</td>
                        <td class="text-lg p-2 text-center">{{ $route->trips_per_day }}</td>
                        <td class="text-lg p-2 text-center">{{ $route->starts_on->format('d/m/Y') }} → {{ $route->ends_on?->format('d/m/Y') ?? 'Em aberto' }}</td>
                        <td class="text-lg p-2 text-right">
                            @if ($routePrice)
                                R$ {{ number_format($routePrice->amount, 2, ',', '.') }}
                            @else
                                <span class="text-red-600">Sem preço vigente</span>
                            @endif
                        </td>
                        <td class="text-lg p-2 text-right">
                            @if ($dailyItem = collect($dailyAmount['items'])->firstWhere('route_id', $route->id))
                                R$ {{ number_format($dailyItem['daily_cents'] / 100, 2, ',', '.') }}/dia
                            @else
                                —
                            @endif
                        </td>
                        <td class="flex justify-center gap-2 p-2">
                            @if ($route->ends_on === null)
                                <x-ui-button sm warning wire:click="openEndRouteModal({{ $route->id }})">Encerrar</x-ui-button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center p-4 text-gray-500">Nenhum trecho cadastrado.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="rounded border border-gray-200 p-4">
        <div class="text-sm uppercase text-gray-500">VT diário em {{ $reference->format('d/m/Y') }}</div>
        <div class="text-2xl font-semibold">R$ {{ number_format($dailyAmount['daily_cents'] / 100, 2, ',', '.') }}</div>
        @if ($dailyAmount['missing_prices'] !== [])
            <div class="mt-2 text-red-600">
                Atenção: há trechos vigentes sem preço cadastrado nesta data. Eles não entram no valor diário:
                {{ $routes->pluck('transportFare')->whereIn('id', $dailyAmount['missing_prices'])->unique('id')->pluck('name')->join(', ') }}.
            </div>
        @endif
    </div>

    {{-- Modais --}}
    <x-ui-modal-card wire:model="showBenefitModal" title="Nova Vigência de Benefício">
        <div class="space-y-4">
            <x-ui-select wire:model="benefitType" :options="$benefitTypeOptions" option-label="name" option-value="id" label="Benefício" :clearable="false" />
            <x-ui-datetime-picker wire:model="benefitStartsOn" label="Início" without-time display-format="DD/MM/YYYY" timezone="America/Sao_Paulo" placeholder="Selecione a data" />
            <x-ui-datetime-picker wire:model="benefitEndsOn" label="Fim (opcional)" without-time display-format="DD/MM/YYYY" timezone="America/Sao_Paulo" placeholder="Em aberto" clearable />
            <x-ui-input wire:model="benefitNotes" label="Observação (opcional)" />
        </div>
        <x-slot name="footer">
            <div class="flex justify-end space-x-2">
                <x-ui-button secondary wire:click="$set('showBenefitModal', false)" label="Cancelar" />
                <x-ui-button primary wire:click="createBenefit" label="Salvar Vigência" />
            </div>
        </x-slot>
    </x-ui-modal-card>

    <x-ui-modal-card wire:model="showEndBenefitModal" title="Encerrar Vigência">
        <div class="space-y-4">
            <x-ui-datetime-picker wire:model="benefitEndDate" label="Último dia da vigência" without-time display-format="DD/MM/YYYY" timezone="America/Sao_Paulo" placeholder="Selecione a data" />
        </div>
        <x-slot name="footer">
            <div class="flex justify-end space-x-2">
                <x-ui-button secondary wire:click="$set('showEndBenefitModal', false)" label="Cancelar" />
                <x-ui-button primary wire:click="endBenefit" label="Encerrar Vigência" />
            </div>
        </x-slot>
    </x-ui-modal-card>

    <x-ui-modal-card wire:model="showRouteModal" title="Novo Trecho de VT">
        <div class="space-y-4">
            <x-ui-select wire:model="routeFareId" :options="$fareOptions" option-label="name" option-value="id" label="Tarifa" placeholder="Selecione a tarifa" />
            <x-ui-input wire:model="routeTripsPerDay" type="number" min="1" label="Viagens por dia" />
            <x-ui-datetime-picker wire:model="routeStartsOn" label="Início" without-time display-format="DD/MM/YYYY" timezone="America/Sao_Paulo" placeholder="Selecione a data" />
            <x-ui-datetime-picker wire:model="routeEndsOn" label="Fim (opcional)" without-time display-format="DD/MM/YYYY" timezone="America/Sao_Paulo" placeholder="Em aberto" clearable />
            <x-ui-input wire:model="routeNotes" label="Observação (opcional)" />
        </div>
        <x-slot name="footer">
            <div class="flex justify-end space-x-2">
                <x-ui-button secondary wire:click="$set('showRouteModal', false)" label="Cancelar" />
                <x-ui-button primary wire:click="createRoute" label="Salvar Trecho" />
            </div>
        </x-slot>
    </x-ui-modal-card>

    <x-ui-modal-card wire:model="showEndRouteModal" title="Encerrar Trecho">
        <div class="space-y-4">
            <x-ui-datetime-picker wire:model="routeEndDate" label="Último dia do trecho" without-time display-format="DD/MM/YYYY" timezone="America/Sao_Paulo" placeholder="Selecione a data" />
        </div>
        <x-slot name="footer">
            <div class="flex justify-end space-x-2">
                <x-ui-button secondary wire:click="$set('showEndRouteModal', false)" label="Cancelar" />
                <x-ui-button primary wire:click="endRoute" label="Encerrar Trecho" />
            </div>
        </x-slot>
    </x-ui-modal-card>
</div>
