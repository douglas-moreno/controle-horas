<?php

namespace App\Services;

use App\Models\Holiday;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Regra vigente de horas extras por dia, extraída sem alteração das telas de
 * horas extras e dos relatórios.
 *
 * - Sem entrada ou sem saída: zero.
 * - Duração = saída − entrada − intervalo marcado (início e fim do almoço). Sem as
 *   duas marcações de almoço e com mais de 6h, desconta 1h padrão.
 * - Sábado, domingo e feriado: toda a duração é extra.
 * - Segunda a quinta: o que passar de 540 minutos; sexta: o que passar de 480.
 *   Duração menor que a jornada não gera saldo negativo.
 */
class OvertimeCalculator
{
    public const MONDAY_TO_THURSDAY_MINUTES = 540;

    public const FRIDAY_MINUTES = 480;

    public const DEFAULT_LUNCH_THRESHOLD_MINUTES = 360;

    public const DEFAULT_LUNCH_MINUTES = 60;

    /**
     * @param  array{entrada: string, almoco_inicio: string, almoco_fim: string, saida: string}  $slots  horários H:i ou vazio
     */
    public function minutes(CarbonInterface $date, array $slots): int
    {
        if (empty($slots['entrada']) || empty($slots['saida'])) {
            return 0;
        }

        $day = $date->format('Y-m-d');
        $isFullOvertimeDay = $date->isWeekend() || Holiday::isHoliday($day);

        $start = Carbon::parse($day.' '.$slots['entrada']);
        $end = Carbon::parse($day.' '.$slots['saida']);
        if ($end < $start) {
            $end->addDay();
        }

        $total = (int) $start->diffInMinutes($end);

        if (! empty($slots['almoco_inicio']) && ! empty($slots['almoco_fim'])) {
            $lunchStart = Carbon::parse($day.' '.$slots['almoco_inicio']);
            $lunchEnd = Carbon::parse($day.' '.$slots['almoco_fim']);
            if ($lunchEnd < $lunchStart) {
                $lunchEnd->addDay();
            }
            $total -= (int) $lunchStart->diffInMinutes($lunchEnd);
        } elseif ($total > self::DEFAULT_LUNCH_THRESHOLD_MINUTES) {
            $total -= self::DEFAULT_LUNCH_MINUTES;
        }

        if ($isFullOvertimeDay) {
            return max(0, $total);
        }

        $limit = $date->isFriday() ? self::FRIDAY_MINUTES : self::MONDAY_TO_THURSDAY_MINUTES;

        return max(0, $total - $limit);
    }

    /**
     * Formata minutos como HH:MM; zero ou negativo vira "00:00", como nas telas atuais.
     */
    public static function format(int $minutes): string
    {
        if ($minutes <= 0) {
            return '00:00';
        }

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
