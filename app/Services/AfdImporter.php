<?php

namespace App\Services;

use App\Enums\AfdRecordType;
use App\Enums\AfdRejectionReason;
use App\Models\AfdImport;
use App\Models\AfdImportRejection;
use App\Models\AfdRep;
use App\Models\ImportedLines;
use App\Models\Point;
use Carbon\CarbonImmutable;
use DomainException;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Importa um AFD (layout do AfdParser) para `points`, identificando cada marcação pelo
 * REP (número de fabricação do cabeçalho) e pelo NSR: UNIQUE(rep_serial, nsr).
 *
 * 1. Validação integral do arquivo, sem acesso ao banco: cabeçalho único na primeira
 *    linha, NSR contínuo (+1 a cada registro), trailer na última linha com contadores
 *    iguais aos registros, nenhuma linha depois do trailer. Qualquer falha recusa o
 *    arquivo inteiro.
 * 2. Numa única transação, com a linha do REP em `afd_reps` bloqueada (lockForUpdate):
 *    cursor (maior NSR já examinado) ou transição do controle legado `imported_lines`;
 *    gravação somente das marcações (tipo 3) válidas com NSR acima do cursor;
 *    marcações inválidas em `afd_import_rejections` (NSR e motivo); registro da
 *    importação e avanço do cursor. Qualquer falha desfaz tudo.
 *
 * Os tipos 1, 2, 4, 5 e o trailer são contados e nunca geram batida.
 */
class AfdImporter
{
    private const BATCH_SIZE = 1000;

    /**
     * Quantidade de registros anteriores ao cursor legado conferidos no banco.
     */
    private const LEGACY_EVIDENCE_WINDOW = 50;

    /**
     * O importador legado pulava as 9 primeiras linhas quando `imported_lines` estava vazia.
     */
    private const LEGACY_INITIAL_SKIP = 9;

    public function __construct(private AfdParser $parser) {}

    /**
     * @return array{
     *     import_id: int, transition: ?string, legacy_line_number: ?int, cursor_before: int, cursor_after: int,
     *     max_nsr: ?int, max_valid_punch_nsr: ?int, max_imported_nsr: ?int,
     *     imported: int, existing: int, already_processed: int, rejected: int,
     *     rejections: list<array{nsr: int, reason: string}>,
     *     ignored: array{header: int, company: int, clock_adjustment: int, employee: int, trailer: int}
     * }
     *
     * @throws DomainException
     */
    public function import(string $path, ?int $userId = null): array
    {
        $scan = $this->scan($path);

        return DB::transaction(fn () => $this->persist($path, $scan, $userId));
    }

    /**
     * Validação estrutural integral. Não grava nada.
     *
     * @return array{
     *     rep_serial: string, starts_on: CarbonImmutable, ends_on: CarbonImmutable, generated_at: CarbonImmutable,
     *     first_nsr: ?int, max_nsr: ?int, max_valid_punch_nsr: ?int, trailer_line: int,
     *     counts: array{company: int, punch: int, clock_adjustment: int, employee: int}, invalid_length_punches: int,
     *     file_hash: string, file_size: int
     * }
     *
     * @throws DomainException
     */
    public function scan(string $path): array
    {
        $header = null;
        $trailerLine = null;
        $blankLine = null;
        $previousNsr = null;
        $firstNsr = null;
        $maxValidPunchNsr = null;
        $invalidLengthPunches = 0;
        $counts = ['company' => 0, 'punch' => 0, 'clock_adjustment' => 0, 'employee' => 0];
        $trailerCounts = null;

        foreach ($this->lines($path) as $lineNumber => $line) {
            if ($this->parser->withoutTerminator($line) === '') {
                $blankLine ??= $lineNumber;

                continue;
            }

            if ($blankLine !== null) {
                throw $this->refused('linha em branco no meio do arquivo (linha '.$blankLine.')');
            }

            if ($trailerLine !== null) {
                throw $this->refused('há registros depois do trailer (linha '.$lineNumber.')');
            }

            $record = $this->parser->parse($line, $lineNumber);

            if ($lineNumber === 1 && $record->type !== AfdRecordType::Header) {
                throw $this->refused('a primeira linha não é o cabeçalho');
            }

            if ($record->type === AfdRecordType::Header) {
                if ($lineNumber !== 1) {
                    throw $this->refused('cabeçalho repetido na linha '.$lineNumber.': o arquivo deve conter um único REP');
                }

                $header = $record;

                continue;
            }

            if ($record->type === AfdRecordType::Trailer) {
                $trailerLine = $lineNumber;
                $trailerCounts = $record->trailerCounts;

                continue;
            }

            if ($previousNsr !== null && $record->nsr !== $previousNsr + 1) {
                throw $this->refused('NSR fora de sequência na linha '.$lineNumber.' (esperado '.($previousNsr + 1).', encontrado '.$record->nsr.')');
            }

            if ($previousNsr === null && $record->nsr < 1) {
                throw $this->refused('NSR inválido na linha '.$lineNumber);
            }

            $firstNsr ??= $record->nsr;
            $previousNsr = $record->nsr;
            $counts[$this->countKey($record->type)]++;

            if ($record->isValidPunch()) {
                $maxValidPunchNsr = $record->nsr;
            } elseif ($record->type === AfdRecordType::Punch && $record->rejection === AfdRejectionReason::InvalidLength) {
                $invalidLengthPunches++;
            }
        }

        if ($header === null) {
            throw $this->refused('arquivo vazio ou sem cabeçalho');
        }

        if ($trailerLine === null) {
            throw $this->refused('trailer ausente: o arquivo pode estar incompleto');
        }

        if ($trailerCounts !== $counts) {
            throw $this->refused('os contadores do trailer não correspondem aos registros do arquivo');
        }

        if ($counts['punch'] > 0 && $invalidLengthPunches === $counts['punch']) {
            throw new DomainException('Layout de AFD não suportado: nenhuma marcação tem o tamanho do layout '.AfdParser::LAYOUT.'. Nenhuma batida foi gravada.');
        }

        return [
            'rep_serial' => $header->repSerial,
            'starts_on' => $header->startsOn,
            'ends_on' => $header->endsOn,
            'generated_at' => $header->generatedAt,
            'first_nsr' => $firstNsr,
            'max_nsr' => $previousNsr,
            'max_valid_punch_nsr' => $maxValidPunchNsr,
            'trailer_line' => $trailerLine,
            'counts' => $counts,
            'invalid_length_punches' => $invalidLengthPunches,
            'file_hash' => hash_file('sha256', $path),
            'file_size' => filesize($path),
        ];
    }

    /**
     * @param  array<string, mixed>  $scan
     * @return array<string, mixed>
     */
    private function persist(string $path, array $scan, ?int $userId): array
    {
        AfdRep::query()->insertOrIgnore(['rep_serial' => $scan['rep_serial'], 'created_at' => now(), 'updated_at' => now()]);
        $rep = AfdRep::query()->where('rep_serial', $scan['rep_serial'])->lockForUpdate()->firstOrFail();

        $start = $rep->processed_to_nsr !== null
            ? ['cursor' => $rep->processed_to_nsr, 'transition' => null, 'legacy_line_number' => null, 'legacy_checked' => 0, 'legacy_confirmed' => 0]
            : $this->startingPoint($path, $scan);
        $cursor = $start['cursor'];

        if (($scan['max_nsr'] ?? 0) < $cursor) {
            throw $this->refused('o arquivo termina no NSR '.($scan['max_nsr'] ?? 0).', antes do último NSR já processado deste relógio ('.$cursor.'). Use o AFD completo mais recente');
        }

        if ($scan['first_nsr'] !== null && $scan['first_nsr'] > $cursor + 1) {
            throw $this->refused('o arquivo começa no NSR '.$scan['first_nsr'].' e o último NSR processado deste relógio é '.$cursor.': faltariam registros entre eles');
        }

        $cursorAfter = max($cursor, $scan['max_nsr'] ?? 0);

        $import = new AfdImport([
            'file_hash' => $scan['file_hash'],
            'file_size' => $scan['file_size'],
            'file_starts_on' => $scan['starts_on'],
            'file_ends_on' => $scan['ends_on'],
            'file_generated_at' => $scan['generated_at'],
            'transition' => $start['transition'],
            'legacy_line_number' => $start['legacy_line_number'],
            'legacy_checked' => $start['legacy_checked'],
            'legacy_confirmed' => $start['legacy_confirmed'],
            'cursor_before' => $cursor,
            'cursor_after' => $cursorAfter,
            'first_nsr' => $scan['first_nsr'],
            'max_nsr' => $scan['max_nsr'],
            'max_valid_punch_nsr' => $scan['max_valid_punch_nsr'],
            'company_records' => $scan['counts']['company'],
            'punch_records' => $scan['counts']['punch'],
            'clock_adjustment_records' => $scan['counts']['clock_adjustment'],
            'employee_records' => $scan['counts']['employee'],
        ]);
        $import->rep()->associate($rep);
        $import->user_id = $userId;
        $import->save();

        $totals = ['imported' => 0, 'existing' => 0, 'already_processed' => 0, 'rejected' => 0];
        $points = [];
        $rejections = [];
        $validAbove = 0;
        $now = now();

        foreach ($this->lines($path) as $lineNumber => $line) {
            if ($this->parser->withoutTerminator($line) === '') {
                continue;
            }

            $record = $this->parser->parse($line, $lineNumber);

            if ($record->type !== AfdRecordType::Punch) {
                continue;
            }

            if ($record->nsr <= $cursor) {
                $totals['already_processed']++;

                continue;
            }

            if ($record->rejection !== null) {
                $rejections[] = ['afd_import_id' => $import->id, 'afd_rep_id' => $rep->id, 'nsr' => $record->nsr, 'reason' => $record->rejection->value, 'created_at' => $now];
                $totals['rejected']++;

                continue;
            }

            $validAbove++;
            $points[] = [
                'pis' => (int) $record->pis,
                'date' => $record->date->toDateString(),
                'time' => $record->time,
                'type' => 'importado',
                'rep_serial' => $rep->rep_serial,
                'nsr' => $record->nsr,
                'afd_import_id' => $import->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($points) >= self::BATCH_SIZE) {
                $totals['imported'] += Point::query()->insertOrIgnore($points);
                $points = [];
            }
        }

        if ($points !== []) {
            $totals['imported'] += Point::query()->insertOrIgnore($points);
        }

        $totals['existing'] = $validAbove - $totals['imported'];

        foreach (array_chunk($rejections, self::BATCH_SIZE) as $chunk) {
            AfdImportRejection::query()->insertOrIgnore($chunk);
        }

        $maxImportedNsr = Point::query()->where('afd_import_id', $import->id)->max('nsr');

        $import->forceFill([
            ...$totals,
            'max_imported_nsr' => $maxImportedNsr === null ? null : (int) $maxImportedNsr,
            'completed_at' => now(),
        ])->save();

        $rep->forceFill(['processed_to_nsr' => $cursorAfter])->save();

        return [
            'import_id' => $import->id,
            'transition' => $start['transition'],
            'legacy_line_number' => $start['legacy_line_number'],
            'cursor_before' => $cursor,
            'cursor_after' => $cursorAfter,
            'max_nsr' => $scan['max_nsr'],
            'max_valid_punch_nsr' => $scan['max_valid_punch_nsr'],
            'max_imported_nsr' => $import->max_imported_nsr,
            ...$totals,
            'rejections' => array_map(fn (array $row) => ['nsr' => $row['nsr'], 'reason' => $row['reason']], $rejections),
            'ignored' => [
                'header' => 1,
                'company' => $scan['counts']['company'],
                'clock_adjustment' => $scan['counts']['clock_adjustment'],
                'employee' => $scan['counts']['employee'],
                'trailer' => 1,
            ],
        ];
    }

    /**
     * Ponto de partida do primeiro AFD de um REP (cursor ainda nulo).
     *
     * - Outro REP já importado pelo novo mecanismo, ou nenhum histórico legado e
     *   nenhuma batida importada: início do zero (cursor 0).
     * - Batidas importadas pelo legado (sem NSR) sem `imported_lines`: ambíguo, recusado.
     * - Com `imported_lines` (L = maior line_number, linhas consumidas pelo importador
     *   legado com o trailer): exige AFD completo a partir do NSR 1 (linha k = NSR k−1);
     *   o cursor é L−2 (o registro da linha L−1, último do arquivo anterior). Antes de
     *   aceitar, confere no banco os registros anteriores ao cursor que o legado deveria
     *   ter gravado e verifica que os registros a partir do NSR L−1 ainda não existem.
     *
     * @param  array<string, mixed>  $scan
     * @return array{cursor: int, transition: string, legacy_line_number: ?int, legacy_checked: int, legacy_confirmed: int}
     *
     * @throws DomainException
     */
    private function startingPoint(string $path, array $scan): array
    {
        $fresh = ['cursor' => 0, 'transition' => AfdImport::TRANSITION_FRESH, 'legacy_line_number' => null, 'legacy_checked' => 0, 'legacy_confirmed' => 0];

        if (AfdRep::query()->whereNotNull('processed_to_nsr')->exists()) {
            return $fresh;
        }

        $legacyLines = ImportedLines::query()->pluck('line_number')->map(fn ($line) => (int) $line)->unique()->flip();

        if ($legacyLines->isEmpty()) {
            if (Point::query()->where('type', 'importado')->whereNull('nsr')->exists()) {
                throw $this->ambiguous('há batidas importadas sem histórico de importação (imported_lines), então o ponto de partida não pode ser determinado');
            }

            return $fresh;
        }

        $legacyLine = (int) $legacyLines->keys()->max();

        if ($scan['first_nsr'] !== 1) {
            throw $this->ambiguous('a transição do controle legado exige o AFD completo, iniciado no NSR 1');
        }

        if ($legacyLine > $scan['trailer_line']) {
            throw $this->ambiguous('o arquivo tem '.$scan['trailer_line'].' linhas e a última importação anterior consumiu '.$legacyLine.': o arquivo é anterior a ela');
        }

        if ($legacyLine < 2) {
            throw $this->ambiguous('o histórico legado não indica nenhum registro importado');
        }

        $cursor = $legacyLine - 2;
        $records = $this->legacyKeys($path, max(1, $cursor - self::LEGACY_EVIDENCE_WINDOW + 1));
        $existing = $this->existingLegacyKeys($records->pluck('key')->unique());

        $expected = $records->filter(fn (array $record) => $record['nsr'] <= $cursor
            && $record['nsr'] + 1 > self::LEGACY_INITIAL_SKIP
            && ! $legacyLines->has($record['nsr'] + 1));
        $missing = $expected->reject(fn (array $record) => ($existing[$record['key']] ?? 0) > 0);

        if ($expected->isEmpty()) {
            throw $this->ambiguous('não há registros anteriores ao NSR '.($cursor + 1).' que comprovem a última importação legada');
        }

        if ($missing->isNotEmpty()) {
            throw $this->ambiguous($missing->count().' de '.$expected->count().' registros anteriores ao NSR '.($cursor + 1).' não foram encontrados no banco (primeiro NSR ausente: '.$missing->first()['nsr'].')');
        }

        $expectedByKey = $expected->countBy('key');
        $alreadyPresent = $records->filter(fn (array $record) => $record['nsr'] > $cursor)
            ->pluck('key')->unique()
            ->filter(fn (string $key) => ($existing[$key] ?? 0) > ($expectedByKey[$key] ?? 0));

        if ($alreadyPresent->isNotEmpty()) {
            throw $this->ambiguous($alreadyPresent->count().' registro(s) a partir do NSR '.($cursor + 1).' parecem já existir no banco');
        }

        return [
            'cursor' => $cursor,
            'transition' => AfdImport::TRANSITION_LEGACY,
            'legacy_line_number' => $legacyLine,
            'legacy_checked' => $expected->count(),
            'legacy_confirmed' => $expected->count() - $missing->count(),
        ];
    }

    /**
     * Chave com que o importador legado gravava cada registro (tipos 2 a 5): data e
     * horário das posições 011-022 e "PIS" das posições 024-035, sem zeros à esquerda.
     *
     * @return Collection<int, array{nsr: int, key: string}>
     */
    private function legacyKeys(string $path, int $fromNsr): Collection
    {
        $records = collect();

        foreach ($this->lines($path) as $lineNumber => $line) {
            $line = $this->parser->withoutTerminator($line);

            if ($lineNumber === 1 || $line === '' || str_starts_with($line, '999999999') || (int) substr($line, 0, 9) < $fromNsr) {
                continue;
            }

            $date = $this->parser->date(substr($line, 10, 8));
            $time = $this->parser->time(substr($line, 18, 4));
            $pis = trim(substr($line, 23, 12));

            if ($date === null || $time === null || ! ctype_digit($pis)) {
                continue;
            }

            $records->push(['nsr' => (int) substr($line, 0, 9), 'key' => $this->legacyKey($date->toDateString(), $time, $pis)]);
        }

        return $records;
    }

    /**
     * Quantidade de batidas gravadas pelo importador legado (sem NSR) por chave legada.
     *
     * @param  Collection<int, string>  $keys
     * @return array<string, int>
     */
    private function existingLegacyKeys(Collection $keys): array
    {
        $dates = $keys->map(fn (string $key) => explode('|', $key)[0])->sort()->values();
        $wanted = $keys->flip();
        $counts = [];

        if ($dates->isEmpty()) {
            return [];
        }

        Point::query()->where('type', 'importado')->whereNull('nsr')
            ->whereDate('date', '>=', $dates->first())
            ->whereDate('date', '<=', $dates->last())
            ->get(['date', 'time', 'pis'])
            ->each(function (Point $point) use ($wanted, &$counts) {
                $key = $this->legacyKey($point->getRawOriginal('date'), (string) $point->getRawOriginal('time'), (string) $point->getRawOriginal('pis'));

                if ($wanted->has($key)) {
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            });

        return $counts;
    }

    private function legacyKey(string $date, string $time, string $pis): string
    {
        return substr($date, 0, 10).'|'.substr($time, 0, 5).'|'.ltrim($pis, '0');
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

    private function countKey(AfdRecordType $type): string
    {
        return match ($type) {
            AfdRecordType::Company => 'company',
            AfdRecordType::Punch => 'punch',
            AfdRecordType::ClockAdjustment => 'clock_adjustment',
            AfdRecordType::Employee => 'employee',
        };
    }

    private function refused(string $reason): DomainException
    {
        return new DomainException('AFD recusado: '.$reason.'. Nenhuma batida foi gravada.');
    }

    private function ambiguous(string $reason): DomainException
    {
        return new DomainException('Transição do controle legado recusada: '.$reason.'. Nenhuma batida foi gravada; o ponto de partida precisa ser conferido antes da importação.');
    }
}
