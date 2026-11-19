<?php

namespace App\Enums;

enum JustificationCategory: string
{
    case Absence = 'absence';
    case DelayAndLeave = 'delay_and_leave';
    case Punch = 'punch';
    case WorkdayAndOvertime = 'workday_and_overtime';

    public function label(): string
    {
        return match ($this) {
            self::Absence => 'Ausências',
            self::DelayAndLeave => 'Atrasos e saídas',
            self::Punch => 'Marcações de ponto',
            self::WorkdayAndOvertime => 'Jornada e horas extras',
        };
    }
}
