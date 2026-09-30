<div class="space-y-4">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">Ajustes — Competência {{ $period->competence->format('m/Y') }}</h1>
            <div class="mt-1 flex flex-wrap items-center gap-3 text-sm text-gray-500">
                <x-ui-badge :label="$period->status->label()" :color="match ($period->status->value) { 'open' => 'blue', 'calculated' => 'amber', default => 'green' }" />
                <span>Eventos realizados: {{ $period->windowStart()->format('d/m/Y') }} a {{ $period->windowEnd()->format('d/m/Y') }}</span>
                <span>Eventos previstos: {{ $period->referenceDate()->format('d/m/Y') }} a {{ $period->monthEnd()->format('d/m/Y') }}</span>
                <span>
                    Dias-base:
                    @if ($businessDays !== null)
                        {{ $businessDays }}
                    @else
                        {{ $calendarBusinessDays }} (pelo calendário atual; congelado no cálculo)
                    @endif
                </span>
                <span>Última batida importada: {{ $lastImportedPointDate?->format('d/m/Y') ?? 'nenhuma' }}</span>
            </div>
        </div>
        <div class="flex gap-2">
            <x-ui-button warning href="{{ route('benefits.periods.index') }}" icon="arrow-left">Voltar para Competências</x-ui-button>
            @if ($isEditable)
                <x-ui-button class="transition-all hover:duration-300 hover:scale-110" icon="plus" wire:click="openCreateModal">Novo ajuste</x-ui-button>
            @endif
        </div>
    </div>

    @if (! $isEditable)
        <div class="rounded border border-gray-300 bg-gray-50 p-3 text-gray-700">
            Competência fechada: os ajustes estão congelados e não podem ser lançados, alterados ou revisados.
        </div>
    @elseif ($period->status->value === 'calculated')
        <div class="rounded border border-amber-300 bg-amber-50 p-3 text-amber-800">
            Competência calculada: qualquer alteração de ajuste descarta a prévia e devolve a competência para Aberta.
        </div>
    @endif

    <p class="text-sm text-gray-500">
        Ajustes não são excluídos: para cancelar um lançamento, rejeite-o com uma nota. O histórico é preservado para auditoria.
    </p>

    @if ($isEditable)
        <div class="rounded border border-gray-200 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-lg font-semibold">Sugestões do ponto</h2>
                    <p class="text-sm text-gray-500">
                        Analisa as batidas de {{ $period->windowStart()->format('d/m/Y') }} a {{ $period->windowEnd()->format('d/m/Y') }}: dia útil sem batida sugere ausência; sábado, domingo ou feriado com batida sugere dia trabalhado. As sugestões entram como pendentes.
                    </p>
                </div>
                <x-ui-button primary icon="arrow-path" wire:click="generateSuggestions" :disabled="$generationBlocker !== null">Gerar sugestões do ponto</x-ui-button>
            </div>
            @if ($generationBlocker !== null)
                <div class="mt-2 text-sm text-red-700">{{ $generationBlocker }}</div>
            @endif
            @if ($generationSummary !== null)
                <div class="mt-2 text-sm">
                    Sugestões geradas: {{ $generationSummary['created'] }} ·
                    Já existentes: {{ $generationSummary['skipped_existing'] }} ·
                    Ignoradas por cobertura: {{ $generationSummary['skipped_covered'] }}
                </div>
            @endif
        </div>
    @endif

    <div class="rounded border border-gray-200 p-4">
        <h2 class="text-lg font-semibold">Conflitos para revisão</h2>
        @forelse ($conflicts as $conflict)
            <div class="mt-1 text-sm {{ in_array($conflict['type'], ['absence_with_punch', 'work_on_absence', 'duplicated_pis'], true) ? 'text-amber-800' : 'text-blue-800' }}" wire:key="conflict-{{ $loop->index }}">
                {{ match ($conflict['type']) {
                    'absence_with_punch' => 'Ausência com batida',
                    'work_on_absence' => 'Trabalho em dia de ausência',
                    'employee_rescinded' => 'Funcionário com rescisão',
                    'unknown_pis' => 'PIS desconhecido',
                    'duplicated_pis' => 'PIS duplicado',
                    default => 'Conflito',
                } }}: {{ $conflict['message'] }}
            </div>
        @empty
            <p class="mt-1 text-sm text-gray-500">Nenhum conflito encontrado.</p>
        @endforelse
    </div>

    <div class="grid gap-4 md:grid-cols-4">
        <x-ui-select wire:model.live="filterEmployeeId" :options="$employeeOptions" option-label="name" option-value="id" label="Funcionário" placeholder="Todos" />
        <x-ui-select wire:model.live="filterReason" :options="$reasonOptions" option-label="name" option-value="id" label="Motivo" placeholder="Todos" />
        <x-ui-select wire:model.live="filterSource" :options="$sourceOptions" option-label="name" option-value="id" label="Origem" placeholder="Todas" />
        <x-ui-select wire:model.live="filterStatus" :options="$statusOptions" option-label="name" option-value="id" label="Status" placeholder="Todos" />
    </div>

    @if ($isEditable)
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-500">{{ count($selected) }} selecionado(s)</span>
            <x-ui-button sm positive wire:click="confirmSelected" :disabled="$selected === []">Confirmar selecionados</x-ui-button>
            <x-ui-button sm negative wire:click="openRejectSelectedModal" :disabled="$selected === []">Rejeitar selecionados</x-ui-button>
        </div>
    @endif

    @foreach ($sections as $section)
        <div class="mt-6" wire:key="section-{{ $section['timing']->value }}">
            <h2 class="text-xl font-semibold">
                @switch($section['timing']->value)
                    @case('forecast') Previstos @break
                    @case('realized') Realizados @break
                    @default Correções retroativas
                @endswitch
                <span class="text-sm font-normal text-gray-500">({{ $section['adjustments']->count() }})</span>
            </h2>

            <div class="overflow-x-auto">
                <table class="table-auto w-full border-collapse border border-gray-200 mt-2">
                    <thead>
                        <tr>
                            <th></th>
                            <th class="uppercase">#</th>
                            <th class="uppercase">Funcionário</th>
                            <th class="uppercase">Período</th>
                            <th class="uppercase">Motivo</th>
                            <th class="uppercase">Origem</th>
                            <th class="uppercase">Status</th>
                            <th class="uppercase">Dias</th>
                            @foreach ($benefitTypes as $benefitType)
                                <th class="uppercase">{{ $benefitType->label() }}</th>
                            @endforeach
                            <th class="uppercase">Observações</th>
                            <th class="uppercase">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($section['adjustments'] as $adjustment)
                            @php($impacts = $adjustment->impacts->keyBy(fn ($impact) => $impact->benefit_type->value))
                            <tr class="border-t border-gray-200 align-top {{ $adjustment->status->value === 'rejected' ? 'text-gray-400' : '' }}" wire:key="adjustment-{{ $adjustment->id }}">
                                <td class="p-2 text-center">
                                    @if ($isEditable && $adjustment->status->value === 'pending')
                                        <input type="checkbox" wire:model.live="selected" value="{{ $adjustment->id }}" />
                                    @endif
                                </td>
                                <td class="p-2 text-center">{{ $adjustment->id }}</td>
                                <td class="p-2">{{ $adjustment->employee->name }}</td>
                                <td class="p-2 text-center whitespace-nowrap">
                                    {{ $adjustment->starts_on->format('d/m/Y') }}
                                    @unless ($adjustment->starts_on->equalTo($adjustment->ends_on))
                                        a {{ $adjustment->ends_on->format('d/m/Y') }}
                                    @endunless
                                    @if ($section['timing']->value === 'retroactive')
                                        <x-ui-badge label="Retroativo" color="purple" />
                                    @endif
                                </td>
                                <td class="p-2">{{ $adjustment->reason->label() }}</td>
                                <td class="p-2 text-center">{{ $adjustment->source->label() }}</td>
                                <td class="p-2 text-center">
                                    <x-ui-badge :label="$adjustment->status->label()" :color="match ($adjustment->status->value) { 'pending' => 'amber', 'confirmed' => 'green', default => 'gray' }" />
                                </td>
                                <td class="p-2 text-center">{{ $adjustment->days_count }}</td>
                                @foreach ($benefitTypes as $benefitType)
                                    @php($quantity = $impacts->get($benefitType->value)?->quantity)
                                    <td class="p-2 text-center {{ $quantity > 0 ? 'text-green-700' : ($quantity < 0 ? 'text-red-700' : '') }}">
                                        {{ $quantity === null ? '—' : ($quantity > 0 ? '+'.$quantity : $quantity) }}
                                    </td>
                                @endforeach
                                <td class="p-2 text-sm">
                                    @if ($adjustment->notes)
                                        <div>{{ $adjustment->notes }}</div>
                                    @endif
                                    @if ($adjustment->related_adjustment_id)
                                        <div class="text-gray-500">Substitui o ajuste #{{ $adjustment->related_adjustment_id }}</div>
                                    @endif
                                    @if ($adjustment->review_notes)
                                        <div class="text-gray-500">Revisão{{ $adjustment->reviewedBy ? ' ('.$adjustment->reviewedBy->name.')' : '' }}: {{ $adjustment->review_notes }}</div>
                                    @endif
                                    @foreach ($alerts[$adjustment->id] ?? [] as $alert)
                                        <div class="{{ match ($alert['level']) { 'block' => 'text-red-700', 'warning' => 'text-amber-700', default => 'text-blue-700' } }}">
                                            {{ match ($alert['level']) { 'block' => 'Bloqueio', 'warning' => 'Alerta', default => 'Informativo' } }}: {{ $alert['message'] }}
                                        </div>
                                    @endforeach
                                </td>
                                <td class="p-2">
                                    @if ($isEditable)
                                        <div class="flex flex-wrap justify-center gap-2">
                                            @switch($adjustment->status->value)
                                                @case('pending')
                                                    <x-ui-button sm wire:click="openUpdateModal({{ $adjustment->id }})">Alterar</x-ui-button>
                                                    <x-ui-button sm positive wire:click="confirm({{ $adjustment->id }})">Confirmar</x-ui-button>
                                                    <x-ui-button sm negative wire:click="openRejectModal({{ $adjustment->id }})">Rejeitar</x-ui-button>
                                                    @break
                                                @case('confirmed')
                                                    <x-ui-button sm wire:click="openReplaceModal({{ $adjustment->id }})">Substituir</x-ui-button>
                                                    <x-ui-button sm negative wire:click="openRejectModal({{ $adjustment->id }})">Rejeitar</x-ui-button>
                                                    @break
                                                @default
                                                    <x-ui-button sm warning wire:click="reconsider({{ $adjustment->id }})" wire:confirm="Reconsiderar o ajuste #{{ $adjustment->id }}? Ele volta para pendente.">Reconsiderar</x-ui-button>
                                            @endswitch
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ 10 + count($benefitTypes) }}" class="text-center p-4 text-gray-500">Nenhum ajuste.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    {{-- Modais --}}
    <x-ui-modal-card wire:model="showFormModal" :title="match ($formMode) { 'update' => 'Alterar Ajuste #'.$formAdjustmentId, 'replace' => 'Substituir Ajuste #'.$formAdjustmentId, default => 'Novo Ajuste' }">
        <div class="space-y-4">
            @if ($formMode === 'replace')
                <p class="text-sm text-gray-500">O ajuste original será rejeitado (nota automática "substituído") e um novo ajuste será criado vinculado a ele.</p>
            @endif

            @error('form')
                <div class="rounded border border-red-300 bg-red-50 p-3 text-red-700">Bloqueio: {{ $message }}</div>
            @enderror

            <x-ui-select wire:model.live="formEmployeeId" :options="$employeeOptions" option-label="name" option-value="id" label="Funcionário" placeholder="Selecione o funcionário" :disabled="$formMode !== 'create'" />
            <x-ui-select wire:model.live="formReason" :options="$reasonOptions" option-label="name" option-value="id" label="Motivo" :clearable="false" />
            <div class="grid gap-4 md:grid-cols-2">
                <x-ui-datetime-picker wire:model.live="formStartsOn" label="Data inicial" without-time display-format="DD/MM/YYYY" timezone="America/Sao_Paulo" placeholder="Selecione a data" />
                <x-ui-datetime-picker wire:model.live="formEndsOn" label="Data final" without-time display-format="DD/MM/YYYY" timezone="America/Sao_Paulo" placeholder="Igual à inicial" clearable />
            </div>

            @if ($formPreview !== null && $formPreview['is_manual'])
                <div>
                    <p class="text-sm text-gray-500">Ajuste manual: informe a quantidade assinada de cada benefício (ex.: +2 acrescenta, -1 desconta). Deixe em branco o que não muda.</p>
                    <div class="grid gap-4 md:grid-cols-3">
                        <x-ui-input wire:model="formImpactVt" type="number" label="Impacto VT" />
                        <x-ui-input wire:model="formImpactVr" type="number" label="Impacto VR" />
                        <x-ui-input wire:model="formImpactVd" type="number" label="Impacto VD" />
                    </div>
                </div>
            @elseif ($formPreview !== null)
                <div class="rounded border border-gray-200 p-3 text-sm">
                    Impacto automático:
                    @if ($formPreview['days_count'] !== null)
                        {{ $formPreview['days_count'] }} dia(s) →
                        VT {{ $formPreview['sign'] > 0 ? '+' : '-' }}{{ $formPreview['days_count'] }},
                        VR {{ $formPreview['sign'] > 0 ? '+' : '-' }}{{ $formPreview['days_count'] }},
                        VD {{ $formPreview['sign'] > 0 ? '+' : '-' }}{{ $formPreview['days_count'] }}.
                    @else
                        {{ $formPreview['sign'] > 0 ? '+1 dia' : '-1 por dia útil' }} em VT, VR e VD.
                    @endif
                    <span class="text-gray-500">{{ $formPreview['sign'] > 0 ? 'Trabalho representa um único dia.' : 'Sábados, domingos e feriados não são descontados.' }} Os impactos não são editáveis.</span>
                </div>
            @endif

            @if ($formPreview !== null && $formPreview['timing'] !== null)
                <div class="text-sm {{ $formPreview['timing']->value === 'retroactive' ? 'text-purple-700' : 'text-gray-500' }}">
                    Classe: {{ $formPreview['timing']->label() }}{{ $formPreview['timing']->value === 'retroactive' ? ' — observação obrigatória.' : '' }}
                </div>
            @endif

            <x-ui-textarea wire:model="formNotes" label="Observação" hint="Obrigatória para ajuste manual e correção retroativa." />

            @foreach ($formPreview['alerts'] ?? [] as $alert)
                <div class="rounded border p-2 text-sm {{ match ($alert['level']) { 'block' => 'border-red-300 bg-red-50 text-red-700', 'warning' => 'border-amber-300 bg-amber-50 text-amber-800', default => 'border-blue-300 bg-blue-50 text-blue-800' } }}">
                    {{ match ($alert['level']) { 'block' => 'Bloqueio', 'warning' => 'Alerta', default => 'Informativo' } }}: {{ $alert['message'] }}
                </div>
            @endforeach
        </div>
        <x-slot name="footer">
            <div class="flex justify-end space-x-2">
                <x-ui-button secondary wire:click="$set('showFormModal', false)" label="Cancelar" />
                <x-ui-button primary wire:click="save" :label="match ($formMode) { 'update' => 'Salvar Alteração', 'replace' => 'Substituir', default => 'Lançar Ajuste' }" />
            </div>
        </x-slot>
    </x-ui-modal-card>

    <x-ui-modal-card wire:model="showRejectModal" title="Rejeitar Ajuste">
        <div class="space-y-4">
            <p class="text-sm text-gray-500">
                {{ count($rejectingIds) > 1 ? count($rejectingIds).' ajustes serão rejeitados.' : 'O ajuste será rejeitado.' }}
                Ajustes rejeitados não afetam a apuração e permanecem no histórico.
            </p>
            <x-ui-textarea wire:model="rejectNotes" label="Motivo da rejeição" />
        </div>
        <x-slot name="footer">
            <div class="flex justify-end space-x-2">
                <x-ui-button secondary wire:click="$set('showRejectModal', false)" label="Cancelar" />
                <x-ui-button negative wire:click="reject" label="Rejeitar" />
            </div>
        </x-slot>
    </x-ui-modal-card>
</div>
