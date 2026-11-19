<?php

namespace App\Enums;

/**
 * Tipo da marcação informada na justificativa. A tabela `points` não guarda o tipo:
 * o espelho deduz o papel de cada batida pela ordem dos horários. O tipo serve para
 * validar o horário informado contra as batidas vizinhas do dia.
 */
enum PunchKind: string
{
    case Entry = 'entry';
    case BreakStart = 'break_start';
    case BreakEnd = 'break_end';
    case Exit = 'exit';

    public function label(): string
    {
        return match ($this) {
            self::Entry => 'Entrada',
            self::BreakStart => 'Início do intervalo',
            self::BreakEnd => 'Fim do intervalo',
            self::Exit => 'Saída',
        };
    }
}
