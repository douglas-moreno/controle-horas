<?php

namespace Tests\Support;

use App\Models\ImportedLines;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Histórico legado em miniatura, com os mesmos defeitos da base real: duas execuções do
 * importador legado (EmployeeIndex::importPoints antes da F13), que gravava toda linha
 * como batida, inclusive registros que não são marcações e o trailer da época, e pulava
 * as 9 primeiras linhas.
 *
 * Arquivo final (cumulativo):
 * - linhas 2–3: empresa e empregado (puladas); linhas 4–9: marcações NSR 3–8 (puladas);
 * - execução 1 (linhas 10–16): ajuste do relógio NSR 9 (indevido), marcações NSR 10–14 e
 *   o trailer da época na linha 16 (indevido);
 * - execução 2 (linhas 17–78): marcação NSR 15 na linha 16, nunca gravada; empregado
 *   NSR 16 (indevido), marcações NSR 17–76 e o trailer da época na linha 78 (indevido);
 * - depois: marcações NSR 77–78, ainda não importadas. Cursor legado: 76.
 */
final class LegacyAfdScenario
{
    public const PIS = '012345678901';

    public const CURSOR = 76;

    /**
     * Marcações que o legado não gravou.
     */
    public const MISSING_NSRS = [3, 4, 5, 6, 7, 8, 15];

    /**
     * Linhas do AFD cujos pontos legados são indevidos, com o tipo do registro.
     */
    public const SPURIOUS_LINES = [10 => '4', 16 => '9', 17 => '5', 78 => '9'];

    public function __construct(public AfdFileBuilder $file, public string $path) {}

    public static function build(): self
    {
        $file = AfdFileBuilder::make()->company()->employee(self::PIS);
        self::punches($file, 6);
        $file->clockAdjustment();
        self::punches($file, 5);
        self::legacyImport($file->write());

        Carbon::setTestNow(now()->addMinute());
        self::punches($file, 1);
        $file->employee(self::PIS, 'A');
        self::punches($file, 60);
        self::legacyImport($file->write());

        Carbon::setTestNow(now()->addMinute());
        self::punches($file, 2);

        return new self($file, $file->write());
    }

    /**
     * Reproduz o importador legado: grava toda linha depois da última consumida.
     */
    public static function legacyImport(string $path): void
    {
        $lines = preg_split('/\r\n|\n/', rtrim((string) file_get_contents($path), "\r\n"));
        $start = (int) (ImportedLines::query()->max('line_number') ?? 9);
        $rows = [];

        foreach (array_slice($lines, $start) as $line) {
            $hour = substr($line, 18, 4);
            $rows[] = [
                'pis' => (int) trim(substr($line, 23, 12)),
                'date' => Carbon::createFromFormat('dmY', substr($line, 10, 8))->format('Y-m-d'),
                'time' => substr($hour, 0, 2).':'.substr($hour, 2, 2).':00',
                'type' => 'importado',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('points')->insert($rows);
        ImportedLines::query()->create(['line_number' => count($lines)]);
    }

    /**
     * Linhas do arquivo final sem o terminador, numeradas a partir de 1.
     *
     * @return array<int, string>
     */
    public function lines(): array
    {
        $lines = $this->file->lines();

        return array_combine(range(1, count($lines)), $lines);
    }

    /**
     * Id do ponto legado gravado para a linha (execução 1: linhas 10–16; execução 2: 17–78).
     */
    public function pointIdForLine(int $line): int
    {
        $ids = DB::table('points')->where('type', 'importado')->whereNull('nsr')->orderBy('id')->pluck('id')->all();

        return (int) $ids[$line - 10];
    }

    /**
     * Hash canônico documentado em AfdHistoricalCorrection, calculado de forma independente.
     */
    public static function canonicalHash(int $id): string
    {
        $row = DB::table('points')->where('id', $id)
            ->selectRaw('id, pis, date, time, type, rep_serial, nsr, afd_import_id, unix_timestamp(created_at) as created_at, unix_timestamp(updated_at) as updated_at')
            ->first();

        return hash('sha256', implode("\x1F", array_map(fn ($value) => $value === null ? '\N' : (string) $value, (array) $row)));
    }

    /**
     * @return array<string, mixed>
     */
    public function quarantineManifest(): array
    {
        $rows = [];

        foreach (self::SPURIOUS_LINES as $line => $type) {
            $id = $this->pointIdForLine($line);
            $rows[] = [
                'id' => $id,
                'afd_line' => $line,
                'afd_nsr' => $type === '9' ? null : (int) substr($this->lines()[$line], 0, 9),
                'afd_record_type' => $type,
                'criterion' => 'posição '.$line.' da execução legada',
                'row_hash' => self::canonicalHash($id),
            ];
        }

        return $this->manifest('quarantine', $rows);
    }

    /**
     * @param  array<int, string>  $classifications  NSR => classificação.
     * @return array<string, mixed>
     */
    public function registrationManifest(array $classifications = [3 => 'C2', 15 => 'C2']): array
    {
        return $this->manifest('registration', array_map(fn (int $nsr) => [
            ...$this->punchRow($nsr),
            'classification' => $classifications[$nsr] ?? 'C1',
            'evidence' => $nsr < 9 ? 'pulo inicial do legado' : 'posição do trailer da execução 1',
        ], self::MISSING_NSRS));
    }

    /**
     * @param  list<int>  $nsrs
     * @return array<string, mixed>
     */
    public function recoveryManifest(array $nsrs = [3, 15], string $classification = 'C2'): array
    {
        return $this->manifest('recovery', array_map(fn (int $nsr) => [...$this->punchRow($nsr), 'classification' => $classification], $nsrs));
    }

    /**
     * Retenção por decisão do RH (classificação R) das marcações novas informadas, com o
     * registro formal da decisão. `$path`: AFD de referência (padrão: o arquivo final).
     *
     * @param  list<int>  $nsrs
     * @param  array<string, mixed>  $overrides  Campos sobrescritos em todas as linhas.
     * @return array<string, mixed>
     */
    public function withholdingManifest(array $nsrs = [77], array $overrides = [], ?string $path = null): array
    {
        $manifest = $this->manifest('withholding', array_map(fn (int $nsr) => [
            ...$this->punchRow($nsr),
            'classification' => 'R',
            'evidence' => 'Comunicação formal do RH',
            'decided_by' => 'Responsável do RH',
            'decision_justification' => 'Legitimidade da marcação não confirmada pelo RH.',
            'decided_at' => '2026-10-24T15:00:00-03:00',
            ...$overrides,
        ], $nsrs));

        return $path === null ? $manifest : [...$manifest, 'afd_sha256' => hash_file('sha256', $path)];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public static function write(array $manifest): string
    {
        return AfdFileBuilder::writeContents((string) json_encode($manifest));
    }

    /**
     * @return array{nsr: int, afd_line: int, afd_line_sha256: string}
     */
    private function punchRow(int $nsr): array
    {
        $line = $nsr + 1;

        return ['nsr' => $nsr, 'afd_line' => $line, 'afd_line_sha256' => hash('sha256', $this->lines()[$line])];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function manifest(string $kind, array $rows): array
    {
        return [
            'kind' => $kind,
            'afd_sha256' => hash_file('sha256', $this->path),
            'rep_serial' => AfdFileBuilder::REP_SERIAL,
            'expected_count' => count($rows),
            'rows' => $rows,
        ];
    }

    private static function punches(AfdFileBuilder $file, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $nsr = $file->nextNsr();
            $file->punch(self::PIS, sprintf('%02d092026', 1 + $nsr % 28), sprintf('08%02d', $nsr % 60));
        }
    }
}
