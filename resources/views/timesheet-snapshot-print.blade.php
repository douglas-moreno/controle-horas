<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Versão histórica do espelho de ponto - {{ $employee['name'] }} - {{ $firstDate->format('d/m/Y') }} a {{ $lastDate->format('d/m/Y') }}</title>
    <style>
        @page { size: A4 landscape; margin: 8mm 10mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #000; margin: 0; background: #fff; }
        .toolbar { padding: 12px; text-align: center; background: #f3f4f6; border-bottom: 1px solid #d1d5db; }
        .toolbar button { font-size: 14px; padding: 6px 18px; cursor: pointer; }
        .sheet { padding: 8mm 10mm; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 1px 4px; text-align: center; height: 16px; }
        th { background: #e5e7eb; font-size: 10px; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; break-inside: avoid; }
        .historical { border: 2px solid #000; padding: 4px 8px; margin-bottom: 4px; font-size: 11px; }
        .historical .title { font-weight: bold; font-size: 13px; }
        .header td { text-align: left; vertical-align: top; padding: 3px 6px; height: auto; }
        .header .missing { font-style: italic; font-weight: normal; }
        .grid { margin-top: 4px; }
        td.date { white-space: nowrap; text-align: left; width: 82px; }
        td.time { width: 40px; }
        td.minutes { width: 46px; }
        td.occurrence { text-align: left; width: 150px; font-size: 10px; }
        td.justification { text-align: left; font-size: 9px; }
        td.version { width: 34px; }
        td.later { text-align: left; width: 170px; font-size: 9px; }
        tr.highlighted td { background: #f3f4f6; }
        tr.totals td { font-weight: bold; }
        .integrity { font-weight: bold; }
        .legend { margin-top: 3px; font-size: 9px; }
        th, tr.highlighted td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        @media print {
            .toolbar { display: none; }
            .sheet { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Imprimir</button>
    </div>

    <div class="sheet">
        <div class="historical">
            <div class="title">VERSÃO HISTÓRICA DO ESPELHO DE PONTO — NÃO É O ESPELHO ATUAL</div>
            Reproduz os dados gravados em {{ $selected['created_at'] }}, sem recálculo pelas regras, configurações, horários ou justificativas atuais.
            <div><strong>Origem:</strong> {{ $selected['source_label'] }}. {{ $selected['origin_note'] }}</div>
            @if ($selected['reason'])
                <div><strong>Motivo:</strong> {{ $selected['reason'] }}</div>
            @endif
            <div>
                <strong>Registrado em:</strong> {{ $selected['created_at'] }}
                · <strong>Responsável:</strong> {{ $selected['created_by_name'] }}
                · <strong>Operação:</strong> {{ $selected['operation_uuid'] ?? 'sem identificador' }}
                · <strong>Regra:</strong> {{ $selected['rule_version'] }} (esquema {{ $selected['schema_version'] }})
            </div>
        </div>

        <table class="header">
            <tr>
                <td style="width: 38%">
                    <div><strong>
                        @if ($company['name'])
                            {{ $company['name'] }}
                        @else
                            <span class="missing">Razão social não configurada</span>
                        @endif
                    </strong></div>
                    <div><strong>CNPJ:</strong> {{ $company['cnpj'] ?? 'não configurado' }}</div>
                </td>
                <td style="width: 27%">
                    <strong>Horário de trabalho registrado</strong> (Ent. · Intervalo · Saída)<br>
                    @forelse ($schedule as $scheduleGroup)
                        {{ $scheduleGroup['days'] }}: {{ $scheduleGroup['times']['entry'] }} · {{ $scheduleGroup['times']['break_start'] }}–{{ $scheduleGroup['times']['break_end'] }} · {{ $scheduleGroup['times']['exit'] }}<br>
                    @empty
                        <span class="missing">sem jornada nos dias registrados</span><br>
                    @endforelse
                    Tolerância registrada: {{ $tolerances === [] ? '—' : implode(', ', $tolerances).' min' }}
                </td>
                <td>
                    <div><strong>Funcionário:</strong> {{ $employee['name'] ?? '—' }}</div>
                    <div><strong>PIS:</strong> {{ $employee['pis'] ?? '—' }}</div>
                    <div><strong>Competência:</strong> {{ $selected['competence'] }}</div>
                    <div><strong>Dias desta versão:</strong> {{ $firstDate->format('d/m/Y') }} a {{ $lastDate->format('d/m/Y') }} ({{ $days->count() }})</div>
                </td>
            </tr>
        </table>

        <table class="grid">
            <thead>
                <tr>
                    <th rowspan="2">Data</th>
                    <th colspan="4">Marcações</th>
                    <th rowspan="2">Hora<br>Extra</th>
                    <th rowspan="2">Atraso</th>
                    <th rowspan="2">Ocorrência</th>
                    <th rowspan="2">Justificativa da irregularidade</th>
                    <th rowspan="2">Versão</th>
                    <th rowspan="2">Alterações posteriores</th>
                </tr>
                <tr>
                    <th>Ent.</th>
                    <th>Saída</th>
                    <th>Ent.</th>
                    <th>Saída</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($days as $day)
                    @php($result = $day['result'])
                    <tr @class(['highlighted' => (bool) ($result['is_highlighted'] ?? false)])>
                        <td class="date">{{ $result['display_date'] ?? $day['snapshot']['date'] }}</td>
                        <td class="time">{{ $result['entrada'] ?? '' }}</td>
                        <td class="time">{{ $result['almoco_inicio'] ?? '' }}</td>
                        <td class="time">{{ $result['almoco_fim'] ?? '' }}</td>
                        <td class="time">{{ $result['saida'] ?? '' }}</td>
                        <td class="minutes">{{ ($result['overtime_minutes'] ?? 0) > 0 ? ($result['overtime'] ?? '') : '' }}</td>
                        <td class="minutes">
                            {{ ($result['delay_minutes'] ?? 0) || ($result['allowed_delay_minutes'] ?? 0) > 0 ? ($result['delay'] ?? '') : '' }}
                        </td>
                        <td class="occurrence">
                            @if (! empty($result['holiday']))
                                Feriado: {{ $result['holiday'] }}@if (! empty($result['status_label'])) · @endif
                            @endif
                            {{ collect([$result['status_label'] ?? null, ...($result['hr_annotations'] ?? [])])->filter()->implode(' · ') }}
                        </td>
                        <td class="justification">{{ implode(' · ', $result['justification_notes'] ?? []) }}</td>
                        <td class="version">v{{ $day['snapshot']['sequence'] }}</td>
                        <td class="later">
                            @if ($day['snapshot']['integrity_problems'] !== [])
                                <div class="integrity">FALHA DE INTEGRIDADE ({{ implode(', ', $day['snapshot']['integrity_problems']) }}): conteúdo pode ter sido alterado fora da aplicação.</div>
                            @endif
                            @foreach ($day['later'] as $version)
                                <div>v{{ $version['sequence'] }} — {{ $version['source_label'] }} ({{ $version['created_at'] }})</div>
                            @endforeach
                            @if ($day['current'] !== null)
                                <div>Situação atual: {{ $day['current']['result_changed'] ? 'difere'.($day['current']['fields'] === [] ? '' : ' ('.implode(', ', $day['current']['fields']).')') : 'confere com esta versão' }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
                <tr class="totals">
                    <td colspan="5" style="text-align: right">Totais dos dias registrados:</td>
                    <td class="minutes">{{ $totals['overtime'] }}</td>
                    <td class="minutes">{{ $totals['delay'] }}</td>
                    <td colspan="4"></td>
                </tr>
            </tbody>
        </table>

        <div class="legend">
            Valores conforme gravados no registro histórico. Totais = soma dos dias desta versão. "Alterações posteriores" lista as versões gravadas depois desta
            para o mesmo dia; "Situação atual" compara, só para consulta, esta versão com o espelho atual. Mudanças por caminhos sem snapshot
            (importação AFD, marcações, feriados, configuração) não têm versões intermediárias preservadas.
        </div>
    </div>
</body>
</html>
