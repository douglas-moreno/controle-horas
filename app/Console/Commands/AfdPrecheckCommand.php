<?php

namespace App\Console\Commands;

use App\Services\AfdPrecheck;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;

/**
 * Pré-validação somente leitura de um AFD (AfdPrecheck). Não importa nada: o resultado
 * serve para decidir se o arquivo pode seguir para uma importação supervisionada.
 * Código de saída 0 sem bloqueios; 1 com bloqueios ou quando o isolamento de leitura não
 * pode ser garantido.
 */
#[Signature('afd:precheck {path : Caminho do arquivo AFD} {--json : Exibe o relatório em JSON}')]
#[Description('Pré-valida um AFD contra o banco, somente leitura, sem importar')]
class AfdPrecheckCommand extends Command
{
    public function handle(AfdPrecheck $precheck): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Arquivo AFD não encontrado ou sem permissão de leitura.');

            return self::FAILURE;
        }

        try {
            $report = $precheck->run($path);
        } catch (DomainException|LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($report);
        }

        return $report['verdict'] === AfdPrecheck::VERDICT_READY ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report): void
    {
        $this->info('Pré-validação do AFD (somente leitura) — '.$report['checked_at']);
        $this->line('Arquivo: '.$report['file']['name'].' · sha256 '.$report['file']['sha256'].' · '.$report['file']['size'].' bytes');

        $this->newLine();
        $this->info('1. Estrutura do arquivo');
        $structure = $report['structure'];

        if ($structure['status'] !== 'ok') {
            $this->error($structure['message']);
        } else {
            $this->table(['Item', 'Valor'], [
                ['REP', $structure['rep_serial']],
                ['Período do cabeçalho', $structure['starts_on'].' a '.$structure['ends_on']],
                ['Gerado em', $structure['generated_at']],
                ['NSR', $structure['first_nsr'].' a '.$structure['max_nsr']],
                ['Linha do trailer', $structure['trailer_line']],
                ['Marcações (tipo 3)', $structure['counts']['punch']],
                ['Empresa / ajuste do relógio / empregado', $structure['counts']['company'].' / '.$structure['counts']['clock_adjustment'].' / '.$structure['counts']['employee']],
                ['Marcações com tamanho inválido', $structure['invalid_length_punches']],
            ]);
        }

        if ($report['start'] !== null) {
            $start = $report['start'];
            $this->info('2. Legado e cursor');
            $rows = [
                ['REP já registrado', $start['rep_registered'] ? 'sim (cursor '.($start['processed_to_nsr'] ?? 'nulo').')' : 'não'],
                ['Outros REPs com cursor', $start['other_reps_with_cursor']],
                ['imported_lines', $start['imported_lines'].' linha(s), maior line_number '.($start['legacy_max_line_number'] ?? '—')],
            ];

            if ($start['status'] === 'ok') {
                array_push($rows,
                    ['Transição', $start['transition'] ?? 'cursor existente'],
                    ['Cursor calculado', $start['cursor']],
                    ['Evidência legada conferida / confirmada', $start['legacy_checked'].' / '.$start['legacy_confirmed']],
                    ['Cursor após a importação', $start['cursor_after']],
                );
            }

            $this->table(['Item', 'Valor'], $rows);

            if ($start['status'] !== 'ok') {
                $this->error($start['message']);
            }
        }

        if ($report['coverage'] !== null) {
            $coverage = $report['coverage'];
            $this->info('2a. Cobertura das marcações até o cursor '.$coverage['cursor']);
            $this->table(['Categoria', 'Quantidade'], [
                ['Escopo: NSR presentes no arquivo', $coverage['scope']['first_nsr'].'–'.$coverage['scope']['last_nsr'].($coverage['scope']['partial'] ? ' (AFD PARCIAL: reconciliação incompleta)' : ' (completo até o cursor)')],
                ['Marcações até o cursor', $coverage['universe']],
                ['Importadas com REP e NSR (recuperadas)', $coverage['counts']['imported'].' ('.$coverage['counts']['recovered'].')'],
                ['Rejeitadas registradas', $coverage['counts']['rejected']],
                ['Comprovadas no legado (posição)', $coverage['counts']['legacy_verified']],
                ['Pendências históricas NÃO resolvidas', $coverage['counts']['pending'].($coverage['pending_by_classification'] === [] ? '' : ' ('.collect($coverage['pending_by_classification'])->map(fn ($count, $class) => $class.': '.$count)->implode(', ').')')],
                ['Decisão do RH: manual mantido', $coverage['counts']['manual_retained']],
                ['Decisão do RH: descarte aprovado', $coverage['counts']['discarded_by_approval']],
                ['Lacunas não explicadas (bloqueiam)', $coverage['counts']['gap']],
                ['Inconsistências (bloqueiam)', count($coverage['inconsistencies'])],
            ]);
        }

        if ($report['records'] !== null) {
            $records = $report['records'];
            $this->info('3–6. Marcações (contagens confirmadas no instante da execução)');
            $this->table(['Item', 'Quantidade'], [
                ['Marcações no arquivo', $records['punches']],
                ['Já processadas (NSR até o cursor)', $records['already_processed']],
                ['Candidatas (NSR após o cursor)', $records['candidates']],
                ['Novas', $records['new'].($records['new_nsr_range'] ? ' (NSR '.implode('–', $records['new_nsr_range']).', datas '.implode(' a ', $records['new_date_range']).')' : '')],
                ['Já existentes (mesmo REP e NSR)', $records['existing']],
                ['Inválidas / rejeitadas', $records['rejected'].($records['rejected_by_reason'] === [] ? '' : ' ('.collect($records['rejected_by_reason'])->map(fn ($count, $reason) => $reason.': '.$count)->implode(', ').')')],
                ['Rejeições já registradas', $records['rejections_already_recorded']],
                ['Coincidências com batidas manuais', $records['manual_coincidences']],
                ['Datas fora do período do cabeçalho', $records['dates_outside_file_period']],
                ['Datas futuras', $records['future_dates']],
            ]);
        }

        if ($report['employees'] !== null) {
            $employees = $report['employees'];
            $this->info('7. Funcionários');
            $this->table(['Item', 'Quantidade'], [
                ['PIS nas marcações novas', $employees['pis_in_new_punches']],
                ['Associados a um cadastro', $employees['associated']],
                ['Sem cadastro', count($employees['not_found'])],
                ['Com mais de um cadastro', $employees['multiple_records']],
                ['Rescindidos com marcação posterior à rescisão', $employees['punches_after_recision']],
            ]);

            if ($employees['not_found'] !== []) {
                $this->table(['PIS (mascarado)', 'Marcações', 'Primeira data', 'Última data'], array_map(fn (array $row) => array_values($row), $employees['not_found']));
            }
        }

        $this->info('8. Bloqueios');
        $this->listing($report['blocking'], 'Nenhum bloqueio.');

        $this->info('Alertas');
        $this->listing($report['warnings'], 'Nenhum alerta.');

        $this->info('9. Limitações (análise manual)');
        $this->listing($report['limitations'], '—');

        $this->newLine();
        $report['verdict'] === AfdPrecheck::VERDICT_READY
            ? $this->info('Resultado: sem bloqueios — o arquivo pode seguir para uma importação supervisionada.')
            : $this->error('Resultado: BLOQUEADO — o arquivo não deve ser importado.');
        $this->warn($report['notice']);
        $this->line('Isolamento: sessão MySQL somente leitura confirmada; comandos executados: '.collect($report['read_only']['statements'])->map(fn ($count, $verb) => $verb.' '.$count)->implode(', ').'.');
    }

    /**
     * @param  list<string>  $items
     */
    private function listing(array $items, string $empty): void
    {
        if ($items === []) {
            $this->line('  '.$empty);

            return;
        }

        foreach ($items as $item) {
            $this->line('  - '.$item);
        }
    }
}
