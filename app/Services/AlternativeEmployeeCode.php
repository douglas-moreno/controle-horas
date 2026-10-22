<?php

namespace App\Services;

use RuntimeException;

/**
 * Código pseudonimizado do funcionário na comparação da regra alternativa (A8):
 * HMAC-SHA256 do ID com a chave da aplicação, estável entre execuções enquanto
 * `app.key` não mudar. Não contém nome, PIS nem o ID em claro.
 */
class AlternativeEmployeeCode
{
    public const PREFIX = 'F';

    public const LENGTH = 10;

    public function for(int $employeeId): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('A chave da aplicação (APP_KEY) é necessária para gerar o código do funcionário.');
        }

        $hash = hash_hmac('sha256', 'alternative-comparison:employee:'.$employeeId, $key);

        return self::PREFIX.strtoupper(substr($hash, 0, self::LENGTH));
    }
}
