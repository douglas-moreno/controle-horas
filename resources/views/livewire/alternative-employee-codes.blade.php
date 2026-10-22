<div>
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-bold">Correspondência de códigos</h1>
            <p class="text-sm text-gray-600">Código usado na comparação da regra alternativa e no CSV. O CSV não traz nome nem PIS.</p>
        </div>
        <div>
            <x-ui-button icon="arrow-left" label="Voltar para a comparação" href="{{ route('reports.alternative-comparison') }}" />
        </div>
    </div>

    @if (! $enabled)
        <p class="p-4 text-center text-gray-500">A regra alternativa está desligada. Nenhum dado de comparação é produzido.</p>
    @else
        <div class="mb-4 max-w-md">
            <x-ui-input label="Buscar por nome ou código" wire:model.live.debounce.300ms="search" />
        </div>

        <div class="bg-white shadow rounded-lg p-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left border-b">
                        <th class="p-2">Código</th>
                        <th class="p-2">Funcionário</th>
                        <th class="p-2">Função</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->codes as $row)
                        <tr wire:key="code-{{ $row['code'] }}" class="border-t border-gray-200">
                            <td class="p-2 font-mono">{{ $row['code'] }}</td>
                            <td class="p-2">{{ $row['name'] }} @if ($row['terminated'])<span class="text-xs text-gray-500">(desligado)</span>@endif</td>
                            <td class="p-2">{{ $row['position'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="p-4 text-center text-gray-500">Nenhum funcionário encontrado.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
