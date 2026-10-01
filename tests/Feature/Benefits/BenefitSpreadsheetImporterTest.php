<?php

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentStatus;
use App\Enums\BenefitPeriodStatus;
use App\Enums\BenefitType;
use App\Models\BenefitAdjustment;
use App\Models\BenefitCalculation;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\Holiday;
use App\Models\TransportFarePrice;
use App\Models\TransportRoute;
use App\Models\User;
use App\Services\BenefitPeriodWorkflow;
use App\Services\BenefitSpreadsheetImporter;
use Carbon\CarbonImmutable;
use Database\Seeders\BenefitCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

/**
 * Planilha sintética no formato de "VT e VR -09.2026.xlsx" (dados fictícios).
 *
 * @param  list<array{pis: string|int|null, G: int|string, H: int, I: int, AA: float, AB: float, routes?: array<string, int>, columns?: array<string, int>}>  $rows
 */
function spreadsheetFixture(array $rows, int $businessDays = 21, array $priceOverrides = []): string
{
    $spreadsheet = new Spreadsheet;
    $main = $spreadsheet->getActiveSheet()->setTitle(BenefitSpreadsheetImporter::MAIN_SHEET);
    $fares = $spreadsheet->createSheet()->setTitle(BenefitSpreadsheetImporter::FARES_SHEET);

    $main->setCellValue('B2', ExcelDate::PHPToExcel(new DateTime('2026-09-01')));
    $main->setCellValue('N2', $businessDays);

    $catalogPrices = array_values(BenefitCatalogSeeder::FARES);

    foreach (array_keys(BenefitSpreadsheetImporter::FARE_COLUMNS) as $index => $column) {
        $fares->setCellValue($column.'3', BenefitSpreadsheetImporter::FARE_COLUMNS[$column][0]);
        $fares->setCellValue($column.'4', (float) ($priceOverrides[$column] ?? $catalogPrices[$index]));
    }

    foreach ($rows as $index => $row) {
        $line = BenefitSpreadsheetImporter::FIRST_ROW + $index;

        $main->setCellValue('A'.$line, $row['pis']);
        $main->setCellValue('B'.$line, 'Funcionário '.$line);

        foreach (['G', 'H', 'I', 'AA', 'AB'] as $column) {
            $main->setCellValue($column.$line, $row[$column]);
        }

        foreach ($row['columns'] ?? [] as $column => $quantity) {
            $main->setCellValue($column.$line, $quantity);
        }

        foreach ($row['routes'] ?? [] as $column => $tripsPerDay) {
            $fares->setCellValue($column.$line, '=$X'.$line.'*'.$tripsPerDay);
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'vtvr').'.xlsx';
    $writer = new Xlsx($spreadsheet);
    $writer->setPreCalculateFormulas(false);
    $writer->save($path);

    return $path;
}

/**
 * Linha 5: sem VT. Linha 6: CPTM×2 + SP×2, K=1, L=2 (PIS salvo com zero à esquerda).
 * Linha 7: CPTM×2, Y=3 (PIS repetido em funcionário rescindido). Linha 8: PIS desconhecido.
 *
 * @return list<array<string, mixed>>
 */
function defaultSpreadsheetRows(): array
{
    return [
        ['pis' => 11111111111, 'G' => 'N', 'H' => 21, 'I' => 21, 'AA' => 0, 'AB' => 735],
        ['pis' => 2222222222, 'G' => 22, 'H' => 22, 'I' => 22, 'AA' => 607.2, 'AB' => 770, 'routes' => ['C' => 2, 'J' => 2], 'columns' => ['K' => 1, 'L' => 2]],
        ['pis' => 33333333333, 'G' => 18, 'H' => 21, 'I' => 21, 'AA' => 194.4, 'AB' => 735, 'routes' => ['C' => 2], 'columns' => ['Y' => 3]],
        ['pis' => 99999999999, 'G' => 'N', 'H' => 21, 'I' => 21, 'AA' => 0, 'AB' => 735],
    ];
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Holiday::factory()->create(['date' => '2026-09-07', 'description' => 'Independência']);
    $this->seed(BenefitCatalogSeeder::class);

    $this->withoutVt = Employee::factory()->create(['pis' => '11111111111']);
    $this->withAdjustments = Employee::factory()->create(['pis' => '02222222222']);
    Employee::factory()->terminated('2025-09-29')->create(['pis' => '33333333333']);
    $this->rehired = Employee::factory()->create(['pis' => '33333333333']);

    $this->importer = app(BenefitSpreadsheetImporter::class);
});

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/vtvr*') as $file) {
        @unlink($file);
    }
});

test('imports eligibility, routes and adjustments matched by PIS', function () {
    $report = $this->importer->import(spreadsheetFixture(defaultSpreadsheetRows()));

    expect($report['competence'])->toBe('09/2026')
        ->and($report['imported'])->toBe([5, 6, 7])
        ->and($report['skipped'])->toBe([['line' => 8, 'reason' => 'PIS não encontrado no cadastro de funcionários.']])
        ->and($report['benefits'])->toBe(8)
        ->and($report['routes'])->toBe(3)
        ->and($report['adjustments'])->toBe(3)
        ->and($report['expected_vt_cents'])->toBe(80160)
        ->and($report['expected_vr_vd_cents'])->toBe(224000);

    expect(EmployeeBenefit::query()->whereBelongsTo($this->withoutVt)->pluck('benefit_type')->all())->toEqualCanonicalizing([BenefitType::Vr, BenefitType::Vd])
        ->and(EmployeeBenefit::query()->whereBelongsTo($this->rehired)->count())->toBe(3)
        ->and(EmployeeBenefit::query()->where('starts_on', '2026-09-01')->whereNull('ends_on')->count())->toBe(8)
        ->and(TransportRoute::query()->whereBelongsTo($this->withAdjustments)->with('transportFare')->get()->mapWithKeys(fn (TransportRoute $route) => [$route->transportFare->name => $route->trips_per_day])->all())
        ->toBe(['CPTM' => 2, 'SP' => 2]);

    $adjustments = BenefitAdjustment::query()->with('impacts')->get();

    expect($adjustments->every(fn (BenefitAdjustment $adjustment) => $adjustment->reason === AdjustmentReason::Manual
        && $adjustment->status === AdjustmentStatus::Confirmed
        && $adjustment->starts_on->toDateString() === '2026-08-31'))->toBeTrue()
        ->and($adjustments->firstWhere('employee_id', $this->rehired->id)->impacts->mapWithKeys(fn ($impact) => [$impact->benefit_type->value => $impact->quantity])->all())
        ->toBe(['vt' => -3]);
});

test('the imported competence reproduces the spreadsheet', function () {
    $this->importer->import(spreadsheetFixture(defaultSpreadsheetRows()));
    $period = BenefitPeriod::query()->sole();

    expect($period->status)->toBe(BenefitPeriodStatus::Open);

    $result = app(BenefitPeriodWorkflow::class)->calculate($period);

    $totals = fn (Employee $employee) => BenefitCalculation::query()
        ->whereHas('benefitPeriodEmployee', fn ($query) => $query->where('employee_id', $employee->id))
        ->get()
        ->mapWithKeys(fn (BenefitCalculation $calculation) => [$calculation->benefit_type->value => [$calculation->final_days, $calculation->total_amount]])
        ->all();

    expect($result['issues'])->toBe([])
        ->and($totals($this->withoutVt))->toEqual(['vr' => [21, '577.50'], 'vd' => [21, '157.50']])
        ->and($totals($this->withAdjustments))->toEqual(['vt' => [22, '607.20'], 'vr' => [22, '605.00'], 'vd' => [22, '165.00']])
        ->and($totals($this->rehired))->toEqual(['vt' => [18, '194.40'], 'vr' => [21, '577.50'], 'vd' => [21, '157.50']]);
});

test('running twice skips employees already registered', function () {
    $path = spreadsheetFixture(defaultSpreadsheetRows());
    $this->importer->import($path);

    $report = $this->importer->import($path);

    expect($report['imported'])->toBe([])
        ->and(collect($report['skipped'])->pluck('line')->all())->toBe([5, 6, 7, 8])
        ->and(EmployeeBenefit::query()->count())->toBe(8)
        ->and(TransportRoute::query()->count())->toBe(3)
        ->and(BenefitAdjustment::query()->count())->toBe(3)
        ->and(BenefitPeriod::query()->count())->toBe(1);
});

test('nothing is written when a row does not match its own totals', function (array $change, string $message) {
    $rows = defaultSpreadsheetRows();
    $rows[1] = array_replace($rows[1], $change);

    expect(fn () => $this->importer->import(spreadsheetFixture($rows)))->toThrow(DomainException::class, $message);

    expect(EmployeeBenefit::query()->count())->toBe(0)
        ->and(BenefitPeriod::query()->count())->toBe(0);
})->with([
    'days' => [['G' => 23], 'linha 6: a coluna G informa 23 dias, mas as colunas de ajuste resultam em 22.'],
    'VT amount' => [['AA' => 600], 'linha 6: VT recalculado 607.20 difere da coluna AA (600.00).'],
    'VR+VD amount' => [['AB' => 700], 'linha 6: VR+VD recalculado 770.00 difere da coluna AB (700.00).'],
    'leave of absence' => [['columns' => ['K' => 1, 'L' => 2, 'Z' => 1]], 'linha 6: coluna Z (afastamento) preenchida.'],
    'VT without routes' => [['routes' => [], 'AA' => 0], 'linha 6: funcionário com VT sem trechos'],
]);

test('refuses a spreadsheet whose business days differ from the calendar', function () {
    Holiday::query()->delete();

    expect(fn () => $this->importer->import(spreadsheetFixture(defaultSpreadsheetRows())))
        ->toThrow(DomainException::class, 'A planilha informa 21 dias úteis em 09/2026, mas o calendário do sistema tem 22. Confira os feriados cadastrados.');

    expect(EmployeeBenefit::query()->count())->toBe(0);
});

test('refuses a spreadsheet whose fare prices differ from the catalog', function () {
    expect(fn () => $this->importer->import(spreadsheetFixture(defaultSpreadsheetRows(), priceOverrides: ['C' => 5.5])))
        ->toThrow(DomainException::class, 'Tarifa "CPTM": preço do sistema 5.40 difere da planilha (5.50).');

    TransportFarePrice::query()->delete();

    expect(fn () => $this->importer->import(spreadsheetFixture(defaultSpreadsheetRows())))
        ->toThrow(DomainException::class, 'Tarifa "CPTM" sem preço vigente em 01/09/2026.');
});

test('refuses a closed competence', function () {
    $period = app(BenefitPeriodWorkflow::class)->createNext(CarbonImmutable::parse('2026-09-01'));
    app(BenefitPeriodWorkflow::class)->calculate($period);
    app(BenefitPeriodWorkflow::class)->close($period);

    expect(fn () => $this->importer->import(spreadsheetFixture(defaultSpreadsheetRows())))
        ->toThrow(DomainException::class, 'A competência 09/2026 está fechada.');

    expect(EmployeeBenefit::query()->count())->toBe(0);
});
