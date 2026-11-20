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
                        @if (isset($annotationLabels[$point->id]))
                            <span class="text-xs text-gray-500" title="{{ \App\Models\Point::ANNOTATED_BY_HR_MESSAGE }}">{{ $annotationLabels[$point->id] }}</span>
                        @elseif (in_array($point->id, $retainedPointIds, true))
                            <span class="text-xs text-gray-500" title="{{ \App\Models\Point::RETAINED_BY_HR_MESSAGE }}">Mantido pelo RH</span>
                        @elseif (in_array($point->id, $justifiedPointIds, true))
                            <span class="text-xs text-gray-500" title="{{ \App\Models\Point::LINKED_TO_JUSTIFICATION_MESSAGE }}">Justificativa</span>
                        @elseif ($point->type!=='importado')
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

    @if ($canManageJustifications)
        <div class="bg-white shadow rounded-lg p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xl font-semibold">Justificativas</h3>
                <x-ui-button
                    wire:click="openJustificationForm" icon="plus" secondary label="Nova Justificativa"
                    class="hover:transition-all hover:duration-300 hover:scale-110"
                />
            </div>

            @if ($justifications->isEmpty())
                <p class="text-gray-500">Nenhuma justificativa registrada nesta data.</p>
            @else
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-sm uppercase text-gray-600">
                            <th class="p-2">Motivo</th>
                            <th class="p-2">Tratamento</th>
                            <th class="p-2">Período</th>
                            <th class="p-2">Duração</th>
                            <th class="p-2">Observação</th>
                            <th class="p-2">Registro</th>
                            <th class="p-2">Situação</th>
                            <th class="p-2">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($justifications as $item)
                            <tr wire:key="justification-{{ $item->id }}" class="border-t border-gray-200 align-top">
                                <td class="p-2">
                                    <div>{{ $item->reason->name }}</div>
                                    <div class="text-xs text-gray-500">{{ $item->reason->category->label() }}</div>
                                </td>
                                <td class="p-2">
                                    {{ $item->treatment->label() }}
                                    @if ($item->allowed_minutes !== null)
                                        <div class="text-xs text-gray-500">{{ \App\Services\OvertimeCalculator::format($item->allowed_minutes) }} abonadas</div>
                                    @endif
                                </td>
                                <td class="p-2">
                                    @if ($item->punch_kind !== null)
                                        {{ $item->punch_kind->label() }} às {{ substr($item->start_time, 0, 5) }}
                                    @elseif ($item->start_time !== null)
                                        {{ substr($item->start_time, 0, 5) }} – {{ substr($item->end_time, 0, 5) }}{{ $item->ends_next_day ? ' (dia seguinte)' : '' }}
                                    @else
                                        Dia inteiro
                                    @endif
                                </td>
                                <td class="p-2">{{ $item->duration_minutes === null ? '—' : \App\Services\OvertimeCalculator::format($item->duration_minutes) }}</td>
                                <td class="p-2 text-sm">{{ $item->observation }}</td>
                                <td class="p-2 text-sm">
                                    {{ $item->creator?->name ?? '—' }}
                                    <div class="text-xs text-gray-500">{{ $item->created_at->format('d/m/Y H:i') }}</div>
                                    @if ($item->updated_by !== null)
                                        <div class="text-xs text-gray-500">Alterada por {{ $item->updater?->name ?? '—' }} em {{ $item->updated_at->format('d/m/Y H:i') }}</div>
                                    @endif
                                </td>
                                <td class="p-2">
                                    @if ($item->isCanceled())
                                        <x-ui-badge label="Cancelada" color="gray" />
                                        <div class="mt-1 text-xs text-gray-500">{{ $item->canceler?->name ?? '—' }} em {{ $item->canceled_at->format('d/m/Y H:i') }}: {{ $item->cancellation_reason }}</div>
                                    @else
                                        <x-ui-badge label="Ativa" color="green" />
                                    @endif
                                    @if ($item->manual_point_id !== null)
                                        <div class="mt-1"><x-ui-badge label="Marcação manual" color="orange" /></div>
                                    @endif
                                </td>
                                <td class="p-2">
                                    @unless ($item->isCanceled())
                                        <div class="flex space-x-1">
                                            <x-ui-button sm secondary icon="pencil" wire:click="editJustification({{ $item->id }})" title="Editar" />
                                            <x-ui-button sm red icon="x-mark" wire:click="confirmCancelJustification({{ $item->id }})" title="Cancelar" />
                                        </div>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <x-ui-modal-card wire:model="showJustificationModal" :title="$isEditingJustification ? 'Editar Justificativa' : 'Nova Justificativa'">
            <div class="space-y-4">
                @error('justification.form')
                    <div class="rounded border border-red-300 bg-red-50 p-3 text-red-700">{{ $message }}</div>
                @enderror
                @error('justification.request_uuid')
                    <div class="rounded border border-red-300 bg-red-50 p-3 text-red-700">{{ $message }}</div>
                @enderror
                @error('justification.occurrence_date')
                    <div class="rounded border border-red-300 bg-red-50 p-3 text-red-700">{{ $message }}</div>
                @enderror
                @error('justification.period')
                    <div class="rounded border border-red-300 bg-red-50 p-3 text-red-700">{{ $message }}</div>
                @enderror
                @error('justification.version')
                    <div class="rounded border border-amber-300 bg-amber-50 p-3 text-amber-800">{{ $message }}</div>
                @enderror

                <div class="text-sm text-gray-600">
                    {{ $employee->name }} — {{ \Illuminate\Support\Carbon::parse($date)->format('d/m/Y') }}
                    @if ($closedPeriod !== null)
                        <div class="mt-1"><x-ui-badge :label="'Competência fechada '.$closedPeriod->competence->format('m/Y')" color="amber" /></div>
                    @endif
                </div>

                @if ($isEditingJustification)
                    <x-ui-input type="date" wire:model="justification.occurrence_date" label="Data da ocorrência" />
                @endif

                <x-ui-select
                    wire:model.live="justification.reason_id" :options="$reasonOptions" option-label="name" option-value="id"
                    label="Motivo" placeholder="Selecione o motivo" :clearable="false"
                />

                @if ($selectedReason !== null)
                    <x-ui-select
                        wire:model.live="justification.treatment" :options="$treatmentOptions" option-label="name" option-value="id"
                        label="Tratamento" placeholder="Selecione o tratamento" :clearable="false"
                    />

                    @if ($showsFullDayChoice)
                        <x-ui-toggle wire:model.live="justification.full_day" label="Dia inteiro" />
                    @endif

                    @if ($showsInterval)
                        <div class="grid gap-4 md:grid-cols-2">
                            <x-ui-maskable emit-formatted wire:model="justification.start_time" label="Horário inicial" mask="##:##" />
                            <x-ui-maskable emit-formatted wire:model="justification.end_time" label="Horário final" mask="##:##" />
                        </div>
                        <x-ui-checkbox wire:model="justification.ends_next_day" label="Termina no dia seguinte" />
                    @endif

                    @if ($showsSinglePunch)
                        @if ($selectedReason->creates_manual_point)
                            <p class="text-sm text-gray-500">Este motivo cria a marcação manual no horário informado. O tipo deve corresponder à posição da marcação na sequência do dia (ímpar: entrada; par: saída).</p>
                        @endif
                        <div class="grid gap-4 md:grid-cols-2">
                            <x-ui-select
                                wire:model="justification.punch_kind" :options="$punchKindOptions" option-label="name" option-value="id"
                                label="Tipo de marcação" placeholder="Selecione" :clearable="false"
                            />
                            <x-ui-maskable emit-formatted wire:model="justification.start_time" label="Horário" mask="##:##" />
                        </div>
                    @endif

                    @if ($showsAllowedMinutes)
                        <x-ui-input wire:model="justification.allowed_minutes" type="number" min="1" label="Minutos abonados" />
                    @endif

                    <x-ui-textarea wire:model="justification.observation" label="Observação" />
                @endif
            </div>
            <x-slot name="footer">
                <div class="flex justify-end space-x-2">
                    <x-ui-button secondary wire:click="$set('showJustificationModal', false)" label="Fechar" />
                    <x-ui-button
                        primary wire:click="submitJustification" :label="$isEditingJustification ? 'Salvar Alterações' : 'Registrar'" spinner="submitJustification"
                        wire:loading.attr="disabled" wire:target="submitJustification" :disabled="$selectedReason === null"
                    />
                </div>
            </x-slot>
        </x-ui-modal-card>

        <x-ui-modal-card wire:model="showCancelJustificationModal" title="Cancelar Justificativa">
            <div class="space-y-4">
                @foreach (['cancellation.form', 'cancellation.version', 'cancellation.occurrence_date'] as $errorKey)
                    @error($errorKey)
                        <div class="rounded border border-red-300 bg-red-50 p-3 text-red-700">{{ $message }}</div>
                    @enderror
                @endforeach

                @if ($cancelingJustification !== null)
                    <p>
                        Confirma o cancelamento da justificativa <strong>{{ $cancelingJustification->reason->name }}</strong>
                        de {{ $cancelingJustification->occurrence_date->format('d/m/Y') }}? A justificativa continua no histórico como cancelada.
                    </p>
                    @if ($cancelingJustification->manualPoint !== null)
                        <div class="rounded border border-amber-300 bg-amber-50 p-3 text-amber-800">
                            A marcação manual das {{ \Illuminate\Support\Carbon::parse($cancelingJustification->manualPoint->time)->format('H:i') }} criada por esta justificativa será removida.
                        </div>
                    @endif
                @endif

                <x-ui-textarea wire:model="cancellationReason" label="Motivo do cancelamento" />
            </div>
            <x-slot name="footer">
                <div class="flex justify-end space-x-2">
                    <x-ui-button secondary wire:click="$set('showCancelJustificationModal', false)" label="Voltar" />
                    <x-ui-button
                        red wire:click="cancelJustification" label="Confirmar Cancelamento" spinner="cancelJustification"
                        wire:loading.attr="disabled" wire:target="cancelJustification"
                    />
                </div>
            </x-slot>
        </x-ui-modal-card>
    @endif
</div>
