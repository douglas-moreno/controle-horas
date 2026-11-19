<?php

namespace App\Enums;

/**
 * Campos de horário exigidos pelo motivo no formulário.
 *
 * - FullDay: dia inteiro, sem horários.
 * - Interval: horário inicial e final.
 * - FullDayOrInterval: dia inteiro ou um intervalo, à escolha do RH.
 * - SinglePunch: um horário e o tipo de marcação (entrada, intervalo ou saída).
 */
enum JustificationTimeMode: string
{
    case FullDay = 'full_day';
    case Interval = 'interval';
    case FullDayOrInterval = 'full_day_or_interval';
    case SinglePunch = 'single_punch';

    public function label(): string
    {
        return match ($this) {
            self::FullDay => 'Dia inteiro',
            self::Interval => 'Intervalo de horário',
            self::FullDayOrInterval => 'Dia inteiro ou intervalo',
            self::SinglePunch => 'Horário de marcação',
        };
    }
}
