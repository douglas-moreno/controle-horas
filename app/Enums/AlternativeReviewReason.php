<?php

namespace App\Enums;

/**
 * Motivo específico de um dia útil enviado para conferência manual pela regra
 * alternativa (situação `outside_schedule`). Não tem caráter disciplinar: indica
 * marcações insuficientes ou incompatíveis com a regra alternativa.
 */
enum AlternativeReviewReason: string
{
    case NightShiftGuard = 'night_shift_guard';
    case PairOutsideWindows = 'pair_outside_windows';
    case LunchBoundary = 'lunch_boundary';
    case ShiftedLunch = 'shifted_lunch';
    case LunchIntervalPunch = 'lunch_interval_punch';

    public function label(): string
    {
        return match ($this) {
            self::NightShiftGuard => 'Possível turno noturno',
            self::PairOutsideWindows => 'Marcações fora das janelas previstas',
            self::LunchBoundary => 'Marcações nos limites do almoço',
            self::ShiftedLunch => 'Almoço fora do intervalo previsto',
            self::LunchIntervalPunch => 'Entrada ou saída dentro do intervalo de almoço',
        };
    }
}
