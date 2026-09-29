<?php

namespace App\Enums;

enum AdjustmentSource: string
{
    case Manual = 'manual';
    case Timesheet = 'timesheet';
    case Hr = 'hr';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Timesheet => 'Ponto',
            self::Hr => 'RH',
            self::Import => 'Importação',
        };
    }

    /**
     * Lançamentos do administrador e do RH já nascem conferidos; sugestões do ponto
     * e importações precisam de revisão antes de afetar a apuração.
     */
    public function initialStatus(): AdjustmentStatus
    {
        return match ($this) {
            self::Manual, self::Hr => AdjustmentStatus::Confirmed,
            self::Timesheet, self::Import => AdjustmentStatus::Pending,
        };
    }
}
