<?php

namespace App\Enums;

enum BenefitPeriodStatus: string
{
    case Open = 'open';
    case Calculated = 'calculated';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberta',
            self::Calculated => 'Calculada',
            self::Closed => 'Fechada',
        };
    }

    /**
     * Competências calculadas continuam editáveis; a alteração invalida o cálculo.
     */
    public function isEditable(): bool
    {
        return $this !== self::Closed;
    }
}
