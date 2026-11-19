<?php

namespace App\Enums;

/**
 * Permissões atribuídas individualmente aos usuários (`users.permissions`).
 *
 * - Hr: registrar, consultar, editar e cancelar justificativas do controle de ponto.
 * - HrClosedPeriod: alterar justificativas que atingem competência fechada. Só vale
 *   junto com Hr.
 */
enum Permission: string
{
    case Hr = 'hr';
    case HrClosedPeriod = 'hr.closed_period';

    public function label(): string
    {
        return match ($this) {
            self::Hr => 'RH — justificativas do controle de ponto',
            self::HrClosedPeriod => 'RH — alterações em competência fechada',
        };
    }
}
