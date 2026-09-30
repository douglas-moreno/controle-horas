<?php

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\BenefitType;
use App\Models\BenefitCalculation;
use App\Models\BenefitPeriod;
use App\Models\BenefitRate;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\Holiday;
use App\Models\TransportFare;
use App\Models\TransportFarePrice;
use App\Models\TransportRoute;
use App\Models\User;
use App\Services\BenefitAdjustmentRegistrar;
use App\Services\BenefitPeriodWorkflow;
use App\Services\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Golden Dataset — planilha "VT e VR -09.2026.xlsx", aba "Controle VT-VR" (plano §9.5).
 *
 * Competência 09/2026: 21 dias-base (07/09 feriado). Janela de eventos realizados:
 * agosto/2026 (21 dias úteis, sem feriado). VR 27,50 e VD 7,50 desde 01/09/2026.
 * Preços das tarifas: linha 4 da aba "Vale transp calc". Funcionários anonimizados
 * pela linha da planilha. Colunas: K falta injustificada, L sábados, W atestado,
 * X férias, Y desconto somente de VT.
 */
const GOLDEN_FARES = [
    'CPTM' => '5.40',
    'CMT BOM' => '5.90',
    '372' => '4.55',
    'BR7' => '5.95',
    'MUN MAUÁ' => '5.90',
    'SP' => '8.40',
    'RIB.PIRES' => '6.40',
    'UNI ABC' => '5.90',
    'TROLEBUS' => '6.35',
    'ETC DIADEMA' => '4.50',
    'INTEGRAÇÃO 1,40' => '1.40',
    'SP TRANS' => '10.32',
];

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
    BenefitRate::factory()->vr('27.50')->validFrom('2026-09-01')->create();
    BenefitRate::factory()->vd('7.50')->validFrom('2026-09-01')->create();

    $this->fares = collect(GOLDEN_FARES)->map(function (string $amount, string $name) {
        $fare = TransportFare::factory()->create(['name' => $name]);
        TransportFarePrice::factory()->for($fare)->amount($amount)->validFrom('2026-09-01')->create();

        return $fare;
    });

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

test('golden scenario', function (int $row, array $routes, array $columns, array $expected) {
    $employee = goldenEmployee($row, $routes, $columns);

    $result = app(BenefitPeriodWorkflow::class)->calculate($this->period);
    $calculations = goldenResult($employee);

    expect($result['issues'])->toBe([])
        ->and($this->period->fresh()->business_days)->toBe(21);

    if ($expected['vt'] === null) {
        expect($calculations)->not->toHaveKey('vt');
    } else {
        expect($calculations['vt']->final_days)->toBe($expected['days_vt'] ?? $expected['days'])
            ->and($calculations['vt']->total_amount)->toBe($expected['vt']);
    }

    expect($calculations['vr']->final_days)->toBe($expected['days'])
        ->and($calculations['vd']->final_days)->toBe($expected['days']);

    if (isset($expected['vr'])) {
        expect($calculations['vr']->total_amount)->toBe($expected['vr'])
            ->and($calculations['vd']->total_amount)->toBe($expected['vd']);
    }

    if (isset($expected['vr_vd'])) {
        expect(Money::fromCents(Money::toCents($calculations['vr']->total_amount) + Money::toCents($calculations['vd']->total_amount)))->toBe($expected['vr_vd']);
    }
})->with([
    'G1 (linha 5)' => [5, [], [], ['days' => 21, 'vt' => null, 'vr' => '577.50', 'vd' => '157.50']],
    'G2 (linha 6)' => [6, ['CPTM' => 2, 'SP' => 2], ['K' => 1, 'L' => 2], ['days' => 22, 'vt' => '607.20', 'vr_vd' => '770.00']],
    'G3 (linha 8)' => [8, ['CPTM' => 2, '372' => 2], [], ['days' => 21, 'vt' => '417.90']],
    'G4 (linha 13)' => [13, ['UNI ABC' => 2, 'TROLEBUS' => 1, 'ETC DIADEMA' => 1, 'INTEGRAÇÃO 1,40' => 1], ['L' => 3], ['days' => 24, 'vt' => '577.20', 'vr_vd' => '840.00']],
    'G5 (linha 10)' => [10, ['CPTM' => 2, 'MUN MAUÁ' => 2], ['W' => 12], ['days' => 9, 'vt' => '203.40', 'vr_vd' => '315.00']],
    'G6 (linha 15)' => [15, ['CPTM' => 2, 'RIB.PIRES' => 2], ['L' => 4, 'W' => 2], ['days' => 23, 'vt' => '542.80']],
    'G7 (linha 17)' => [17, ['CPTM' => 2, 'BR7' => 2, 'TROLEBUS' => 2], ['W' => 1], ['days' => 20, 'vt' => '708.00']],
    'G8 (linha 26)' => [26, ['UNI ABC' => 4], [], ['days' => 21, 'vt' => '495.60']],
    'G9 (linha 31)' => [31, ['CMT BOM' => 4, 'TROLEBUS' => 2], ['L' => 2, 'W' => 5], ['days' => 18, 'vt' => '653.40', 'vr_vd' => '630.00']],
    'G10 (linha 33)' => [33, ['SP TRANS' => 2], [], ['days' => 21, 'vt' => '433.44']],
    'G11 (linha 39)' => [39, ['CPTM' => 2, 'SP' => 2, 'UNI ABC' => 2], ['X' => 21], ['days' => 0, 'vt' => '0.00', 'vr' => '0.00', 'vd' => '0.00']],
    'G12 (linha 41)' => [41, ['CPTM' => 2], ['Y' => 3], ['days' => 21, 'days_vt' => 18, 'vt' => '194.40', 'vr_vd' => '735.00']],
]);

test('G11 keeps zero-day calculations distinct from non eligibility', function () {
    $vacation = goldenEmployee(39, ['CPTM' => 2, 'SP' => 2, 'UNI ABC' => 2], ['X' => 21]);
    $withoutVt = goldenEmployee(5, [], []);

    app(BenefitPeriodWorkflow::class)->calculate($this->period);

    expect(goldenResult($vacation))->toHaveKeys(['vt', 'vr', 'vd'])
        ->and(goldenResult($vacation)['vt']->carried_out_days)->toBe(0)
        ->and(goldenResult($vacation)['vt']->unit_amount)->toBe('39.40')
        ->and(goldenResult($withoutVt))->not->toHaveKey('vt');
});
