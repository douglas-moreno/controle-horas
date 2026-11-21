<?php

namespace App\Enums;

use Illuminate\Database\QueryException;

/**
 * Falhas de banco previsíveis em operações concorrentes, para mensagem amigável na tela.
 *
 * - Concurrency: espera de bloqueio esgotada (MySQL 1205) ou deadlock (1213). A transação
 *   foi desfeita; repetir a operação é seguro.
 * - Integrity: violação de integridade (SQLSTATE 23000), por exemplo chave estrangeira
 *   (1451/1452) ou índice único (1062) por gravação concorrente. A transação foi desfeita.
 *
 * Qualquer outra falha não é classificada (`fromException()` devolve null) e deve seguir
 * como erro inesperado.
 */
enum DatabaseFailure: string
{
    case Concurrency = 'concurrency';
    case Integrity = 'integrity';

    public static function fromException(QueryException $exception): ?self
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return match (true) {
            in_array($driverCode, [1205, 1213], true) => self::Concurrency,
            (string) ($exception->errorInfo[0] ?? $exception->getCode()) === '23000' => self::Integrity,
            default => null,
        };
    }
}
