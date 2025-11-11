<div>
    <div class="bg-white shadow rounded-lg p-6 mb-6">
        <h2 class="text-2xl font-semibold mb-4">
            <div>
                Editar Pontos - {{ \Illuminate\Support\Carbon::parse($date)->format('d/m/Y') }}
            </div>
            <div class="text-lg text-gray-600">
                {{ $employee->name }} - {{ $employee->position }}
            </div>
        </h2>
        <div class="grid grid-cols-12 space-y-4">
            @foreach($points as $point)
                    <div class="">
                        <x-ui-input
                            label="Hora"
                            value="{{ \Illuminate\Support\Carbon::parse($point->time)->format('H:i') }}"
                            disabled
                        />
                    </div>
                    <div>
                        @if ($point->type!=='importado')
                            <x-ui-button red sm wire:click="removePoint({{ $point->id }})" icon="trash" 
                                class="hover:transition-all hover:duration-300 hover:scale-110"
                            />
                        @endif
                    </div>
            @endforeach
        </div>
        <div class="flex justify-between mt-6">
            <div class="mt-6">
                <x-ui-button 
                    wire:click="addPoint" icon="plus" secondary label="Adicionar Ponto" 
                    class="hover:transition-all hover:duration-300 hover:scale-110"
                />
            </div>
            
            <div class="mt-6">
                <x-ui-button 
                    href="javascript:history.back()" 
                    label="Voltar para Horas Extras" 
                    primary
                    icon="arrow-left"
                    class="hover:transition-all hover:duration-300 hover:scale-110"
                />
            </div>
        </div>
    </div>

    <x-ui-modal-card
        wire:model="showAddPointModal"
        title="Adicionar Ponto"
    >
        <div class="space-y-4">
            <x-ui-input wire:model="date" disabled label="Data" />
            <x-ui-maskable emit-formatted wire:model="newPointTime" label="Hora" mask="H:m" />
        </div>
        <x-slot name="footer">
            <div class="flex justify-end space-x-2">
                <x-ui-button
                    secondary
                    wire:click="$set('showAddPointModal', false)"
                    label="Cancelar"
                    class="hover:transition-all hover:duration-300 hover:scale-110"
                />
                <x-ui-button
                    primary
                    wire:click="saveNewPoint"
                    label="Adicionar Ponto"
                    class="hover:transition-all hover:duration-300 hover:scale-110"
                />
            </div>
        </x-slot>
    </x-ui-modal-card>
</div>
