<?php

namespace App\Console\Commands;

use App\Services\AfdHistoricalCorrection;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;

/**
 * Correção histórica do importador AFD por lotes (AfdHistoricalCorrection). Sem
 * `--apply`, só simula, em sessão somente leitura. Com `--apply`, exige o responsável
 * e a identidade esperada do banco (hostname, server_uuid e nome), conferidas antes de
 * qualquer gravação. Código de saída 0 em sucesso; 1 em recusa ou divergência.
 */
#[Signature('afd:correction
    {action : register, quarantine, recover, withhold, restore ou revert}
    {--manifest= : Manifesto JSON (register, quarantine, recover, withhold)}
    {--afd= : Arquivo AFD do manifesto (register, quarantine, recover, withhold)}
    {--batch= : Lote a desfazer (restore, revert)}
    {--apply : Grava; sem esta opção, apenas simula}
    {--operator= : Responsável pela operação (obrigatório com --apply)}
    {--expect-host= : @@hostname esperado do banco}
    {--expect-server-uuid= : @@server_uuid esperado do banco}
    {--expect-database= : Nome esperado do banco}')]
#[Description('Correção histórica do AFD: pendências, quarentena, recuperação, retenção por decisão do RH e reversões')]
class AfdCorrectionCommand extends Command
{
    public function handle(AfdHistoricalCorrection $correction): int
    {
        $apply = $this->option('apply') ? [
            'operator' => (string) $this->option('operator'),
            'expected' => [
                'hostname' => $this->option('expect-host'),
                'server_uuid' => $this->option('expect-server-uuid'),
                'database' => $this->option('expect-database'),
            ],
        ] : null;

        try {
            $result = match ($this->argument('action')) {
                'register' => $correction->register((string) $this->option('manifest'), (string) $this->option('afd'), $apply),
                'quarantine' => $correction->quarantine((string) $this->option('manifest'), (string) $this->option('afd'), $apply),
                'recover' => $correction->recover((string) $this->option('manifest'), (string) $this->option('afd'), $apply),
                'withhold' => $correction->withhold((string) $this->option('manifest'), (string) $this->option('afd'), $apply),
                'restore' => $correction->restore((int) $this->option('batch'), $apply),
                'revert' => $correction->revert((int) $this->option('batch'), $apply),
                default => throw new DomainException('Ação desconhecida: use register, quarantine, recover, withhold, restore ou revert.'),
            };
        } catch (DomainException|LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
