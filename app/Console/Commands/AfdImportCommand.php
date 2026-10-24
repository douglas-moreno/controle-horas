<?php

namespace App\Console\Commands;

use App\Services\AfdHistoricalCorrection;
use App\Services\AfdImporter;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Importação supervisionada de um AFD pela linha de comando (AfdImporter::import, a mesma
 * da tela de funcionários), para uso durante a janela de manutenção. Grava sempre: a
 * simulação é o afd:precheck. Exige o responsável e a identidade esperada do banco
 * (hostname, server_uuid e nome), conferidos antes de qualquer gravação; o responsável
 * fica em `afd_imports.operator`. Marcações retidas por decisão do RH não são gravadas
 * (`withheld`). Código de saída 0 em sucesso; 1 em recusa.
 */
#[Signature('afd:import
    {path : Caminho do arquivo AFD}
    {--operator= : Responsável pela importação (obrigatório)}
    {--expect-host= : @@hostname esperado do banco}
    {--expect-server-uuid= : @@server_uuid esperado do banco}
    {--expect-database= : Nome esperado do banco}')]
#[Description('Importação supervisionada de um AFD, com conferência do banco de destino')]
class AfdImportCommand extends Command
{
    public function handle(AfdImporter $importer, AfdHistoricalCorrection $correction): int
    {
        $operator = trim((string) $this->option('operator'));

        if ($operator === '') {
            $this->error('Informe o responsável pela importação (--operator). Nenhuma batida foi gravada.');

            return self::FAILURE;
        }

        $target = $correction->target();

        foreach (['hostname' => 'expect-host', 'server_uuid' => 'expect-server-uuid', 'database' => 'expect-database'] as $key => $option) {
            if (blank($this->option($option)) || $this->option($option) !== $target[$key]) {
                $this->error('Banco de destino diferente do esperado ('.$key.'). Nenhuma batida foi gravada.');

                return self::FAILURE;
            }
        }

        try {
            $result = $importer->import((string) $this->argument('path'), null, $operator);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line((string) json_encode(['target' => $target, 'operator' => $operator, ...$result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
