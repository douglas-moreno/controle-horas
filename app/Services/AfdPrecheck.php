<?php

namespace App\Services;

use App\Enums\AfdRecordType;
use App\Models\AfdRep;
use App\Models\Employee;
use App\Models\ImportedLines;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Pré-validação somente leitura de um AFD contra o estado atual do banco, separada da
 * importação: nunca chama AfdImporter::import(), nem cria o REP, nem trava linhas.
 *
 * Reaproveita as regras do importador: scan() (validação estrutural do arquivo),
 * startFor() (cursor do REP ou transição do controle legado, com a conferência dos
 * registros anteriores ao cursor e a recusa de registros posteriores já presentes),
 * storedNsrs() e recordedRejectionNsrs() (duplicidade por REP e NSR) e
 * manualCoincidences() (G1), e a cobertura individual dos NSRs até o cursor
 * (AfdNsrCoverage), a mesma que a importação exige. Acrescenta só diagnósticos de
 * leitura: associação do PIS ao cadastro de funcionários e datas fora do período do
 * arquivo. Lacunas não explicadas bloqueiam; pendências históricas conhecidas aparecem
 * como alerta, sem serem consideradas resolvidas. Marcações retidas por decisão do RH
 * (AfdImporter::withheldAhead) não contam como novas: aparecem em `records.withheld`.
 *
 * Isolamento de leitura em duas camadas, ativas durante toda a análise:
 * 1. a sessão MySQL é colocada em `SET SESSION TRANSACTION READ ONLY` e o valor de
 *    `@@session.transaction_read_only` é conferido antes da análise; o modo anterior é
 *    restaurado ao final, com ou sem erro;
 * 2. um bloqueio na conexão interrompe, antes da execução, qualquer comando que não seja
 *    SELECT ou a troca do modo da sessão, e qualquer início de transação.
 * Fora de uma transação aberta, a camada 1 se aplica a cada comando; por isso a
 * pré-validação recusa execução dentro de uma transação.
 *
 * As contagens são confirmadas no instante da execução. A importação recalcula tudo sob
 * a trava do REP e pode divergir se o banco mudar depois. O relatório não traz PIS
 * completo: funcionários não encontrados aparecem com o PIS mascarado.
 */
class AfdPrecheck
{
    public const NOT_AN_AUTHORIZATION = 'Pré-validação sem bloqueios não autoriza a importação: a importação real exige autorização explícita e deve ser supervisionada.';

    public const VERDICT_READY = 'ready_for_supervised_import';

    public const VERDICT_BLOCKED = 'blocked';

    private const BATCH_SIZE = 1000;

    private const SESSION_MODE = '/^\s*set\s+session\s+transaction\s+read\s+(only|write)\s*$/i';

    /**
     * @var array<string, int>
     */
    private array $statements = [];

    public function __construct(private AfdImporter $importer, private AfdParser $parser, private AfdNsrCoverage $coverage) {}

    /**
     * @return array<string, mixed>
     *
     * @throws DomainException quando o isolamento de leitura não pode ser garantido.
     */
    public function run(string $path): array
    {
        $report = $this->whileReadOnly(fn () => $this->analyse($path));
        $report['read_only'] = [
            'mysql_session_read_only_verified' => true,
            'write_guard' => true,
            'statements' => $this->statements,
        ];

        return $report;
    }

    /**
     * Executa o callback com a sessão MySQL em modo somente leitura e com o bloqueio de
     * escrita da conexão ativo.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws DomainException
     */
    public function whileReadOnly(Closure $callback): mixed
    {
        /** @var Connection $connection */
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            throw new DomainException('A pré-validação exige conexão MySQL, para garantir a sessão somente leitura. Nada foi executado.');
        }

        if ($connection->transactionLevel() > 0) {
            throw new DomainException('A pré-validação precisa ser executada fora de uma transação, onde a sessão somente leitura se aplica a cada comando. Nada foi executado.');
        }

        $this->statements = [];
        $guarded = true;

        $connection->beforeExecuting(function (string $query) use (&$guarded) {
            if (! $guarded) {
                return;
            }

            $verb = strtolower((string) strtok(ltrim($query, " \t\r\n("), " \t\r\n("));
            $this->statements[$verb] = ($this->statements[$verb] ?? 0) + 1;

            if ($verb !== 'select' && preg_match(self::SESSION_MODE, $query) !== 1) {
                throw new LogicException('Pré-validação interrompida: comando "'.$verb.'" bloqueado. A pré-validação só executa leituras; nenhuma alteração foi feita.');
            }
        });
        $connection->beforeStartingTransaction(function () use (&$guarded) {
            if ($guarded) {
                throw new LogicException('Pré-validação interrompida: abertura de transação bloqueada. Nenhuma alteração foi feita.');
            }
        });

        $previouslyReadOnly = $this->sessionReadOnly($connection);

        try {
            $connection->statement('SET SESSION TRANSACTION READ ONLY');

            if (! $this->sessionReadOnly($connection)) {
                throw new DomainException('Não foi possível confirmar a sessão somente leitura do MySQL. Nada foi analisado.');
            }

            return $callback();
        } finally {
            $connection->statement($previouslyReadOnly ? 'SET SESSION TRANSACTION READ ONLY' : 'SET SESSION TRANSACTION READ WRITE');
            $guarded = false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function analyse(string $path): array
    {
        $report = [
            'checked_at' => now()->toIso8601String(),
            'file' => ['name' => basename($path), 'sha256' => is_file($path) ? hash_file('sha256', $path) : null, 'size' => is_file($path) ? filesize($path) : null],
            'structure' => null,
            'start' => null,
            'coverage' => null,
            'records' => null,
            'employees' => null,
            'blocking' => [],
            'warnings' => [],
            'limitations' => $this->limitations(),
        ];

        try {
            $scan = $this->importer->scan($path);
        } catch (DomainException $exception) {
            $report['structure'] = ['status' => 'refused', 'message' => $exception->getMessage()];
            $report['blocking'][] = 'Estrutura: '.$exception->getMessage();

            return $this->conclude($report);
        }

        $report['structure'] = [
            'status' => 'ok',
            'rep_serial' => $scan['rep_serial'],
            'starts_on' => $scan['starts_on']->toDateString(),
            'ends_on' => $scan['ends_on']->toDateString(),
            'generated_at' => $scan['generated_at']->format('Y-m-d H:i'),
            'first_nsr' => $scan['first_nsr'],
            'max_nsr' => $scan['max_nsr'],
            'max_valid_punch_nsr' => $scan['max_valid_punch_nsr'],
            'trailer_line' => $scan['trailer_line'],
            'counts' => $scan['counts'],
            'invalid_length_punches' => $scan['invalid_length_punches'],
        ];

        $rep = AfdRep::query()->where('rep_serial', $scan['rep_serial'])->first();
        $report['start'] = [
            'rep_registered' => $rep !== null,
            'processed_to_nsr' => $rep?->processed_to_nsr,
            'other_reps_with_cursor' => AfdRep::query()->whereNotNull('processed_to_nsr')->where('rep_serial', '!=', $scan['rep_serial'])->count(),
            'imported_lines' => ImportedLines::query()->count(),
            'legacy_max_line_number' => ($max = ImportedLines::query()->max('line_number')) === null ? null : (int) $max,
        ];

        try {
            $start = $this->importer->startFor($rep, $path, $scan);
        } catch (DomainException $exception) {
            $report['start'] += ['status' => 'refused', 'message' => $exception->getMessage()];
            $report['blocking'][] = 'Ponto de partida: '.$exception->getMessage();

            return $this->conclude($report);
        }

        $report['start'] += [
            'status' => 'ok',
            ...$start,
            'cursor_after' => max($start['cursor'], $scan['max_nsr'] ?? 0),
        ];

        $report['coverage'] = $this->coverage->evaluate($path, $scan['rep_serial'], $rep?->id, $start['cursor']);
        $report['blocking'] = [...$report['blocking'], ...$this->coverageBlocking($report['coverage'])];

        [$report['records'], $pisSummary] = $this->classify($path, $scan, $rep, $start['cursor']);
        $report['employees'] = $this->associate($pisSummary);

        return $this->conclude($this->warn($report));
    }

    /**
     * Classifica as marcações como o importador faria a partir do cursor.
     *
     * @param  array<string, mixed>  $scan
     * @return array{0: array<string, mixed>, 1: array<string, array{punches: int, first: string, last: string}>}
     */
    private function classify(string $path, array $scan, ?AfdRep $rep, int $cursor): array
    {
        $records = [
            'punches' => 0,
            'already_processed' => 0,
            'candidates' => 0,
            'new' => 0,
            'existing' => 0,
            'rejected' => 0,
            'rejected_by_reason' => [],
            'withheld' => 0,
            'withheld_nsrs' => [],
            'rejections_already_recorded' => 0,
            'manual_coincidences' => 0,
            'new_nsr_range' => null,
            'new_date_range' => null,
            'dates_outside_file_period' => 0,
            'future_dates' => 0,
        ];
        $pisSummary = [];
        $manualIds = [];
        $batch = [];
        $rejectedNsrs = [];
        $withheld = $this->importer->withheldAhead($scan['rep_serial'], $cursor);
        $today = CarbonImmutable::today()->toDateString();
        $startsOn = $scan['starts_on']->toDateString();
        $endsOn = $scan['ends_on']->toDateString();

        $flush = function () use (&$batch, &$records, &$pisSummary, &$manualIds, $scan, $today, $startsOn, $endsOn) {
            if ($batch === []) {
                return;
            }

            $stored = $this->importer->storedNsrs($scan['rep_serial'], array_column($batch, 'nsr'));
            $new = array_values(array_filter($batch, fn (array $row) => ! $stored->has($row['nsr'])));
            $records['existing'] += count($batch) - count($new);
            $records['new'] += count($new);

            foreach ($this->importer->manualCoincidences($new) as $id) {
                $manualIds[$id] = true;
            }

            foreach ($new as $row) {
                $records['new_nsr_range'] = [min($records['new_nsr_range'][0] ?? $row['nsr'], $row['nsr']), max($records['new_nsr_range'][1] ?? $row['nsr'], $row['nsr'])];
                $records['new_date_range'] = [min($records['new_date_range'][0] ?? $row['date'], $row['date']), max($records['new_date_range'][1] ?? $row['date'], $row['date'])];
                $records['dates_outside_file_period'] += (int) ($row['date'] < $startsOn || $row['date'] > $endsOn);
                $records['future_dates'] += (int) ($row['date'] > $today);

                $pis = (string) $row['pis'];
                $pisSummary[$pis] ??= ['punches' => 0, 'first' => $row['date'], 'last' => $row['date']];
                $pisSummary[$pis]['punches']++;
                $pisSummary[$pis]['first'] = min($pisSummary[$pis]['first'], $row['date']);
                $pisSummary[$pis]['last'] = max($pisSummary[$pis]['last'], $row['date']);
            }

            $batch = [];
        };

        foreach ($this->lines($path) as $lineNumber => $line) {
            if ($this->parser->withoutTerminator($line) === '') {
                continue;
            }

            $record = $this->parser->parse($line, $lineNumber);

            if ($record->type !== AfdRecordType::Punch) {
                continue;
            }

            $records['punches']++;

            if ($record->nsr <= $cursor) {
                $records['already_processed']++;

                continue;
            }

            $records['candidates']++;

            if ($withheld->has($record->nsr)) {
                $records['withheld']++;
                $records['withheld_nsrs'][] = $record->nsr;

                continue;
            }

            if ($record->rejection !== null) {
                $records['rejected']++;
                $records['rejected_by_reason'][$record->rejection->value] = ($records['rejected_by_reason'][$record->rejection->value] ?? 0) + 1;
                $rejectedNsrs[] = $record->nsr;

                continue;
            }

            $batch[] = ['nsr' => $record->nsr, 'pis' => (int) $record->pis, 'date' => $record->date->toDateString(), 'time' => $record->time];

            if (count($batch) >= self::BATCH_SIZE) {
                $flush();
            }
        }

        $flush();

        if ($rep !== null) {
            foreach (array_chunk($rejectedNsrs, self::BATCH_SIZE) as $chunk) {
                $records['rejections_already_recorded'] += $this->importer->recordedRejectionNsrs($rep->id, $chunk)->count();
            }
        }

        $records['manual_coincidences'] = count($manualIds);

        return [$records, $pisSummary];
    }

    /**
     * Associação dos PIS das marcações novas ao cadastro, pelo PIS sem zeros à esquerda,
     * como a relação Employee::points (PIS numérico em `points`).
     *
     * @param  array<string, array{punches: int, first: string, last: string}>  $pisSummary
     * @return array<string, mixed>
     */
    private function associate(array $pisSummary): array
    {
        $employees = Employee::query()->toBase()->get(['pis', 'recision_date'])
            ->groupBy(fn (object $employee) => ltrim(preg_replace('/\D/', '', (string) $employee->pis), '0'));

        $result = ['pis_in_new_punches' => count($pisSummary), 'associated' => 0, 'not_found' => [], 'multiple_records' => 0, 'punches_after_recision' => 0];

        foreach ($pisSummary as $pis => $summary) {
            $matches = $employees->get(ltrim($pis, '0'));

            if ($matches === null) {
                $result['not_found'][] = ['pis' => $this->mask($pis), 'punches' => $summary['punches'], 'first_date' => $summary['first'], 'last_date' => $summary['last']];

                continue;
            }

            $result['associated']++;
            $result['multiple_records'] += (int) ($matches->count() > 1);

            $recisions = $matches->pluck('recision_date');

            if ($recisions->every(fn ($date) => $date !== null) && $summary['last'] > substr((string) $recisions->max(), 0, 10)) {
                $result['punches_after_recision']++;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function warn(array $report): array
    {
        $records = $report['records'];
        $employees = $report['employees'];

        $warnings = array_filter([
            $records['existing'] > 0 ? $records['existing'].' marcação(ões) depois do cursor já gravada(s) com o mesmo REP e NSR: a importação as contará como existentes.' : null,
            $records['rejected'] > 0 ? $records['rejected'].' marcação(ões) inválida(s) seriam registradas como rejeitadas.' : null,
            $records['withheld'] > 0 ? $records['withheld'].' marcação(ões) retida(s) por decisão do RH (NSR '.implode(', ', array_slice($records['withheld_nsrs'], 0, 10)).'): não seriam gravadas; o cursor avança sobre ela(s).' : null,
            $records['manual_coincidences'] > 0 ? $records['manual_coincidences'].' batida(s) manual(is) com o mesmo PIS, data e minuto de uma marcação nova: verificar duplicidade no espelho.' : null,
            $records['dates_outside_file_period'] > 0 ? $records['dates_outside_file_period'].' marcação(ões) nova(s) com data fora do período do cabeçalho.' : null,
            $records['future_dates'] > 0 ? $records['future_dates'].' marcação(ões) nova(s) com data futura.' : null,
            $employees['not_found'] !== [] ? count($employees['not_found']).' PIS sem funcionário cadastrado: as marcações seriam gravadas, mas não aparecem em nenhum espelho.' : null,
            $employees['multiple_records'] > 0 ? $employees['multiple_records'].' PIS com mais de um cadastro de funcionário.' : null,
            $employees['punches_after_recision'] > 0 ? $employees['punches_after_recision'].' PIS de funcionário rescindido com marcação nova posterior à rescisão.' : null,
            $records['new'] === 0 ? 'Nenhuma marcação nova: a importação não gravaria batidas.' : null,
            $report['coverage']['scope']['partial'] ? 'AFD parcial: a cobertura de NSR se limita aos registros presentes no arquivo (NSR '.$report['coverage']['scope']['first_nsr'].' a '.$report['coverage']['scope']['last_nsr'].'); os NSR '.collect($report['coverage']['scope']['unchecked'])->map(fn (array $range) => $range[0] === $range[1] ? (string) $range[0] : $range[0].' a '.$range[1])->implode(' e ').' até o cursor não foram conferidos, e a reconciliação histórica NÃO está completa.' : null,
            $report['coverage']['counts']['withheld'] > 0 ? $report['coverage']['counts']['withheld'].' marcação(ões) retida(s) por decisão do RH até o cursor (NSR '.implode(', ', array_slice($report['coverage']['nsrs']['withheld'], 0, 10)).'), NÃO importada(s): aguardam decisão final do RH.' : null,
            $report['coverage']['counts']['pending'] > 0 ? $report['coverage']['counts']['pending'].' pendência(s) histórica(s) conhecida(s) até o cursor, NÃO resolvida(s) ('.collect($report['coverage']['pending_by_classification'])->map(fn ($count, $class) => $class.': '.$count)->implode(', ').'): dependem de recuperação ou de decisão do RH.' : null,
        ]);

        $report['warnings'] = array_values($warnings);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $coverage
     * @return list<string>
     */
    private function coverageBlocking(array $coverage): array
    {
        $blocking = $coverage['inconsistencies'] === [] ? [] : ['Cobertura de NSR: '.count($coverage['inconsistencies']).' inconsistência(s): '.implode(' ', array_slice($coverage['inconsistencies'], 0, 5))];

        if ($coverage['counts']['gap'] > 0) {
            $blocking[] = 'Cobertura de NSR: '.$coverage['counts']['gap'].' marcação(ões) até o cursor '.$coverage['cursor'].' sem nenhuma cobertura (lacuna não explicada; primeiros NSR: '.implode(', ', array_slice($coverage['nsrs']['gap'], 0, 10)).').';
        }

        return $blocking;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function conclude(array $report): array
    {
        $report['verdict'] = $report['blocking'] === [] ? self::VERDICT_READY : self::VERDICT_BLOCKED;
        $report['notice'] = self::NOT_AN_AUTHORIZATION;

        return $report;
    }

    /**
     * @return list<string>
     */
    private function limitations(): array
    {
        return [
            'As consultas não usam um snapshot transacional único: o relatório vale para o instante da execução, e a importação recalcula tudo sob a trava do REP.',
            'A transição do legado confere a janela de 50 registros anteriores ao cursor; os registros mais antigos são conferidos um a um pela prova posicional das execuções de imported_lines (cobertura de NSR).',
            'A prova posicional depende da ordem dos ids e do created_at dos pontos legados; uma execução com quantidade de batidas incompatível não comprova nenhuma das suas linhas.',
            'A associação ao funcionário usa somente o PIS sem zeros à esquerda; nome e função não são conferidos.',
            'A coincidência com batidas manuais usa PIS, data e minuto; não indica qual das batidas é a correta.',
            'Registros que não são marcações (tipos 2, 4 e 5) não são importados nem avaliados.',
            'A cobertura de NSR só confere os registros presentes no arquivo: um AFD parcial (sem o início) não comprova os NSR anteriores ao primeiro registro do arquivo; o intervalo não conferido aparece em coverage.scope e nos alertas.',
        ];
    }

    private function mask(string $pis): string
    {
        return str_repeat('*', max(0, strlen($pis) - 3)).substr($pis, -3);
    }

    private function sessionReadOnly(Connection $connection): bool
    {
        return (int) $connection->selectOne('select @@session.transaction_read_only as read_only')->read_only === 1;
    }

    /**
     * Linhas do arquivo, numeradas a partir de 1, com o terminador.
     *
     * @return Generator<int, string>
     */
    private function lines(string $path): Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new DomainException('Não foi possível ler o arquivo AFD.');
        }

        try {
            $lineNumber = 0;

            while (($line = fgets($handle)) !== false) {
                yield ++$lineNumber => $line;
            }
        } finally {
            fclose($handle);
        }
    }
}
