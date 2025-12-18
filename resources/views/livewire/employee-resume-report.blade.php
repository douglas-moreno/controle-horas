<div>
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-bold">Resumo de Horas Extras</h1>
            <p class="text-sm text-gray-600">Período: <strong>{{ \Illuminate\Support\Carbon::parse($startDate)->format('d/m/Y') }}</strong> — <strong>{{ \Illuminate\Support\Carbon::parse($endDate)->format('d/m/Y') }}</strong></p>
        </div>
    </div>
    <div class="flex items-center justify-between mb-4">
        <div class="space-x-2 items-center flex">
            <x-ui-button label="Anterior" icon="arrow-left" wire:click="periodoAnterior" />
            <x-ui-button label="Próximo" right-icon="arrow-right" wire:click="periodoProximo" />
        </div>
    </div>

    <div class="overflow-auto">
        <table class="w-full table-auto border-collapse border border-gray-200">
            <thead>
                <tr class="bg-gray-100">
                    <th class="p-2 text-left">Nome</th>
                    <th class="p-2 text-center">Seg-Sex</th>
                    <th class="p-2 text-center">Sábado</th>
                    <th class="p-2 text-center">Domingo</th>
                    <th class="p-2 text-center">Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse($results as $row)
                    <tr class="border-t border-gray-200">
                        <td class="p-2">{{ $row['employee']->name }}</td>
                        <td class="p-2 text-center">{{ $row['weekday_hours'] }}</td>
                        <td class="p-2 text-center">{{ $row['saturday_hours'] }}</td>
                        <td class="p-2 text-center">{{ $row['sunday_hours'] }}</td>
                        <td class="p-2 text-center"><strong>{{ $row['total_hours'] }}</strong></td>
                    </tr>
                @empty
                    <tr>
                        <td class="border px-2 py-4 text-center" colspan="5">Nenhum funcionário encontrado neste período.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
