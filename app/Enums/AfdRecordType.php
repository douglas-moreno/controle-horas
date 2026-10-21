<?php

namespace App\Enums;

/**
 * Tipos de registro do AFD (Portaria MTE 1510/2009, Anexo I, item 1). O valor é o
 * caractere da posição 010; o trailer não tem tipo nessa posição e é identificado
 * por "999999999" nas posições 001-009.
 */
enum AfdRecordType: string
{
    case Header = '1';
    case Company = '2';
    case Punch = '3';
    case ClockAdjustment = '4';
    case Employee = '5';
    case Trailer = '9';

    /**
     * Tamanho exato do registro, sem o terminador de linha.
     */
    public function length(): int
    {
        return match ($this) {
            self::Header => 232,
            self::Company => 299,
            self::Punch => 34,
            self::ClockAdjustment => 34,
            self::Employee => 87,
            self::Trailer => 46,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Header => 'Cabeçalho',
            self::Company => 'Empresa',
            self::Punch => 'Marcação',
            self::ClockAdjustment => 'Ajuste do relógio',
            self::Employee => 'Empregado',
            self::Trailer => 'Trailer',
        };
    }
}
