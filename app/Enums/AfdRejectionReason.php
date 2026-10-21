<?php

namespace App\Enums;

/**
 * Motivo da rejeição individual de uma marcação (registro tipo 3) do AFD. Somente o
 * motivo e o NSR são registrados: nunca o PIS nem o conteúdo da linha.
 */
enum AfdRejectionReason: string
{
    case InvalidLength = 'invalid_length';
    case InvalidDate = 'invalid_date';
    case InvalidTime = 'invalid_time';
    case InvalidPis = 'invalid_pis';

    public function label(): string
    {
        return match ($this) {
            self::InvalidLength => 'Tamanho do registro inválido',
            self::InvalidDate => 'Data inválida',
            self::InvalidTime => 'Horário inválido',
            self::InvalidPis => 'PIS inválido',
        };
    }
}
