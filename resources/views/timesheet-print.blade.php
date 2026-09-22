<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Espelho de Ponto - {{ $startDate->format('d/m/Y') }} a {{ $endDate->format('d/m/Y') }}</title>
    <style>
        @page { size: A4 portrait; margin: 10mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #000; margin: 0; background: #fff; }
        .toolbar { padding: 12px; text-align: center; background: #f3f4f6; border-bottom: 1px solid #d1d5db; }
        .toolbar button { font-size: 14px; padding: 6px 18px; cursor: pointer; }
        .sheet { padding: 10mm; page-break-after: always; break-after: page; }
        .sheet:last-child { page-break-after: auto; break-after: auto; }
        .header { border: 1px solid #000; padding: 6px 8px; margin-bottom: 6px; }
        .header h1 { font-size: 20px; margin: 0 0 4px; text-align: center; text-transform: uppercase; }
        .header .row { display: flex; justify-content: space-between; gap: 12px; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 4px 5px; text-align: center; height: 26px; font-size: 15px; }
        th { background: #e5e7eb; text-transform: uppercase; font-size: 13px; }
        td.date { white-space: nowrap; width: 130px; text-align: left; font-weight: 600; }
        td.time { width: 80px; font-weight: 600; }
        td.observation { text-align: left; }
        tr.highlighted td { background: #f3f4f6; }
        .holiday { font-size: 11px; font-style: italic; }
        .signatures { display: flex; justify-content: space-around; margin-top: 40px; }
        .signatures div { width: 40%; border-top: 1px solid #000; text-align: center; padding-top: 4px; font-size: 13px; }
        .empty { padding: 40px; text-align: center; font-size: 16px; }
        th, td, tr.highlighted td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
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
            <div class="header">
                <h1>Espelho de Ponto</h1>
                <div class="row">
                    <span><strong>Período:</strong> {{ $startDate->format('d/m/Y') }} a {{ $endDate->format('d/m/Y') }}</span>
                    <span><strong>PIS:</strong> {{ $timesheet['employee']->pis }}</span>
                </div>
                <div class="row">
                    <span><strong>Funcionário:</strong> {{ $timesheet['employee']->name }}</span>
                    <span><strong>Função:</strong> {{ $timesheet['employee']->position }}</span>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Entrada</th>
                        <th>Almoço Início</th>
                        <th>Almoço Fim</th>
                        <th>Saída</th>
                        <th>Observação</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($timesheet['days'] as $day)
                        <tr @class(['highlighted' => $day['is_highlighted']])>
                            <td class="date">{{ $day['date'] }}</td>
                            <td class="time">{{ $day['entrada'] }}</td>
                            <td class="time">{{ $day['almoco_inicio'] }}</td>
                            <td class="time">{{ $day['almoco_fim'] }}</td>
                            <td class="time">{{ $day['saida'] }}</td>
                            <td class="observation">
                                @if ($day['holiday'])
                                    <span class="holiday">Feriado: {{ $day['holiday'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="signatures">
                <div>Assinatura do Funcionário</div>
                <div>Assinatura do Responsável</div>
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
