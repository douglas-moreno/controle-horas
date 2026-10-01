<?php

namespace App\Services;

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\BenefitPeriodStatus;
use App\Enums\BenefitType;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\TransportFare;
use App\Models\TransportRoute;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Carga inicial a partir da planilha de controle de VT/VR (formato "VT e VR -09.2026.xlsx").
 *
 * Aba "Controle VT-VR": competência (B2), dias úteis (N2), uma linha por funcionário a
 * partir da linha 5 com o PIS na coluna A, dias de VT/VR/VD (G/H/I) e colunas de ajuste
 * (J–Z). Aba "Vale transp calc": tarifas (linhas 3–4, colunas C–V) e viagens do mês por
 * trecho na forma "=X{linha}*{viagens por dia}".
 *
 * A planilha é lida na execução; nenhum dado pessoal fica no código. Tudo é validado
 * antes de gravar (dias recalculados e valores conferidos contra as colunas G/H/I/AA/AB)
 * e a gravação ocorre em uma única transação. Funcionários que já possuem elegibilidade
 * ou itinerário são ignorados, o que torna a carga repetível.
 */
class BenefitSpreadsheetImporter
{
    public const MAIN_SHEET = 'Controle VT-VR';

    public const FARES_SHEET = 'Vale transp calc';

    public const FIRST_ROW = 5;

    /**
     * Coluna da aba de tarifas => [nome na planilha (linha 3), nome no catálogo].
     *
     * @var array<string, array{string, string}>
     */
    public const FARE_COLUMNS = [
        'C' => ['CPTM', 'CPTM'],
        'D' => ['CMT BOM', 'CMT BOM 1'],
        'E' => ['CMT BOM', 'CMT BOM 2'],
        'F' => ['372', '372'],
        'G' => ['BR7', 'BR7'],
        'H' => ['INT TRILHOS', 'INT TRILHOS'],
        'I' => ['MUN MAUÁ', 'MUN MAUÁ'],
        'J' => ['SP', 'SP'],
        'K' => ['RIB.PIRES', 'RIB.PIRES'],
        'L' => ['UNI ABC', 'UNI ABC'],
        'M' => ['ETC MAUÁ', 'ETC MAUÁ'],
        'N' => ['SZT MAUÁ', 'SZT MAUÁ'],
        'O' => ['TROLEBUS', 'TROLEBUS'],
        'P' => ['RGS', 'RGS'],
        'Q' => ['ETC DIADEMA', 'ETC DIADEMA'],
        'R' => ['RGS TLM', 'RGS TLM'],
        'S' => ['INTEGRAÇÃO', 'INTEGRAÇÃO 1,10'],
        'T' => ['INTEGRAÇÃO', 'INTEGRAÇÃO 1,40'],
        'U' => ['SP TRANS', 'SP TRANS'],
        'V' => ['Rio Grande -Talismã', 'Rio Grande -Talismã'],
    ];

    /**
     * Colunas de ajuste da aba principal => [descrição, impacto por unidade], conforme as
     * fórmulas G (VT), H (VR) e I (VD) da planilha. Cada coluna preenchida vira um ajuste
     * Manual confirmado. A coluna Z (afastamento) não é aceita: a planilha desconta só VD
     * e a especificação desconta os três (DV-1), exigindo decisão do administrador.
     *
     * @var array<string, array{string, array<string, int>}>
     */
    public const ADJUSTMENT_COLUMNS = [
        'J' => ['falta justificada', ['vt' => -1, 'vr' => -1, 'vd' => -1]],
        'K' => ['falta injustificada', ['vt' => -1, 'vr' => -1, 'vd' => -1]],
        'L' => ['sábado', ['vt' => 1, 'vr' => 1, 'vd' => 1]],
        'M' => ['domingo/feriado', ['vt' => 1, 'vr' => 1, 'vd' => 1]],
        'N' => ['sábado/domingo/feriado somente VT', ['vt' => 1]],
        'O' => ['domingo/feriado somente VD', ['vd' => 1]],
        'P' => ['desconto VR', ['vr' => -1]],
        'Q' => ['desconto VD', ['vd' => -1]],
        'R' => ['pago a menor VR', ['vr' => 1, 'vd' => 1]],
        'S' => ['pago a menor VT', ['vt' => 1]],
        'T' => ['não quer VT', ['vt' => -1]],
        'U' => ['pago a maior VT/VR', ['vt' => -1, 'vr' => -1, 'vd' => -1]],
        'V' => ['pago a maior VR', ['vr' => -1, 'vd' => -1]],
        'W' => ['desconto atestado', ['vt' => -1, 'vr' => -1, 'vd' => -1]],
        'X' => ['desconto férias', ['vt' => -1, 'vr' => -1, 'vd' => -1]],
        'Y' => ['pago a maior / desconto VT', ['vt' => -1]],
    ];

    public function __construct(
        private BenefitPeriodWorkflow $workflow,
        private BenefitAdjustmentRegistrar $adjustmentRegistrar,
        private EmployeeBenefitRegistrar $benefitRegistrar,
        private TransportRouteRegistrar $routeRegistrar,
        private BusinessCalendar $calendar,
        private BenefitRateResolver $rateResolver,
        private TransportFarePriceResolver $priceResolver,
    ) {}

    /**
     * Lê, valida e grava a planilha.
     *
     * @return array{competence: string, imported: list<int>, skipped: list<array{line: int, reason: string}>, benefits: int, routes: int, adjustments: int, expected_vt_cents: int, expected_vr_vd_cents: int}
     *
     * @throws DomainException com todas as inconsistências encontradas; nada é gravado
     */
    public function import(string $path): array
    {
        $data = $this->read($path);

        $competence = $data['competence'];
        $fares = TransportFare::query()->whereIn('name', array_column(self::FARE_COLUMNS, 1))->get()->keyBy('name');
        $this->ensureCatalog($data['fare_prices'], $fares, $competence);

        if ($this->calendar->businessDaysInMonth($competence) !== $data['business_days']) {
            throw new DomainException('A planilha informa '.$data['business_days'].' dias úteis em '.$competence->format('m/Y').', mas o calendário do sistema tem '.$this->calendar->businessDaysInMonth($competence).'. Confira os feriados cadastrados.');
        }

        $errors = [];
        $skipped = [];
        $toImport = [];
        $employeesByPis = $this->employeesByPis();
        $rateCents = [
            'vr' => Money::toCents($this->rateResolver->forDate(BenefitType::Vr, $competence)->amount),
            'vd' => Money::toCents($this->rateResolver->forDate(BenefitType::Vd, $competence)->amount),
        ];

        foreach ($data['rows'] as $row) {
            $rowErrors = $this->validateRow($row, $data['business_days'], $data['fare_prices'], $rateCents);

            if ($rowErrors !== []) {
                array_push($errors, ...$rowErrors);

                continue;
            }

            if ($row['pis'] === '') {
                $skipped[] = ['line' => $row['line'], 'reason' => 'PIS não informado na coluna A.'];

                continue;
            }

            $candidates = $employeesByPis->get($row['pis'], collect());
            $active = $candidates->filter(fn (Employee $employee) => blank($employee->getRawOriginal('recision_date')));

            if ($candidates->isEmpty()) {
                $skipped[] = ['line' => $row['line'], 'reason' => 'PIS não encontrado no cadastro de funcionários.'];

                continue;
            }

            if ($active->count() !== 1) {
                $skipped[] = ['line' => $row['line'], 'reason' => $active->isEmpty()
                    ? 'PIS encontrado somente em funcionário com rescisão.'
                    : 'PIS encontrado em mais de um funcionário ativo.'];

                continue;
            }

            $employee = $active->first();

            if (EmployeeBenefit::query()->whereBelongsTo($employee)->exists() || TransportRoute::query()->whereBelongsTo($employee)->exists()) {
                $skipped[] = ['line' => $row['line'], 'reason' => 'Funcionário já possui elegibilidade ou itinerário cadastrado.'];

                continue;
            }

            $toImport[] = [$row, $employee];
        }

        if ($errors !== []) {
            throw new DomainException("A planilha não foi importada:\n- ".implode("\n- ", $errors));
        }

        return DB::transaction(function () use ($competence, $toImport, $skipped, $fares) {
            $period = $this->openPeriod($competence);
            $counts = ['benefits' => 0, 'routes' => 0, 'adjustments' => 0];
            $expectedVtCents = 0;
            $expectedVrVdCents = 0;

            foreach ($toImport as [$row, $employee]) {
                $note = 'Carga inicial da planilha de VT/VR '.$competence->format('m/Y').', linha '.$row['line'].'.';
                $types = $row['vt_days'] === null ? [BenefitType::Vr, BenefitType::Vd] : BenefitType::cases();

                foreach ($types as $type) {
                    $this->benefitRegistrar->register($employee, $type, $competence, null, $note);
                    $counts['benefits']++;
                }

                foreach ($row['vt_days'] === null ? [] : $row['routes'] as $column => $tripsPerDay) {
                    $this->routeRegistrar->register($employee, $fares[self::FARE_COLUMNS[$column][1]], $tripsPerDay, $competence, null, $note);
                    $counts['routes']++;
                }

                foreach ($row['adjustments'] as $column => $quantity) {
                    [$description, $unitImpacts] = self::ADJUSTMENT_COLUMNS[$column];
                    $impacts = collect($unitImpacts)
                        ->reject(fn (int $unit, string $type) => $type === BenefitType::Vt->value && $row['vt_days'] === null)
                        ->map(fn (int $unit) => $unit * $quantity)
                        ->all();

                    if ($impacts === []) {
                        continue;
                    }

                    $this->adjustmentRegistrar->register(
                        $period,
                        $employee,
                        AdjustmentReason::Manual,
                        AdjustmentSource::Manual,
                        $period->windowEnd(),
                        $period->windowEnd(),
                        'Carga inicial da planilha de VT/VR '.$competence->format('m/Y').', linha '.$row['line'].', coluna '.$column.' ('.$description.'): '.$quantity.'.',
                        $impacts,
                    );
                    $counts['adjustments']++;
                }

                $expectedVtCents += $row['vt_cents'];
                $expectedVrVdCents += $row['vr_vd_cents'];
            }

            return [
                'competence' => $competence->format('m/Y'),
                'imported' => array_map(fn (array $item) => $item[0]['line'], $toImport),
                'skipped' => $skipped,
                ...$counts,
                'expected_vt_cents' => $expectedVtCents,
                'expected_vr_vd_cents' => $expectedVrVdCents,
            ];
        });
    }

    /**
     * @return array{competence: CarbonImmutable, business_days: int, fare_prices: array<string, int>, rows: list<array<string, mixed>>}
     *
     * @throws DomainException
     */
    public function read(string $path): array
    {
        if (! is_file($path)) {
            throw new DomainException('Planilha não encontrada: '.$path);
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setLoadSheetsOnly([self::MAIN_SHEET, self::FARES_SHEET]);
        $spreadsheet = $reader->load($path);

        $main = $spreadsheet->getSheetByName(self::MAIN_SHEET);
        $fares = $spreadsheet->getSheetByName(self::FARES_SHEET);

        if ($main === null || $fares === null) {
            throw new DomainException('A planilha deve conter as abas "'.self::MAIN_SHEET.'" e "'.self::FARES_SHEET.'".');
        }

        $competenceValue = $this->value($main, 'B2');

        if (! is_numeric($competenceValue)) {
            throw new DomainException('A célula B2 da aba "'.self::MAIN_SHEET.'" deve conter a data da competência.');
        }

        $farePrices = [];

        foreach (self::FARE_COLUMNS as $column => [$sourceName]) {
            $header = preg_replace('/\s+/u', ' ', trim((string) $this->value($fares, $column.'3')));

            if ($header !== $sourceName) {
                throw new DomainException('Aba "'.self::FARES_SHEET.'", célula '.$column.'3: esperado "'.$sourceName.'", encontrado "'.$header.'".');
            }

            $farePrices[$column] = $this->cents($this->value($fares, $column.'4'));
        }

        $rows = [];

        for ($line = self::FIRST_ROW; $this->isEmployeeRow($main, $line); $line++) {
            $rows[] = $this->readRow($main, $fares, $line);
        }

        return [
            'competence' => CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $competenceValue))->startOfMonth()->startOfDay(),
            'business_days' => (int) $this->value($main, 'N2'),
            'fare_prices' => $farePrices,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readRow(Worksheet $main, Worksheet $fares, int $line): array
    {
        $vtDays = $this->value($main, 'G'.$line);
        $routes = [];
        $routeErrors = [];

        foreach (array_keys(self::FARE_COLUMNS) as $column) {
            $cell = $fares->getCell($column.$line);
            $content = $cell->getValue();

            if ($content === null || $content === '' || $content === 0 || $content === '0') {
                continue;
            }

            if (is_string($content) && preg_match('/^=\$?X\$?(\d+)\*(\d+)$/', str_replace(' ', '', $content), $matches) && (int) $matches[1] === $line) {
                $routes[$column] = (int) $matches[2];

                continue;
            }

            $routeErrors[] = 'linha '.$line.': trecho da coluna '.$column.' da aba "'.self::FARES_SHEET.'" em formato não reconhecido ('.$content.').';
        }

        $adjustments = [];
        $adjustmentErrors = [];

        foreach (array_merge(array_keys(self::ADJUSTMENT_COLUMNS), ['Z']) as $column) {
            $value = $this->value($main, $column.$line);

            if ($value === null || $value === '' || $value == 0) {
                continue;
            }

            if ($column === 'Z') {
                $adjustmentErrors[] = 'linha '.$line.': coluna Z (afastamento) preenchida. Lance o afastamento manualmente na tela de ajustes.';
            } elseif (! is_numeric($value) || (int) $value != $value) {
                $adjustmentErrors[] = 'linha '.$line.': coluna '.$column.' deve conter um número inteiro de dias.';
            } else {
                $adjustments[$column] = (int) $value;
            }
        }

        return [
            'line' => $line,
            'pis' => ltrim((string) preg_replace('/\D/', '', (string) $this->value($main, 'A'.$line)), '0'),
            'vt_days' => is_string($vtDays) && strtoupper(trim($vtDays)) === 'N' ? null : $this->days($vtDays),
            'vr_days' => $this->days($this->value($main, 'H'.$line)),
            'vd_days' => $this->days($this->value($main, 'I'.$line)),
            'routes' => $routes,
            'adjustments' => $adjustments,
            'vt_cents' => $this->cents($this->value($main, 'AA'.$line)),
            'vr_vd_cents' => $this->cents($this->value($main, 'AB'.$line)),
            'errors' => [...$routeErrors, ...$adjustmentErrors],
        ];
    }

    /**
     * Recalcula dias e valores a partir das colunas de ajuste e dos trechos e confere com
     * os resultados da própria planilha (G/H/I e AA/AB).
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $farePrices
     * @param  array{vr: int, vd: int}  $rateCents
     * @return list<string>
     */
    private function validateRow(array $row, int $businessDays, array $farePrices, array $rateCents): array
    {
        if ($row['errors'] !== []) {
            return $row['errors'];
        }

        $errors = [];
        $line = $row['line'];
        $days = ['vt' => $businessDays, 'vr' => $businessDays, 'vd' => $businessDays];

        foreach ($row['adjustments'] as $column => $quantity) {
            foreach (self::ADJUSTMENT_COLUMNS[$column][1] as $type => $unit) {
                $days[$type] += $unit * $quantity;
            }
        }

        foreach (['vt' => 'G', 'vr' => 'H', 'vd' => 'I'] as $type => $column) {
            $sheetDays = $row[$type.'_days'];

            if ($type === 'vt' && $sheetDays === null) {
                continue;
            }

            if ($sheetDays !== $days[$type]) {
                $errors[] = 'linha '.$line.': a coluna '.$column.' informa '.var_export($sheetDays, true).' dias, mas as colunas de ajuste resultam em '.$days[$type].'.';
            }
        }

        if ($row['vt_days'] !== null && $row['routes'] === []) {
            $errors[] = 'linha '.$line.': funcionário com VT sem trechos na aba "'.self::FARES_SHEET.'".';
        }

        if ($errors !== []) {
            return $errors;
        }

        $dailyVtCents = 0;

        foreach ($row['routes'] as $column => $tripsPerDay) {
            $dailyVtCents += $farePrices[$column] * $tripsPerDay;
        }

        $vtCents = $row['vt_days'] === null ? 0 : $days['vt'] * $dailyVtCents;
        $vrVdCents = $days['vr'] * $rateCents['vr'] + $days['vd'] * $rateCents['vd'];

        if ($vtCents !== $row['vt_cents']) {
            $errors[] = 'linha '.$line.': VT recalculado '.Money::fromCents($vtCents).' difere da coluna AA ('.Money::fromCents($row['vt_cents']).').';
        }

        if ($vrVdCents !== $row['vr_vd_cents']) {
            $errors[] = 'linha '.$line.': VR+VD recalculado '.Money::fromCents($vrVdCents).' difere da coluna AB ('.Money::fromCents($row['vr_vd_cents']).').';
        }

        return $errors;
    }

    /**
     * @param  array<string, int>  $farePrices
     * @param  Collection<string, TransportFare>  $fares
     *
     * @throws DomainException
     */
    private function ensureCatalog(array $farePrices, Collection $fares, CarbonImmutable $competence): void
    {
        $errors = [];

        foreach (self::FARE_COLUMNS as $column => [, $catalogName]) {
            $fare = $fares->get($catalogName);
            $price = $fare === null ? null : $this->priceResolver->forDate($fare, $competence);

            if ($price === null) {
                $errors[] = 'Tarifa "'.$catalogName.'" sem preço vigente em '.$competence->format('d/m/Y').'.';
            } elseif (Money::toCents($price->amount) !== $farePrices[$column]) {
                $errors[] = 'Tarifa "'.$catalogName.'": preço do sistema '.Money::fromCents(Money::toCents($price->amount)).' difere da planilha ('.Money::fromCents($farePrices[$column]).').';
            }
        }

        foreach ([BenefitType::Vr, BenefitType::Vd] as $type) {
            if ($this->rateResolver->forDate($type, $competence) === null) {
                $errors[] = 'Não há valor de '.$type->label().' vigente em '.$competence->format('d/m/Y').'.';
            }
        }

        if ($errors !== []) {
            throw new DomainException("Catálogo incompleto. Execute o BenefitCatalogSeeder:\n- ".implode("\n- ", $errors));
        }
    }

    /**
     * @throws DomainException
     */
    private function openPeriod(CarbonImmutable $competence): BenefitPeriod
    {
        $period = BenefitPeriod::query()->where('competence', $competence->toDateString())->first()
            ?? $this->workflow->createNext($competence);

        if ($period->status === BenefitPeriodStatus::Closed) {
            throw new DomainException('A competência '.$competence->format('m/Y').' está fechada.');
        }

        return $period;
    }

    /**
     * @return Collection<string, Collection<int, Employee>>
     */
    private function employeesByPis(): Collection
    {
        return Employee::query()
            ->get(['id', 'pis', 'name', 'recision_date'])
            ->groupBy(fn (Employee $employee) => ltrim((string) preg_replace('/\D/', '', $employee->pis), '0'));
    }

    private function isEmployeeRow(Worksheet $main, int $line): bool
    {
        return trim((string) $this->value($main, 'B'.$line)) !== '';
    }

    private function value(Worksheet $sheet, string $coordinate): mixed
    {
        $cell = $sheet->getCell($coordinate);

        return $cell->isFormula() ? ($cell->getOldCalculatedValue() ?? $cell->getCalculatedValue()) : $cell->getValue();
    }

    /**
     * A planilha guarda quantidades como número (inclusive float em fórmulas); qualquer
     * outro conteúdo é mantido para ser apontado na validação.
     */
    private function days(mixed $value): mixed
    {
        return is_numeric($value) && (int) $value == $value ? (int) $value : $value;
    }

    private function cents(mixed $value): int
    {
        return Money::toCents(number_format((float) $value, 2, '.', ''));
    }
}
