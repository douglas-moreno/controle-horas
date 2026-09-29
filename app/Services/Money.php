<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Conversão exata entre valores decimais (como o cast `decimal:2` os entrega) e
 * centavos inteiros, sem passar por float.
 */
final class Money
{
    /**
     * Limite da parte inteira: mantém o resultado em centavos dentro de um inteiro de 64 bits.
     */
    private const MAX_INTEGER_DIGITS = 15;

    /**
     * Converte "5.40" em 540. Inteiros são tratados como valor em reais (5 → 500).
     *
     * @throws InvalidArgumentException quando o formato é inválido ou há mais de duas casas decimais
     */
    public static function toCents(string|int $amount): int
    {
        if (is_int($amount)) {
            return $amount * 100;
        }

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $amount, $matches) !== 1) {
            throw new InvalidArgumentException("Valor monetário inválido: \"{$amount}\".");
        }

        [, $sign, $integerPart, $fractionPart] = $matches + [3 => ''];

        if (strlen($fractionPart) > 2) {
            throw new InvalidArgumentException("Valor monetário com mais de duas casas decimais: \"{$amount}\".");
        }

        if (strlen(ltrim($integerPart, '0')) > self::MAX_INTEGER_DIGITS) {
            throw new InvalidArgumentException("Valor monetário fora do limite suportado: \"{$amount}\".");
        }

        $cents = (int) $integerPart * 100 + (int) str_pad($fractionPart, 2, '0');

        return $sign === '-' ? -$cents : $cents;
    }

    /**
     * Converte 540 em "5.40".
     */
    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absoluteCents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($absoluteCents, 100), $absoluteCents % 100);
    }
}
