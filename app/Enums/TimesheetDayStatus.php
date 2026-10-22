<?php

namespace App\Enums;

/**
 * Situação de um dia no espelho de ponto. Define se o atraso pôde ser calculado
 * e se o dia precisa de conferência manual.
 */
enum TimesheetDayStatus: string
{
    case Regular = 'regular';
    case NoPunches = 'no_punches';
    case OddPunches = 'odd_punches';
    case ExcessPunches = 'excess_punches';
    case NoSchedule = 'no_schedule';
    case NonWorkingDay = 'non_working_day';
    case NonWorkingDayWorked = 'non_working_day_worked';

    public function label(): string
    {
        return match ($this) {
            self::Regular => '',
            self::NoPunches => 'Sem marcação',
            self::OddPunches => 'Marcações incompletas',
            self::ExcessPunches => 'Mais de 4 marcações',
            self::NoSchedule => 'Sem jornada definida',
            self::NonWorkingDay => '',
            self::NonWorkingDayWorked => 'Trabalho fora da jornada',
        };
    }

    /**
     * Dias cujo atraso não foi calculado por falta de marcações confiáveis ou de jornada.
     */
    public function needsReview(): bool
    {
        return ! in_array($this, [self::Regular, self::NonWorkingDay], true);
    }
}
