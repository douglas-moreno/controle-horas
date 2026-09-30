<div class="space-y-4">
    <div class="flex items-center justify-between">
        <div class="flex items-center space-x-2">
            <x-ui-icon name="scale" class="w-5 h-5" />
            <span class="text-2xl font-semibold">Pendências de Saldo</span>
        </div>
        <div class="w-64">
            <x-ui-select wire:model.live="filterStatus" :options="$statusOptions" option-label="name" option-value="id" label="Situação" placeholder="Todas" />
        </div>
    </div>

    <p class="text-sm text-gray-500">
        Saldos negativos gerados por competências fechadas. O saldo é aplicado automaticamente no cálculo da competência seguinte, somente no mesmo benefício e se o funcionário for elegível em 01 do mês. Saldos não aplicados não migram para outras competências e ficam como pendência administrativa.
    </p>

    <div class="overflow-x-auto">
        <table class="table-auto w-full border-collapse border border-gray-200 mt-4">
            <thead>
                <tr>
                    <th class="uppercase text-left p-2">Funcionário</th>
                    <th class="uppercase">Benefício</th>
                    <th class="uppercase">Competência origem</th>
                    <th class="uppercase">Dias</th>
                    <th class="uppercase">Situação</th>
                    <th class="uppercase text-left p-2">Motivo</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr class="border-t border-gray-200" wire:key="carry-{{ $row['employee_id'] }}-{{ $row['benefit_type'] }}-{{ $row['origin_competence'] }}">
                        <td class="p-2">{{ $row['employee_name'] }}</td>
                        <td class="p-2 text-center">{{ strtoupper($row['benefit_type']) }}</td>
                        <td class="p-2 text-center">{{ $row['origin_competence'] }}</td>
                        <td class="p-2 text-center">{{ $row['days'] }}</td>
                        <td class="p-2 text-center">
                            <x-ui-badge :label="$row['status']" :color="match ($row['status']) { 'Aplicado' => 'green', 'Não aplicado' => 'red', default => 'amber' }" />
                        </td>
                        <td class="p-2 text-sm">{{ $row['reason'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center p-4 text-gray-500">Nenhum saldo negativo em competências fechadas.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
