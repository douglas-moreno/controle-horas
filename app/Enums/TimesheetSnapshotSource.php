<?php

namespace App\Enums;

/**
 * Origem de um snapshot do espelho de ponto (F6.2).
 *
 * - ClosureBaseline: ponto de partida gravado na transação do fechamento da competência.
 * - LateBaseline: ponto de partida tardio, gravado na primeira necessidade de registro de
 *   uma competência fechada antes da implantação. Nunca é o resultado original do fechamento.
 * - BeforeChange / AfterChange: dia antes e depois de uma alteração autorizada.
 * - Recalculation: novo registro do dia por recálculo autorizado, fora de uma alteração.
 */
enum TimesheetSnapshotSource: string
{
    case ClosureBaseline = 'closure_baseline';
    case LateBaseline = 'late_baseline';
    case BeforeChange = 'before_change';
    case AfterChange = 'after_change';
    case Recalculation = 'recalculation';

    public function label(): string
    {
        return match ($this) {
            self::ClosureBaseline => 'Fechamento da competência (original)',
            self::LateBaseline => 'Ponto de partida tardio (não é o fechamento original)',
            self::BeforeChange => 'Antes de alteração autorizada',
            self::AfterChange => 'Depois de alteração autorizada',
            self::Recalculation => 'Recálculo autorizado',
        };
    }

    public function isBaseline(): bool
    {
        return in_array($this, [self::ClosureBaseline, self::LateBaseline], true);
    }
}
