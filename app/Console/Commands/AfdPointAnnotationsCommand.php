<?php

namespace App\Console\Commands;

use App\Services\AfdPointAnnotations;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;

/**
 * Anotações do RH sobre pontos importados do AFD (AfdPointAnnotations): só registro, sem
 * alterar `points` nem os cálculos.
 * - `preview`: simulação somente leitura; valida o manifesto e devolve o hash de cada ponto.
 *   Código de saída 0 quando válido; 1 em recusa ou erro.
 * - `apply`: grava as anotações `disregard`, `duplicate` e `revoke`; exige o responsável, a
 *   identidade esperada do banco e, em cada linha, o `expected_point_hash` da simulação
 *   aprovada. Código de saída 0 em sucesso; 1 em recusa. Em produção, só grava com
 *   `--production` e com a aplicação em manutenção.
 */
#[Signature('afd:point-annotations
    {action : preview ou apply}
    {--manifest= : Manifesto JSON de anotações (kind: point_annotation)}
    {--afd= : Arquivo AFD do manifesto}
    {--operator= : Responsável pela operação (obrigatório em apply)}
    {--expect-host= : @@hostname esperado do banco (apply)}
    {--expect-server-uuid= : @@server_uuid (MySQL) ou @@server_uid (MariaDB) esperado do banco (apply)}
    {--expect-database= : Nome esperado do banco (apply)}
    {--production : Autoriza gravar no ambiente de produção (apply; exige a aplicação em manutenção)}')]
#[Description('Anotações do RH sobre pontos importados do AFD: simulação e gravação')]
class AfdPointAnnotationsCommand extends Command
{
    public function handle(AfdPointAnnotations $annotations): int
    {
        $manifest = (string) $this->option('manifest');
        $afd = (string) $this->option('afd');

        try {
            $report = match ($this->argument('action')) {
                'preview' => $annotations->preview($manifest, $afd),
                'apply' => $annotations->apply($manifest, $afd, [
                    'operator' => (string) $this->option('operator'),
                    'production' => (bool) $this->option('production'),
                    'expected' => [
                        'hostname' => $this->option('expect-host'),
                        'server_uuid' => $this->option('expect-server-uuid'),
                        'database' => $this->option('expect-database'),
                    ],
                ]),
                default => throw new DomainException('Ação desconhecida: use preview ou apply.'),
            };
        } catch (DomainException|LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return in_array($report['status'], [AfdPointAnnotations::STATUS_VALID, 'applied', 'already_applied'], true) ? self::SUCCESS : self::FAILURE;
    }
}
