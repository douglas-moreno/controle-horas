<?php

namespace App\Console\Commands;

use App\Services\AfdDecisionMatrix;
use App\Services\AfdHistoricalDecisions;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;

/**
 * Decisões do RH sobre pendências históricas do AFD (AfdHistoricalDecisions e
 * AfdDecisionMatrix).
 * - `convert`: converte a matriz do RH (CSV) em manifesto de decisões (`--output`), sem
 *   acessar o banco; só entram as linhas com decisão preenchida.
 * - `preview`: simulação somente leitura; valida o manifesto e mostra a consequência
 *   prevista de cada decisão. Com `--output`, grava a simulação (JSON, usada pelo
 *   `approve`); com `--report`, grava o relatório legível para o RH (Markdown). Código de
 *   saída 0 quando válido e com todos os avisos confirmados; 1 em recusa, aviso não
 *   confirmado ou erro.
 * - `approve`: gera o manifesto aprovado (`--output`) com o hash da consequência de cada
 *   linha copiado da simulação salva (`--preview`), que precisa ser válida e do mesmo
 *   manifesto. Não acessa o banco.
 * - `apply`: grava as decisões `recover`, `retain_manual`, `discard`, `defer` e `revoke`;
 *   exige o responsável, a identidade esperada do banco e, em cada linha, o
 *   `expected_consequence_sha256` da simulação aprovada. Código de saída 0 em sucesso; 1 em
 *   recusa.
 *
 * Os arquivos gerados nunca sobrescrevem um existente e ficam legíveis só pelo dono.
 */
#[Signature('afd:decisions
    {action : convert, preview, approve ou apply}
    {--matrix= : Matriz do RH em CSV (convert)}
    {--manifest= : Manifesto JSON de decisões (kind: decision)}
    {--afd= : Arquivo AFD do manifesto}
    {--preview= : Simulação salva por preview --output (approve)}
    {--output= : Arquivo a gerar: manifesto (convert, approve) ou simulação JSON (preview)}
    {--report= : Relatório da simulação em Markdown para o RH (preview)}
    {--timezone=America/Sao_Paulo : Fuso das datas da matriz sem fuso explícito (convert)}
    {--classes= : Só estas classes, separadas por vírgula (convert)}
    {--employees= : Só estes funcionários (id interno), separados por vírgula (convert)}
    {--operator= : Responsável pela operação (obrigatório em apply)}
    {--expect-host= : @@hostname esperado do banco (apply)}
    {--expect-server-uuid= : @@server_uuid esperado do banco (apply)}
    {--expect-database= : Nome esperado do banco (apply)}')]
#[Description('Decisões do RH sobre pendências históricas do AFD: conversão da matriz, simulação, aprovação e gravação')]
class AfdDecisionsCommand extends Command
{
    public function handle(AfdHistoricalDecisions $decisions, AfdDecisionMatrix $matrix): int
    {
        $manifest = (string) $this->option('manifest');
        $afd = (string) $this->option('afd');

        try {
            $report = match ($this->argument('action')) {
                'convert' => $this->convert($matrix, $afd),
                'preview' => $this->preview($decisions, $matrix, $manifest, $afd),
                'approve' => $this->approve($matrix, $manifest),
                'apply' => $decisions->apply($manifest, $afd, [
                    'operator' => (string) $this->option('operator'),
                    'expected' => [
                        'hostname' => $this->option('expect-host'),
                        'server_uuid' => $this->option('expect-server-uuid'),
                        'database' => $this->option('expect-database'),
                    ],
                ]),
                default => throw new DomainException('Ação desconhecida: use convert, preview, approve ou apply.'),
            };
        } catch (DomainException|LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return in_array($report['status'], [AfdHistoricalDecisions::STATUS_VALID, 'applied', 'already_applied', 'converted', 'approved'], true) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function convert(AfdDecisionMatrix $matrix, string $afd): array
    {
        $output = (string) $this->option('output');
        $this->ensureNew($output);

        $converted = $matrix->convert((string) $this->option('matrix'), $afd, [
            'timezone' => (string) $this->option('timezone'),
            'classes' => $this->list('classes'),
            'employees' => $this->list('employees'),
        ]);
        $sha = $matrix->writeNew($output, $this->json($converted['manifest']));

        return [
            'status' => 'converted',
            'output' => $output,
            'manifest_sha256' => $sha,
            'rows' => $converted['included'],
            'by_decision' => collect($converted['manifest']['rows'])->countBy('decision')->sortKeys()->all(),
            'skipped_without_decision' => count($converted['skipped_without_decision']),
            'filtered_out' => $converted['filtered_out'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function preview(AfdHistoricalDecisions $decisions, AfdDecisionMatrix $matrix, string $manifest, string $afd): array
    {
        $outputPath = (string) $this->option('output');
        $reportPath = (string) $this->option('report');

        foreach (array_filter([$outputPath, $reportPath]) as $path) {
            $this->ensureNew($path);
        }

        $preview = $decisions->preview($manifest, $afd);

        if ($outputPath !== '') {
            $matrix->writeNew($outputPath, $this->json($preview));
        }

        if ($reportPath !== '') {
            $matrix->writeNew($reportPath, $matrix->report((array) json_decode((string) file_get_contents($manifest), true), $preview));
        }

        return $preview;
    }

    /**
     * @return array<string, mixed>
     */
    private function approve(AfdDecisionMatrix $matrix, string $manifest): array
    {
        $output = (string) $this->option('output');
        $this->ensureNew($output);

        $approved = $matrix->approve($manifest, (string) $this->option('preview'));
        $sha = $matrix->writeNew($output, $this->json($approved));

        return ['status' => 'approved', 'output' => $output, 'manifest_sha256' => $sha, 'rows' => count($approved['rows']), 'approved_from' => $approved['approved_from']];
    }

    private function ensureNew(string $path): void
    {
        if ($path === '' || file_exists($path)) {
            throw new DomainException('Arquivo de saída não informado ou já existente ('.$path.'): nada foi sobrescrito.');
        }
    }

    /**
     * @return list<string>
     */
    private function list(string $option): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->option($option)))));
    }

    /**
     * @param  array<mixed>  $value
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    }
}
