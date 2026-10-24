<?php

namespace App\Services;

use App\Models\AfdPointQuarantine;
use App\Models\ImportedLines;
use App\Models\Point;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Prova posicional do que o importador legado gravou. Somente leitura.
 *
 * O legado lia o AFD acumulado a partir da última linha consumida (`imported_lines`),
 * gravava uma batida por linha (todos os tipos, com data, hora e "PIS" das posições
 * 011-022 e 024-035) e registrava a nova última linha. Cada par consecutivo de
 * `imported_lines` delimita uma execução: linhas (anterior, atual] e batidas com
 * created_at em (anterior, atual]. A primeira execução começa depois da linha
 * AfdImporter::LEGACY_INITIAL_SKIP. Batidas sem created_at só são atribuídas à única
 * execução sem nenhuma batida cuja quantidade de linhas coincide.
 *
 * Uma execução só é alinhada quando a quantidade de batidas é igual à de linhas (o
 * trailer da época foi gravado na última posição) ou uma a menos (o trailer foi
 * ignorado). Fora disso, nenhuma linha da execução é comprovada. Uma marcação (tipo 3)
 * só é comprovada quando a batida da mesma posição tem data, hora e PIS iguais à
 * transformação legada da linha. Os pontos em quarentena continuam contando como
 * gravados pelo legado, para que a prova não mude com a quarentena.
 */
class AfdLegacyAlignment
{
    /**
     * Linhas do arquivo sem o terminador, numeradas a partir de 1.
     *
     * @return array<int, string>
     */
    public function readLines(string $path): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new DomainException('Não foi possível ler o arquivo AFD.');
        }

        $lines = [];
        $lineNumber = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $lines[++$lineNumber] = rtrim($line, "\r\n");
            }
        } finally {
            fclose($handle);
        }

        return $lines;
    }

    /**
     * Data, hora e PIS que o importador legado gravaria para a linha, como
     * Carbon::createFromFormat('dmY') (com o transbordo do PHP). Null quando o legado
     * falharia na data.
     *
     * @return array{date: string, time: string, pis: string}|null
     */
    public function legacyFields(string $line): ?array
    {
        $date = substr($line, 10, 8);

        if (strlen($date) !== 8 || ! ctype_digit($date)) {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat('dmY', $date);
        } catch (Throwable) {
            return null;
        }

        if ($parsed === null) {
            return null;
        }

        $hour = substr($line, 18, 4);
        $pis = trim(substr($line, 23, 12));

        return [
            'date' => $parsed->format('Y-m-d'),
            'time' => substr($hour, 0, 2).':'.substr($hour, 2, 2).':00',
            'pis' => ctype_digit($pis) ? (string) (int) $pis : $pis,
        ];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{verified: array<int, int>, positions: array<int, array{line: int, run: int, last: bool}>, runs: array{total: int, aligned: int, unaligned: list<int>, mismatched_punches: int}}
     */
    public function align(array $lines): array
    {
        $runs = $this->runs();
        $points = $this->legacyPoints();
        $undated = [];
        $ends = array_column($runs, 'to');

        foreach ($ends as $index => $end) {
            if ($end === null || ($index > 0 && $end < $ends[$index - 1])) {
                throw new DomainException('imported_lines sem created_at ou fora de ordem cronológica: a prova posicional do legado não pode ser feita');
            }
        }

        foreach ($points as $id => $point) {
            if ($point['created_at'] === null) {
                $undated[] = $id;

                continue;
            }

            $index = $this->runIndex($ends, $point['created_at']);

            if ($index !== null) {
                $runs[$index]['points'][] = $id;
            }
        }

        if ($undated !== []) {
            $candidates = array_keys(array_filter($runs, fn (array $run) => $run['points'] === []
                && in_array(count($undated), [$run['end'] - $run['start'], $run['end'] - $run['start'] - 1], true)));

            if (count($candidates) === 1) {
                $runs[$candidates[0]]['points'] = $undated;
            }
        }

        $verified = [];
        $positions = [];
        $stats = ['total' => count($runs), 'aligned' => 0, 'unaligned' => [], 'mismatched_punches' => 0];

        foreach ($runs as $run) {
            $expected = $run['end'] - $run['start'];
            $count = count($run['points']);

            if ($expected <= 0 && $count === 0) {
                $stats['aligned']++;

                continue;
            }

            if ($count !== $expected && $count !== $expected - 1) {
                $stats['unaligned'][] = $run['id'];

                continue;
            }

            $stats['aligned']++;

            foreach ($run['points'] as $offset => $id) {
                $lineNumber = $run['start'] + 1 + $offset;
                $positions[$id] = ['line' => $lineNumber, 'run' => $run['id'], 'last' => $count === $expected && $lineNumber === $run['end']];
                $line = $lines[$lineNumber] ?? null;

                if ($line === null || ! $this->isPunch($line, $lineNumber)) {
                    continue;
                }

                $fields = $this->legacyFields($line);

                if ($fields !== null && $points[$id]['key'] === $fields['date'].'|'.substr($fields['time'], 0, 5).'|'.$fields['pis']) {
                    $verified[(int) substr($line, 0, 9)] = $id;
                } else {
                    $stats['mismatched_punches']++;
                }
            }
        }

        return ['verified' => $verified, 'positions' => $positions, 'runs' => $stats];
    }

    public function isPunch(string $line, int $lineNumber): bool
    {
        return $lineNumber > 1 && ! str_starts_with($line, '999999999') && substr($line, 9, 1) === '3';
    }

    /**
     * @return list<array{id: int, start: int, end: int, from: ?string, to: ?string, points: list<int>}>
     */
    private function runs(): array
    {
        $runs = [];
        $previous = null;

        foreach (ImportedLines::query()->toBase()->orderBy('id')->get(['id', 'line_number', 'created_at']) as $row) {
            $runs[] = [
                'id' => (int) $row->id,
                'start' => $previous === null ? AfdImporter::LEGACY_INITIAL_SKIP : (int) $previous->line_number,
                'end' => (int) $row->line_number,
                'from' => $previous?->created_at,
                'to' => $row->created_at,
                'points' => [],
            ];
            $previous = $row;
        }

        return $runs;
    }

    /**
     * Batidas gravadas pelo legado (importadas, sem NSR), inclusive as em quarentena,
     * em ordem de id.
     *
     * @return array<int, array{created_at: ?string, key: string}>
     */
    private function legacyPoints(): array
    {
        $points = [];

        foreach (Point::query()->toBase()->where('type', 'importado')->whereNull('nsr')->select(['id', 'pis', 'date', 'time', 'created_at'])->cursor() as $point) {
            $points[(int) $point->id] = $this->legacyPoint($point->created_at, $point->date, $point->time, $point->pis);
        }

        if (Schema::hasTable('afd_point_quarantines')) {
            $quarantined = AfdPointQuarantine::query()->toBase()
                ->where('status', AfdPointQuarantine::STATUS_QUARANTINED)
                ->where('type', 'importado')
                ->whereNull('nsr')
                ->select(['original_id', 'pis', 'date', 'time', 'original_created_at'])
                ->cursor();

            foreach ($quarantined as $point) {
                $points[(int) $point->original_id] = $this->legacyPoint($point->original_created_at, $point->date, $point->time, $point->pis);
            }
        }

        ksort($points);

        return $points;
    }

    /**
     * @return array{created_at: ?string, key: string}
     */
    private function legacyPoint(mixed $createdAt, mixed $date, mixed $time, mixed $pis): array
    {
        return [
            'created_at' => $createdAt === null ? null : (string) $createdAt,
            'key' => substr((string) $date, 0, 10).'|'.substr((string) $time, 0, 5).'|'.($pis === null ? '' : (string) $pis),
        ];
    }

    /**
     * Execução cuja janela (fim da anterior, fim desta] contém o instante.
     *
     * @param  list<?string>  $ends
     */
    private function runIndex(array $ends, string $createdAt): ?int
    {
        $low = 0;
        $high = count($ends) - 1;
        $found = null;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);

            if ($ends[$middle] !== null && $ends[$middle] >= $createdAt) {
                $found = $middle;
                $high = $middle - 1;
            } else {
                $low = $middle + 1;
            }
        }

        return $found;
    }
}
