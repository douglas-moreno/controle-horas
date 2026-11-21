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
        @if ($snapshotNotice !== null)
            <div @class([
                'mb-4 rounded border p-3 text-sm',
                'border-amber-300 bg-amber-50 text-amber-800' => $snapshotNotice['level'] === 'warning',
                'border-blue-300 bg-blue-50 text-blue-800' => $snapshotNotice['level'] === 'info',
                'border-red-300 bg-red-50 text-red-800' => $snapshotNotice['level'] === 'danger',
            ])>
                <div class="font-semibold">{{ $snapshotNotice['title'] }}</div>
                <div>{{ $snapshotNotice['message'] }}</div>
                @if ($snapshotNotice['fields'] !== [])
                    <div class="mt-1">Campos alterados: {{ implode(', ', $snapshotNotice['fields']) }}.</div>
                @endif
            </div>
        @endif
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
                <div class="flex gap-2">
                    <x-ui-button
                        wire:click="showSnapshotVersions" icon="archive-box" secondary label="Versões do Espelho"
                    />
                    <x-ui-button
                        wire:click="openJustificationForm" icon="plus" secondary label="Nova Justificativa"
                        class="hover:transition-all hover:duration-300 hover:scale-110"
                    />
                </div>
            </div>

            @foreach ($movedJustifications as $moved)
                <div wire:key="moved-justification-{{ $moved['id'] }}" class="mb-3 flex items-center justify-between rounded border border-blue-300 bg-blue-50 p-3 text-blue-800">
                    <span>
                        A justificativa #{{ $moved['id'] }} ({{ $moved['reason'] }}) estava nesta data e foi movida para
                        <a href="{{ $moved['new_date_url'] }}" class="underline">{{ $moved['new_date'] }}</a>{{ $moved['canceled'] ? ', onde foi cancelada' : '' }}.
                    </span>
                    <x-ui-button sm secondary icon="clock" label="Histórico" wire:click="showJustificationHistory({{ $moved['id'] }})" />
                </div>
            @endforeach

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
                                    <div class="flex space-x-1">
                                        <x-ui-button sm secondary icon="clock" wire:click="showJustificationHistory({{ $item->id }})" title="Histórico" />
                                        @unless ($item->isCanceled())
                                            <x-ui-button sm secondary icon="pencil" wire:click="editJustification({{ $item->id }})" title="Editar" />
                                            <x-ui-button sm red icon="x-mark" wire:click="confirmCancelJustification({{ $item->id }})" title="Cancelar" />
                                        @endunless
                                    </div>
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

        <x-ui-modal-card wire:model="showHistoryModal" title="Histórico da Justificativa" width="4xl">
            @if ($justificationHistory !== null)
                <div class="space-y-4">
                    <div class="text-sm text-gray-600">
                        Justificativa #{{ $justificationHistory['justification']['id'] }} — {{ $justificationHistory['justification']['reason'] }},
                        {{ $justificationHistory['justification']['occurrence_date'] }}
                        @if ($justificationHistory['justification']['canceled'])
                            <x-ui-badge label="Cancelada" color="gray" />
                        @endif
                    </div>

                    @php($linked = $justificationHistory['linked_point'])
                    @if ($linked !== null)
                        <div @class([
                            'rounded border p-3 text-sm',
                            'border-gray-300 bg-gray-50' => $linked['status'] === 'current',
                            'border-amber-300 bg-amber-50 text-amber-800' => $linked['status'] !== 'current',
                        ])>
                            <div class="font-semibold">Marcação vinculada</div>
                            @if ($linked['status'] === 'current')
                                Marcação #{{ $linked['point_id'] }}: {{ $linked['date'] }} às {{ $linked['time'] }} ({{ $linked['type'] }}).
                                @if ($linked['protection'] !== null)
                                    <div class="mt-1">{{ $linked['protection'] }}</div>
                                @endif
                            @elseif ($linked['status'] === 'removed')
                                Marcação #{{ $linked['point_id'] }} de {{ $linked['date'] }} às {{ $linked['time'] }} removida em {{ $linked['removed_at'] }} por {{ $linked['removed_by'] }} (dados do histórico).
                            @else
                                A marcação #{{ $linked['point_id'] }} não existe mais e não há registro da remoção neste histórico. Último registro: {{ $linked['date'] }} às {{ $linked['time'] }}.
                            @endif
                        </div>
                    @endif

                    <ol class="space-y-3">
                        @foreach ($justificationHistory['entries'] as $entry)
                            <li wire:key="history-entry-{{ $entry['id'] }}" class="rounded border border-gray-200 p-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold">{{ $entry['action'] }}</span>
                                    <span class="text-sm text-gray-600">{{ $entry['performed_at'] }} — {{ $entry['performed_by'] }}</span>
                                    @if ($entry['competence'] !== null)
                                        <span class="text-xs text-gray-500">Competência {{ $entry['competence'] }}</span>
                                    @endif
                                    @if ($entry['closed_period'])
                                        <x-ui-badge label="Competência fechada" color="amber" />
                                    @endif
                                </div>
                                @if ($entry['description'] !== null)
                                    <div class="mt-1 text-sm">{{ $entry['description'] }}</div>
                                @endif
                                @if ($entry['cancellation_reason'] !== null)
                                    <div class="mt-1 text-sm">Motivo do cancelamento: {{ $entry['cancellation_reason'] }}</div>
                                @endif
                                @if ($entry['point_before'] !== null || $entry['point_after'] !== null)
                                    <div class="mt-1 text-sm">Marcação: {{ $entry['point_before'] ?? '—' }} → {{ $entry['point_after'] ?? '—' }}</div>
                                @endif
                                @if ($entry['changes'] !== [])
                                    <table class="mt-2 w-full text-left text-sm">
                                        <thead>
                                            <tr class="text-xs uppercase text-gray-500">
                                                <th class="p-1">Campo</th>
                                                <th class="p-1">Antes</th>
                                                <th class="p-1">Depois</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($entry['changes'] as $change)
                                                <tr class="border-t border-gray-100">
                                                    <td class="p-1">{{ $change['field'] }}</td>
                                                    <td class="p-1">{{ $change['before'] ?? '—' }}</td>
                                                    <td class="p-1">{{ $change['after'] ?? '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif
            <x-slot name="footer">
                <div class="flex justify-end">
                    <x-ui-button secondary wire:click="closeJustificationHistory" label="Fechar" />
                </div>
            </x-slot>
        </x-ui-modal-card>

        <x-ui-modal-card wire:model="showSnapshotsModal" title="Versões Históricas do Espelho" width="5xl">
            @if ($snapshotVersions !== null)
                <div class="space-y-4">
                    <div class="text-sm text-gray-600">
                        Registros gravados do espelho deste funcionário em {{ \Illuminate\Support\Carbon::parse($date)->format('d/m/Y') }}
                        (competência {{ $snapshotVersions['competence'] }}{{ $snapshotVersions['competence_status'] !== null ? ', '.$snapshotVersions['competence_status'] : '' }}).
                        Somente leitura: os dados mostrados são os armazenados, sem recálculo.
                    </div>

                    @if ($snapshotVersions['reference_state'] === 'open')
                        <div class="rounded border border-gray-300 bg-gray-50 p-3 text-sm">A competência não está fechada: alterações são esperadas e não há registro de referência para o aviso de divergência.</div>
                    @elseif ($snapshotVersions['reference_state'] === 'missing')
                        <div class="rounded border border-gray-300 bg-gray-50 p-3 text-sm">Competência fechada sem ponto de partida registrado para este dia (fechada antes da implantação ou funcionário fora do fechamento). Não há como verificar divergência até a primeira alteração autorizada.</div>
                    @elseif ($snapshotVersions['reference_stale'])
                        <div class="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">A referência é anterior ao último fechamento da competência (o funcionário não entrou nele).</div>
                    @endif

                    @if ($snapshotVersions['versions'] === [])
                        <div class="text-sm text-gray-500">Nenhuma versão gravada para este dia.</div>
                    @else
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="text-xs uppercase text-gray-500">
                                    <th class="p-1">Versão</th>
                                    <th class="p-1">Origem</th>
                                    <th class="p-1">Registrado em</th>
                                    <th class="p-1">Responsável</th>
                                    <th class="p-1">Competência</th>
                                    <th class="p-1">Operação</th>
                                    <th class="p-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($snapshotVersions['versions'] as $version)
                                    <tr wire:key="snapshot-version-{{ $version['id'] }}" class="border-t border-gray-100 align-top">
                                        <td class="p-1">v{{ $version['sequence'] }}</td>
                                        <td class="p-1">
                                            <x-ui-badge :label="$version['source_label']" :color="match ($version['source']) { 'closure_baseline' => 'green', 'late_baseline' => 'amber', default => 'gray' }" />
                                            @if ($version['is_reference'])
                                                <x-ui-badge label="Referência atual" color="blue" />
                                            @endif
                                            @if ($version['integrity_problems'] !== [])
                                                <x-ui-badge label="Falha de integridade" color="red" />
                                            @endif
                                            @if ($version['reason'])
                                                <div class="mt-1 text-xs text-gray-600">{{ $version['reason'] }}</div>
                                            @endif
                                        </td>
                                        <td class="p-1">{{ $version['created_at'] }}</td>
                                        <td class="p-1">{{ $version['created_by_name'] }}</td>
                                        <td class="p-1">{{ $version['competence'] }}</td>
                                        <td class="p-1 font-mono text-xs">{{ $version['operation_uuid'] !== null ? \Illuminate\Support\Str::limit($version['operation_uuid'], 8, '') : '—' }}</td>
                                        <td class="p-1 whitespace-nowrap">
                                            <x-ui-button sm secondary icon="eye" wire:click="showSnapshot({{ $version['id'] }})" title="Detalhes" />
                                            <x-ui-button sm secondary icon="printer" :href="$version['print_url']" target="_blank" title="Imprimir" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif

                    @if ($snapshotDetail !== null)
                        <div class="rounded border border-gray-300 p-3 text-sm space-y-2">
                            <div class="font-semibold">Versão v{{ $snapshotDetail['sequence'] }} — {{ $snapshotDetail['source_label'] }}</div>
                            <div class="text-gray-600">{{ $snapshotDetail['origin_note'] }}</div>
                            <div class="text-gray-600">
                                Registrado em {{ $snapshotDetail['created_at'] }} por {{ $snapshotDetail['created_by_name'] }} · Operação {{ $snapshotDetail['operation_uuid'] ?? 'sem identificador' }}
                                · Regra {{ $snapshotDetail['rule_version'] }}
                                @if ($snapshotDetail['point_justification_history_id'] !== null)
                                    · Histórico da justificativa #{{ $snapshotDetail['point_justification_history_id'] }}
                                @endif
                            </div>

                            @if ($snapshotDetail['integrity_problems'] !== [])
                                <div class="rounded border border-red-300 bg-red-50 p-2 text-red-800">
                                    Falha de integridade ({{ implode(', ', $snapshotDetail['integrity_problems']) }}): o registro não confere com os próprios hashes e pode ter sido alterado fora da aplicação. O conteúdo abaixo não é confiável.
                                </div>
                            @elseif ($snapshotDetail['comparison'] !== null)
                                <div @class([
                                    'rounded border p-2',
                                    'border-amber-300 bg-amber-50 text-amber-800' => $snapshotDetail['comparison']['result_changed'],
                                    'border-green-300 bg-green-50 text-green-800' => ! $snapshotDetail['comparison']['result_changed'],
                                ])>
                                    @if ($snapshotDetail['comparison']['result_changed'])
                                        O espelho atual difere desta versão{{ $snapshotDetail['comparison']['fields'] === [] ? '' : ' ('.implode(', ', $snapshotDetail['comparison']['fields']).')' }}.
                                    @else
                                        O espelho atual confere com esta versão.
                                    @endif
                                    @if ($snapshotDetail['comparison']['inputs_changed'] && ! $snapshotDetail['comparison']['result_changed'])
                                        As entradas do cálculo mudaram sem alterar o resultado.
                                    @endif
                                    @if ($snapshotDetail['comparison']['rules_changed'])
                                        O código das regras de cálculo mudou desde o registro.
                                    @endif
                                </div>
                            @endif

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <table class="w-full text-left">
                                    <caption class="text-left text-xs uppercase text-gray-500">Resultado gravado</caption>
                                    @foreach ($snapshotDetail['stored'] as $label => $value)
                                        <tr class="border-t border-gray-100"><td class="p-1 text-gray-600">{{ $label }}</td><td class="p-1">{{ $value !== '' ? $value : '—' }}</td></tr>
                                    @endforeach
                                </table>
                                <table class="w-full text-left">
                                    <caption class="text-left text-xs uppercase text-gray-500">Entradas gravadas</caption>
                                    @foreach ($snapshotDetail['inputs'] as $label => $value)
                                        <tr class="border-t border-gray-100"><td class="p-1 text-gray-600">{{ $label }}</td><td class="p-1">{{ $value !== '' ? $value : '—' }}</td></tr>
                                    @endforeach
                                </table>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
            <x-slot name="footer">
                <div class="flex justify-end">
                    <x-ui-button secondary wire:click="closeSnapshotVersions" label="Fechar" />
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
