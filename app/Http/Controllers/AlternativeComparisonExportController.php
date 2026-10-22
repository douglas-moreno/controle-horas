<?php

namespace App\Http\Controllers;

use App\Http\Requests\AlternativeComparisonExportRequest;
use App\Services\AlternativeComparison;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AlternativeComparisonExportController extends Controller
{
    /**
     * Colunas do CSV: uma linha por funcionário (código pseudonimizado) e dia do período.
     * Minutos inteiros; célula vazia = não calculado.
     *
     * @var list<string>
     */
    public const HEADER = [
        'codigo',
        'data',
        'tipo_dia',
        'marcacoes',
        'oficial_situacao',
        'oficial_extra_min',
        'oficial_atraso_min',
        'alternativa_situacao',
        'alternativa_motivo',
        'alternativa_extra_min',
        'alternativa_atraso_min',
        'divergente',
        'configuracao',
    ];

    /**
     * Exporta a comparação do período em CSV para planilhas (UTF-8 com BOM, separador ";").
     * Sem nome nem PIS (A8). Com a regra alternativa desligada, nada é produzido (404).
     */
    public function __invoke(AlternativeComparisonExportRequest $request, AlternativeComparison $comparison): StreamedResponse
    {
        $start = Carbon::createFromFormat('Y-m-d', $request->validated('start'))->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $request->validated('end'))->startOfDay();

        $result = $comparison->compare($start, $end, 'csv');

        abort_unless($result['enabled'], 404);

        $filename = 'comparacao-regra-alternativa-'.$result['start'].'-a-'.$result['end'].'.csv';

        return response()->streamDownload(function () use ($result) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\u{FEFF}");
            fputcsv($output, self::HEADER, ';', '"', '');

            foreach ($result['days'] as $day) {
                fputcsv($output, array_map(self::cell(...), [
                    $day['code'],
                    Carbon::parse($day['date'])->format('d/m/Y'),
                    $day['day_type'],
                    implode(' ', $day['times']),
                    $day['official_status'],
                    $day['official_overtime_minutes'],
                    $day['official_delay_minutes'],
                    $day['alternative_status'],
                    $day['alternative_review_reason_label'],
                    $day['alternative_overtime_minutes'],
                    $day['alternative_delay_minutes'],
                    $day['divergent'] ? 'sim' : 'não',
                    $result['configuration']['descriptor'],
                ]), ';', '"', '');
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Converte o valor em célula; textos iniciados por =, +, -, @, tabulação ou retorno
     * de carro recebem apóstrofo para não serem interpretados como fórmula.
     */
    public static function cell(string|int|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
