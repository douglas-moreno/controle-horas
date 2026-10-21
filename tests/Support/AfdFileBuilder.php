<?php

namespace Tests\Support;

/**
 * Monta AFDs sintéticos no layout da Portaria MTE 1510/2009 (Anexo I). Os NSRs são
 * atribuídos em sequência; o trailer é calculado a partir dos registros. Nenhum dado
 * é real: PIS, CNPJ, nomes e número do REP são fictícios.
 */
final class AfdFileBuilder
{
    public const REP_SERIAL = '00004000000012345';

    /**
     * @var list<array{type: string, body: string}>
     */
    private array $records = [];

    /**
     * @var array{company: int, punch: int, clock_adjustment: int, employee: int}|null
     */
    private ?array $trailerOverride = null;

    /**
     * @var list<string>
     */
    private static array $writtenPaths = [];

    private string $lineEnding = "\r\n";

    private int $firstNsr = 1;

    public function __construct(private string $repSerial = self::REP_SERIAL) {}

    public static function make(string $repSerial = self::REP_SERIAL): self
    {
        return new self($repSerial);
    }

    public function company(string $date = '01092026', string $time = '0800'): self
    {
        return $this->add('2', $date.$time.'1'.'11222333000181'.str_repeat('0', 12).str_pad('EMPRESA FICTICIA', 150).str_pad('LOCAL FICTICIO', 100));
    }

    /**
     * Marcação com os campos informados sem validação (permite montar inválidas).
     */
    public function punch(string $pis, string $date, string $time): self
    {
        return $this->add('3', $date.$time.$pis);
    }

    /**
     * Marcação com o conteúdo após o tipo informado literalmente (linha curta ou longa).
     */
    public function rawPunch(string $body): self
    {
        return $this->add('3', $body);
    }

    public function clockAdjustment(string $before = '010920260800', string $after = '010920260805'): self
    {
        return $this->add('4', $before.$after);
    }

    public function employee(string $pis, string $operation = 'I', string $date = '01092026', string $time = '0700'): self
    {
        return $this->add('5', $date.$time.$operation.$pis.str_pad('EMPREGADO FICTICIO', 52));
    }

    /**
     * @param  array{company: int, punch: int, clock_adjustment: int, employee: int}  $counts
     */
    public function trailerCounts(array $counts): self
    {
        $this->trailerOverride = $counts;

        return $this;
    }

    /**
     * AFD parcial: o primeiro registro tem o NSR informado.
     */
    public function startingAt(int $nsr): self
    {
        $this->firstNsr = $nsr;

        return $this;
    }

    public function withLf(): self
    {
        $this->lineEnding = "\n";

        return $this;
    }

    public function nextNsr(): int
    {
        return $this->firstNsr + count($this->records);
    }

    public function header(): string
    {
        return '000000000'.'1'.'1'.'11222333000181'.str_repeat('0', 12).str_pad('EMPRESA FICTICIA', 150)
            .$this->repSerial.'01092026'.'30092026'.'30092026'.'1800';
    }

    public function trailer(): string
    {
        $counts = $this->trailerOverride ?? [
            'company' => $this->count('2'),
            'punch' => $this->count('3'),
            'clock_adjustment' => $this->count('4'),
            'employee' => $this->count('5'),
        ];

        return '999999999'.sprintf('%09d%09d%09d%09d', $counts['company'], $counts['punch'], $counts['clock_adjustment'], $counts['employee']).'9';
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [$this->header()];

        foreach ($this->records as $index => $record) {
            $lines[] = sprintf('%09d', $this->firstNsr + $index).$record['type'].$record['body'];
        }

        $lines[] = $this->trailer();

        return $lines;
    }

    public function contents(): string
    {
        return self::join($this->lines(), $this->lineEnding);
    }

    /**
     * Grava o arquivo em um diretório temporário e devolve o caminho.
     */
    public function write(): string
    {
        return self::writeContents($this->contents());
    }

    /**
     * @param  list<string>  $lines
     */
    public static function join(array $lines, string $lineEnding = "\r\n"): string
    {
        return implode($lineEnding, $lines).$lineEnding;
    }

    public static function writeContents(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'afd');
        file_put_contents($path, $contents);
        self::$writtenPaths[] = $path;

        return $path;
    }

    /**
     * Remove os arquivos gravados por writeContents().
     */
    public static function deleteWrittenFiles(): void
    {
        foreach (self::$writtenPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        self::$writtenPaths = [];
    }

    private function add(string $type, string $body): self
    {
        $this->records[] = ['type' => $type, 'body' => $body];

        return $this;
    }

    private function count(string $type): int
    {
        return count(array_filter($this->records, fn (array $record) => $record['type'] === $type));
    }
}
