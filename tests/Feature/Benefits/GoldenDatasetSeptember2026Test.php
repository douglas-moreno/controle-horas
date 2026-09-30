<?php

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\BenefitType;
use App\Models\BenefitCalculation;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\Holiday;
use App\Models\TransportFare;
use App\Models\TransportRoute;
use App\Models\User;
use App\Services\BenefitAdjustmentRegistrar;
use App\Services\BenefitPeriodWorkflow;
use App\Services\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\BenefitCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Golden Dataset — planilha "VT e VR -09.2026.xlsx", aba "Controle VT-VR" (plano §9.5).
 *
 * Competência 09/2026: 21 dias-base (07/09 feriado). Janela de eventos realizados:
 * agosto/2026 (21 dias úteis, sem feriado). Catálogo (20 tarifas da linha 4 da aba
 * "Vale transp calc", VR 27,50 e VD 7,50 desde 01/09/2026) carregado pelo
 * BenefitCatalogSeeder. Funcionários anonimizados pela linha da planilha. Colunas:
 * K falta injustificada, L sábados, W atestado, X férias, Y desconto somente de VT.
 *
 * Cada cenário: [linha, tarifa => viagens/dia (vazio = sem VT), colunas de ajuste,
 * esperado (dias e valores de VT/VR/VD; VT null = não elegível)].
 *
 * @return array<string, array{int, array<string, int>, array<string, int>, array{days_vt: ?int, vt: ?string, days: int, vr: string, vd: string}}>
 */
function goldenScenarios(): array
{
    return [
        'G1 (linha 5)' => [5, [], [], ['days_vt' => null, 'vt' => null, 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G2 (linha 6)' => [6, ['CPTM' => 2, 'SP' => 2], ['K' => 1, 'L' => 2], ['days_vt' => 22, 'vt' => '607.20', 'days' => 22, 'vr' => '605.00', 'vd' => '165.00']],
        'G13 (linha 7)' => [7, [], [], ['days_vt' => null, 'vt' => null, 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G3 (linha 8)' => [8, ['CPTM' => 2, '372' => 2], [], ['days_vt' => 21, 'vt' => '417.90', 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G14 (linha 9)' => [9, ['CPTM' => 2, 'SZT MAUÁ' => 2], ['L' => 2], ['days_vt' => 23, 'vt' => '519.80', 'days' => 23, 'vr' => '632.50', 'vd' => '172.50']],
        'G5 (linha 10)' => [10, ['CPTM' => 2, 'MUN MAUÁ' => 2], ['W' => 12], ['days_vt' => 9, 'vt' => '203.40', 'days' => 9, 'vr' => '247.50', 'vd' => '67.50']],
        'G15 (linha 11)' => [11, [], ['L' => 2], ['days_vt' => null, 'vt' => null, 'days' => 23, 'vr' => '632.50', 'vd' => '172.50']],
        'G16 (linha 12)' => [12, [], [], ['days_vt' => null, 'vt' => null, 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G4 (linha 13)' => [13, ['UNI ABC' => 2, 'TROLEBUS' => 1, 'ETC DIADEMA' => 1, 'INTEGRAÇÃO 1,40' => 1], ['L' => 3], ['days_vt' => 24, 'vt' => '577.20', 'days' => 24, 'vr' => '660.00', 'vd' => '180.00']],
        'G17 (linha 14)' => [14, ['CPTM' => 2], [], ['days_vt' => 21, 'vt' => '226.80', 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G6 (linha 15)' => [15, ['CPTM' => 2, 'RIB.PIRES' => 2], ['L' => 4, 'W' => 2], ['days_vt' => 23, 'vt' => '542.80', 'days' => 23, 'vr' => '632.50', 'vd' => '172.50']],
        'G18 (linha 16)' => [16, ['CPTM' => 2, 'SZT MAUÁ' => 2], ['L' => 2], ['days_vt' => 23, 'vt' => '519.80', 'days' => 23, 'vr' => '632.50', 'vd' => '172.50']],
        'G7 (linha 17)' => [17, ['CPTM' => 2, 'BR7' => 2, 'TROLEBUS' => 2], ['W' => 1], ['days_vt' => 20, 'vt' => '708.00', 'days' => 20, 'vr' => '550.00', 'vd' => '150.00']],
        'G19 (linha 18)' => [18, ['CPTM' => 2, 'MUN MAUÁ' => 2], [], ['days_vt' => 21, 'vt' => '474.60', 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G20 (linha 19)' => [19, ['UNI ABC' => 2], [], ['days_vt' => 21, 'vt' => '247.80', 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G21 (linha 20)' => [20, ['CPTM' => 2, 'RIB.PIRES' => 2], ['L' => 3, 'W' => 2], ['days_vt' => 22, 'vt' => '519.20', 'days' => 22, 'vr' => '605.00', 'vd' => '165.00']],
        'G22 (linha 21)' => [21, [], ['L' => 1], ['days_vt' => null, 'vt' => null, 'days' => 22, 'vr' => '605.00', 'vd' => '165.00']],
        'G23 (linha 22)' => [22, ['CPTM' => 2, 'Rio Grande -Talismã' => 2], ['L' => 2], ['days_vt' => 23, 'vt' => '519.80', 'days' => 23, 'vr' => '632.50', 'vd' => '172.50']],
        'G24 (linha 23)' => [23, [], ['L' => 2], ['days_vt' => null, 'vt' => null, 'days' => 23, 'vr' => '632.50', 'vd' => '172.50']],
        'G25 (linha 24)' => [24, ['CPTM' => 2, 'SZT MAUÁ' => 2], ['L' => 2, 'W' => 1], ['days_vt' => 22, 'vt' => '497.20', 'days' => 22, 'vr' => '605.00', 'vd' => '165.00']],
        'G26 (linha 25)' => [25, ['CPTM' => 2, 'SZT MAUÁ' => 2], [], ['days_vt' => 21, 'vt' => '474.60', 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G8 (linha 26)' => [26, ['UNI ABC' => 4], [], ['days_vt' => 21, 'vt' => '495.60', 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G27 (linha 27)' => [27, ['CPTM' => 2, 'SP' => 2], ['L' => 2], ['days_vt' => 23, 'vt' => '634.80', 'days' => 23, 'vr' => '632.50', 'vd' => '172.50']],
        'G28 (linha 28)' => [28, ['CPTM' => 2, 'RIB.PIRES' => 2], [], ['days_vt' => 21, 'vt' => '495.60', 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G29 (linha 29)' => [29, ['CPTM' => 2, 'RIB.PIRES' => 2], [], ['days_vt' => 21, 'vt' => '495.60', 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G30 (linha 30)' => [30, ['CPTM' => 2, 'ETC MAUÁ' => 2], ['W' => 11], ['days_vt' => 10, 'vt' => '226.00', 'days' => 10, 'vr' => '275.00', 'vd' => '75.00']],
        'G9 (linha 31)' => [31, ['CMT BOM 2' => 4, 'TROLEBUS' => 2], ['L' => 2, 'W' => 5], ['days_vt' => 18, 'vt' => '653.40', 'days' => 18, 'vr' => '495.00', 'vd' => '135.00']],
        'G31 (linha 32)' => [32, [], [], ['days_vt' => null, 'vt' => null, 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G10 (linha 33)' => [33, ['SP TRANS' => 2], [], ['days_vt' => 21, 'vt' => '433.44', 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G32 (linha 34)' => [34, ['CPTM' => 2, 'SZT MAUÁ' => 2], ['L' => 2], ['days_vt' => 23, 'vt' => '519.80', 'days' => 23, 'vr' => '632.50', 'vd' => '172.50']],
        'G33 (linha 35)' => [35, ['CMT BOM 2' => 2, 'UNI ABC' => 2, 'TROLEBUS' => 2], [], ['days_vt' => 21, 'vt' => '762.30', 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G34 (linha 36)' => [36, ['CPTM' => 2, 'ETC MAUÁ' => 2], ['L' => 2], ['days_vt' => 23, 'vt' => '519.80', 'days' => 23, 'vr' => '632.50', 'vd' => '172.50']],
        'G35 (linha 37)' => [37, ['CPTM' => 2, 'UNI ABC' => 2], ['W' => 2], ['days_vt' => 19, 'vt' => '429.40', 'days' => 19, 'vr' => '522.50', 'vd' => '142.50']],
        'G36 (linha 38)' => [38, ['CPTM' => 2, 'INT TRILHOS' => 2], ['L' => 1], ['days_vt' => 22, 'vt' => '530.20', 'days' => 22, 'vr' => '605.00', 'vd' => '165.00']],
        'G11 (linha 39)' => [39, ['UNI ABC' => 2, 'TROLEBUS' => 2, 'RGS TLM' => 2], ['X' => 21], ['days_vt' => 0, 'vt' => '0.00', 'days' => 0, 'vr' => '0.00', 'vd' => '0.00']],
        'G37 (linha 40)' => [40, [], [], ['days_vt' => null, 'vt' => null, 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G12 (linha 41)' => [41, ['CPTM' => 2], ['Y' => 3], ['days_vt' => 18, 'vt' => '194.40', 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G38 (linha 42)' => [42, ['UNI ABC' => 4], [], ['days_vt' => 21, 'vt' => '495.60', 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G39 (linha 43)' => [43, ['CPTM' => 2, 'RGS' => 2], [], ['days_vt' => 21, 'vt' => '474.60', 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G40 (linha 44)' => [44, ['UNI ABC' => 4], ['L' => 1], ['days_vt' => 22, 'vt' => '519.20', 'days' => 22, 'vr' => '605.00', 'vd' => '165.00']],
        'G41 (linha 45)' => [45, [], [], ['days_vt' => null, 'vt' => null, 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
        'G42 (linha 46)' => [46, [], [], ['days_vt' => null, 'vt' => null, 'days' => 21, 'vr' => '577.50', 'vd' => '157.50']],
    ];
}

/**
 * Dias úteis de agosto/2026 em ordem, usados para lançar os ajustes da janela.
 */
const GOLDEN_AUGUST_BUSINESS_DAYS = [
    '2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07',
    '2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13', '2026-08-14',
    '2026-08-17', '2026-08-18', '2026-08-19', '2026-08-20', '2026-08-21',
    '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28',
    '2026-08-31',
];

const GOLDEN_AUGUST_SATURDAYS = ['2026-08-01', '2026-08-08', '2026-08-15', '2026-08-22', '2026-08-29'];

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Holiday::factory()->create(['date' => '2026-09-07', 'description' => 'Independência']);
    $this->seed(BenefitCatalogSeeder::class);

    $this->fares = TransportFare::query()->get()->keyBy('name');
    $this->period = BenefitPeriod::factory()->forCompetence('2026-09')->create();
    $this->registrar = app(BenefitAdjustmentRegistrar::class);
});

/**
 * @param  array<string, int>  $routes  tarifa => viagens por dia (vazio = sem VT)
 * @param  array{K?: int, L?: int, W?: int, X?: int, Y?: int}  $columns
 */
function goldenEmployee(int $row, array $routes, array $columns): Employee
{
    $employee = Employee::factory()->create(['name' => 'Funcionário L'.str_pad((string) $row, 2, '0', STR_PAD_LEFT)]);
    $types = $routes === [] ? [BenefitType::Vr, BenefitType::Vd] : BenefitType::cases();

    foreach ($types as $type) {
        EmployeeBenefit::factory()->for($employee)->ofType($type)->between('2026-01-01')->create();
    }

    foreach ($routes as $fareName => $trips) {
        TransportRoute::factory()->for($employee)->for(test()->fares[$fareName])->tripsPerDay($trips)->between('2026-01-01')->create();
    }

    $register = fn (AdjustmentReason $reason, string $startsOn, string $endsOn, ?string $notes = null, array $impacts = []) => test()->registrar->register(
        test()->period, $employee, $reason, AdjustmentSource::Manual, CarbonImmutable::parse($startsOn), CarbonImmutable::parse($endsOn), $notes, $impacts,
    );

    if ($days = $columns['K'] ?? 0) {
        $register(AdjustmentReason::UnjustifiedAbsence, GOLDEN_AUGUST_BUSINESS_DAYS[20 - $days + 1], GOLDEN_AUGUST_BUSINESS_DAYS[20]);
    }

    foreach (array_slice(GOLDEN_AUGUST_SATURDAYS, 0, $columns['L'] ?? 0) as $saturday) {
        $register(AdjustmentReason::SaturdayWorked, $saturday, $saturday);
    }

    if ($days = $columns['W'] ?? 0) {
        $register(AdjustmentReason::MedicalCertificate, GOLDEN_AUGUST_BUSINESS_DAYS[0], GOLDEN_AUGUST_BUSINESS_DAYS[$days - 1]);
    }

    if ($days = $columns['X'] ?? 0) {
        $register(AdjustmentReason::Vacation, GOLDEN_AUGUST_BUSINESS_DAYS[0], GOLDEN_AUGUST_BUSINESS_DAYS[$days - 1]);
    }

    if ($days = $columns['Y'] ?? 0) {
        $register(AdjustmentReason::Manual, '2026-08-31', '2026-08-31', 'Pago a maior / desconto VT.', ['vt' => -$days]);
    }

    return $employee;
}

/**
 * @return array<string, BenefitCalculation>
 */
function goldenResult(Employee $employee): array
{
    return BenefitCalculation::query()
        ->whereHas('benefitPeriodEmployee', fn ($query) => $query->where('benefit_period_id', test()->period->id)->where('employee_id', $employee->id))
        ->get()
        ->keyBy(fn (BenefitCalculation $calculation) => $calculation->benefit_type->value)
        ->all();
}

/**
 * @param  array{days_vt: ?int, vt: ?string, days: int, vr: string, vd: string}  $expected
 */
function assertGoldenResult(Employee $employee, array $expected): void
{
    $calculations = goldenResult($employee);

    if ($expected['vt'] === null) {
        expect($calculations)->not->toHaveKey('vt');
    } else {
        expect($calculations['vt']->final_days)->toBe($expected['days_vt'])
            ->and($calculations['vt']->total_amount)->toBe($expected['vt']);
    }

    expect($calculations['vr']->final_days)->toBe($expected['days'])
        ->and($calculations['vr']->total_amount)->toBe($expected['vr'])
        ->and($calculations['vd']->final_days)->toBe($expected['days'])
        ->and($calculations['vd']->total_amount)->toBe($expected['vd']);
}

test('golden scenario', function (int $row, array $routes, array $columns, array $expected) {
    $employee = goldenEmployee($row, $routes, $columns);

    $result = app(BenefitPeriodWorkflow::class)->calculate($this->period);

    expect($result['issues'])->toBe([])
        ->and($this->period->fresh()->business_days)->toBe(21);

    assertGoldenResult($employee, $expected);
})->with(goldenScenarios());

test('G-ALL reproduces every row and the spreadsheet totals (AA47, AB47, AD47)', function () {
    $employees = collect(goldenScenarios())->map(fn (array $scenario) => goldenEmployee($scenario[0], $scenario[1], $scenario[2]));

    $result = app(BenefitPeriodWorkflow::class)->calculate($this->period);

    expect($result['issues'])->toBe([])
        ->and($this->period->fresh()->business_days)->toBe(21);

    foreach (goldenScenarios() as $label => $scenario) {
        assertGoldenResult($employees[$label], $scenario[3]);
    }

    $centsByType = BenefitCalculation::query()
        ->whereHas('benefitPeriodEmployee', fn ($query) => $query->where('benefit_period_id', $this->period->id))
        ->get()
        ->groupBy(fn (BenefitCalculation $calculation) => $calculation->benefit_type->value)
        ->map(fn ($calculations) => $calculations->sum(fn (BenefitCalculation $calculation) => Money::toCents($calculation->total_amount)));

    expect($this->period->periodEmployees()->count())->toBe(42)
        ->and(BenefitCalculation::query()->where('benefit_type', BenefitType::Vt)->count())->toBe(32)
        ->and(Money::fromCents($centsByType['vt']))->toBe('14935.84')
        ->and(Money::fromCents($centsByType['vr'] + $centsByType['vd']))->toBe('30065.00')
        ->and(Money::fromCents($centsByType->sum()))->toBe('45000.84');
});

test('G11 keeps zero-day calculations distinct from non eligibility', function () {
    $vacation = goldenEmployee(39, ['UNI ABC' => 2, 'TROLEBUS' => 2, 'RGS TLM' => 2], ['X' => 21]);
    $withoutVt = goldenEmployee(5, [], []);

    app(BenefitPeriodWorkflow::class)->calculate($this->period);

    expect(goldenResult($vacation))->toHaveKeys(['vt', 'vr', 'vd'])
        ->and(goldenResult($vacation)['vt']->carried_out_days)->toBe(0)
        ->and(goldenResult($vacation)['vt']->unit_amount)->toBe('35.10')
        ->and(goldenResult($withoutVt))->not->toHaveKey('vt');
});
