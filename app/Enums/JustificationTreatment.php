<?php

namespace App\Enums;

/**
 * Efeito da justificativa sobre a jornada, independente do motivo.
 *
 * HourBank fica registrado no catálogo, mas não existe banco de horas no sistema:
 * enquanto `isSupported()` for falso, o tratamento não pode ser aplicado.
 */
enum JustificationTreatment: string
{
    case FullAllowance = 'full_allowance';
    case PartialAllowance = 'partial_allowance';
    case HourBank = 'hour_bank';
    case NoAllowance = 'no_allowance';

    public function label(): string
    {
        return match ($this) {
            self::FullAllowance => 'Abonar integralmente',
            self::PartialAllowance => 'Abonar parcialmente',
            self::HourBank => 'Compensar no banco de horas',
            self::NoAllowance => 'Não abonar',
        };
    }

    public function isSupported(): bool
    {
        return $this !== self::HourBank;
    }

    /**
     * Tratamento que exige a quantidade de minutos abonados informada pelo RH.
     */
    public function requiresAllowedMinutes(): bool
    {
        return $this === self::PartialAllowance;
    }
}
