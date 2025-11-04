<div>
    <div class="flex justify-between items-center mb-4">
        <div>
            <h1 class="text-2xl font-bold">Funcionário</h1>
            <h2 class="text-lg text-gray-600">{{ $employee->name }} - {{ $employee->position }}</h2>
        </div>
        <div>
            <x-ui-button href="{{ route('employees.index') }}" icon="arrow-left">Voltar para Funcionários</x-ui-button>
        </div>
    </div>
    <div class="bg-white shadow rounded-lg p-6">
        @php
            // Agrupa pontos por data (formato Y-m-d) e ordena os grupos por data
            $groups = $employee->points
                        ->sortBy(function($p){ return \Illuminate\Support\Carbon::parse($p->date)->format('Y-m-d') . ' ' . ($p->time ?? ''); })
                        ->groupBy(function($p){
                            return \Illuminate\Support\Carbon::parse($p->date)->format('Y-m-d');
                        });
        @endphp

        <table class="table-auto w-full border-collapse border border-gray-200">
            <thead>
                <tr>
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
                        <td colspan="5" class="text-center p-4 text-gray-500">Nenhum ponto registrado.</td>
                    </tr>
                @else
                    @foreach($groups as $date => $points)
                        @php
                            // Ordena horários e atribui às colunas, deixando vazias quando ausentes
                            $times = $points->sortBy('time')->pluck('time')->values();
                            $entrada = $times->get(0) ?? '';
                            $almoco_inicio = $times->get(1) ?? '';
                            $almoco_fim = $times->get(2) ?? '';
                            $saida = $times->get(3) ?? '';
                            $displayDate = \Illuminate\Support\Carbon::parse($date)->format('d/m/Y');
                        @endphp
                        <tr class="border-t border-gray-200">
                            <td class="text-lg p-2">{{ $displayDate }}</td>
                            <td class="text-lg p-2">{{ $entrada }}</td>
                            <td class="text-lg p-2">{{ $almoco_inicio }}</td>
                            <td class="text-lg p-2">{{ $almoco_fim }}</td>
                            <td class="text-lg p-2">{{ $saida }}</td>
                        </tr>
                    @endforeach
                @endif
            </tbody>
        </table>
    </div>
</div>
