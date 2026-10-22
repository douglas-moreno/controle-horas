<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Espelho de Ponto - {{ $startDate->format('d/m/Y') }} a {{ $endDate->format('d/m/Y') }}</title>
    <style>
        @page { size: A4 landscape; margin: 8mm 10mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #000; margin: 0; background: #fff; }
        .toolbar { padding: 12px; text-align: center; background: #f3f4f6; border-bottom: 1px solid #d1d5db; }
        .toolbar button { font-size: 14px; padding: 6px 18px; cursor: pointer; }
        .sheet { padding: 8mm 10mm; page-break-after: always; break-after: page; }
        .sheet:last-child { page-break-after: auto; break-after: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 1px 4px; text-align: center; height: 16px; }
        th { background: #e5e7eb; font-size: 10px; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; break-inside: avoid; }
        .header td { text-align: left; vertical-align: top; padding: 3px 6px; height: auto; }
        .header .company { font-weight: bold; font-size: 12px; }
        .header .title { text-align: center; font-weight: bold; font-size: 12px; }
        .header .schedule { text-align: center; font-size: 10px; }
        .header .missing { font-style: italic; font-weight: normal; }
        .grid { margin-top: 4px; }
        td.date { white-space: nowrap; text-align: left; width: 82px; }
        td.time { width: 40px; }
        td.minutes { width: 46px; }
        td.occurrence { text-align: left; width: 150px; font-size: 10px; }
        td.check { width: 16px; }
        td.signature { width: 70px; }
        tr.highlighted td { background: #f3f4f6; }
        tr.totals td { font-weight: bold; }
        .legend { margin-top: 3px; font-size: 9px; }
        .footer { display: flex; margin-top: 6px; border: 1px solid #000; }
        .footer div { flex: 1; padding: 4px 6px; height: 54px; border-right: 1px solid #000; position: relative; font-size: 10px; }
        .footer div:last-child { border-right: none; }
        .footer .line { position: absolute; bottom: 4px; left: 6px; right: 6px; }
        .empty { padding: 40px; text-align: center; font-size: 16px; }
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

    @forelse ($timesheets as $timesheet)
        <div class="sheet">
            <table class="header">
                <tr>
                    <td style="width: 38%">
                        <div class="company">
                            @if ($company['name'])
                                {{ $company['name'] }}
                            @else
                                <span class="missing">Razão social não configurada</span>
                            @endif
                        </div>
                        <div>
                            <strong>CNPJ:</strong>
                            @if ($company['cnpj'])
                                {{ $company['cnpj'] }}
                            @else
                                <span class="missing">não configurado</span>
                            @endif
                        </div>
                    </td>
                    <td style="width: 27%">
                        <div class="title">ESPELHO DE PONTO</div>
                        <div class="schedule">
                            <strong>Horário de trabalho</strong> (Ent. · Intervalo · Saída)<br>
                            @foreach ($schedule as $scheduleGroup)
                                {{ $scheduleGroup['days'] }}: {{ $scheduleGroup['times']['entry'] }} · {{ $scheduleGroup['times']['break_start'] }}–{{ $scheduleGroup['times']['break_end'] }} · {{ $scheduleGroup['times']['exit'] }}<br>
                            @endforeach
                        </div>
                    </td>
                    <td>
                        <div><strong>Funcionário:</strong> {{ $timesheet['employee']->name }}</div>
                        @if (filled($timesheet['employee']->position))
                            <div><strong>Função:</strong> {{ $timesheet['employee']->position }}</div>
                        @endif
                        <div><strong>Período:</strong> {{ $startDate->format('d/m/Y') }} a {{ $endDate->format('d/m/Y') }}</div>
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
                        <th colspan="3">Abono da chefia</th>
                    </tr>
                    <tr>
                        <th>Ent.</th>
                        <th>Saída</th>
                        <th>Ent.</th>
                        <th>Saída</th>
                        <th>S</th>
                        <th>N</th>
                        <th>Rubrica</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($timesheet['days'] as $day)
                        <tr @class(['highlighted' => $day['is_highlighted']])>
                            <td class="date">{{ $day['display_date'] }}</td>
                            <td class="time">{{ $day['entrada'] }}</td>
                            <td class="time">{{ $day['almoco_inicio'] }}</td>
                            <td class="time">{{ $day['almoco_fim'] }}</td>
                            <td class="time">{{ $day['saida'] }}</td>
                            <td class="minutes">{{ $day['overtime_minutes'] > 0 ? $day['overtime'] : '' }}</td>
                            <td class="minutes">{{ $day['delay_minutes'] ? $day['delay'] : '' }}</td>
                            <td class="occurrence">
                                @if ($day['holiday'])
                                    Feriado: {{ $day['holiday'] }}@if ($day['status_label']) · @endif
                                @endif
                                {{ $day['status_label'] }}
                            </td>
                            <td></td>
                            <td class="check"></td>
                            <td class="check"></td>
                            <td class="signature"></td>
                        </tr>
                    @endforeach
                    <tr class="totals">
                        <td colspan="5" style="text-align: right">Totais do período:</td>
                        <td class="minutes">{{ $timesheet['totals']['overtime'] }}</td>
                        <td class="minutes">{{ $timesheet['totals']['delay'] }}</td>
                        <td class="occurrence">
                            @if ($timesheet['totals']['review_days'] > 0)
                                {{ $timesheet['totals']['review_days'] }} {{ $timesheet['totals']['review_days'] === 1 ? 'dia' : 'dias' }} para conferência
                            @endif
                        </td>
                        <td colspan="4"></td>
                    </tr>
                </tbody>
            </table>

            <div class="legend">
                Atraso = entrada após o horário previsto (tolerância de {{ $tolerance }} min) + saída antecipada, sem contar o intervalo.
                Dias com ocorrência não têm atraso calculado. O atraso é informativo e não representa desconto.
            </div>

            <div class="footer">
                <div>
                    Data e assinatura do funcionário
                    <span class="line">____/____/______ &nbsp; ______________________________</span>
                </div>
                <div>
                    Data e assinatura do responsável
                    <span class="line">____/____/______ &nbsp; ______________________________</span>
                </div>
                <div>
                    Concordo com as marcações acima descritas.
                    <span class="line">______________________________________<br>Assinatura do funcionário</span>
                </div>
            </div>
        </div>
    @empty
        <div class="empty">Nenhum funcionário ativo selecionado.</div>
    @endforelse

    <script>
        window.addEventListener('load', () => window.print());
    </script>
</body>
</html>
