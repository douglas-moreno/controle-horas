<?php

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\AdjustmentStatus;
use App\Enums\BenefitPeriodStatus;
use App\Enums\BenefitType;
use App\Models\BenefitAdjustment;
use App\Models\BenefitAdjustmentImpact;
use App\Models\BenefitCalculation;
use App\Models\BenefitCalculationTransportItem;
use App\Models\BenefitPeriod;
use App\Models\BenefitPeriodEmployee;
use App\Models\BenefitPeriodStatusChange;
use App\Models\BenefitRate;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\TransportFarePrice;
use App\Models\TransportRoute;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('enum columns are cast to the domain enums', function () {
    $adjustment = BenefitAdjustment::factory()->pending()->reason(AdjustmentReason::Vacation)->source(AdjustmentSource::Hr)->create();
    $impact = BenefitAdjustmentImpact::factory()->ofType(BenefitType::Vd, -3)->create();
    $period = BenefitPeriod::factory()->calculated()->create();
    $change = BenefitPeriodStatusChange::factory()->create([
        'from_status' => BenefitPeriodStatus::Open,
        'to_status' => BenefitPeriodStatus::Calculated,
    ]);

    expect($adjustment->fresh()->reason)->toBe(AdjustmentReason::Vacation)
        ->and($adjustment->fresh()->source)->toBe(AdjustmentSource::Hr)
        ->and($adjustment->fresh()->status)->toBe(AdjustmentStatus::Pending)
        ->and($impact->fresh()->benefit_type)->toBe(BenefitType::Vd)
        ->and($impact->fresh()->quantity)->toBe(-3)
        ->and($period->fresh()->status)->toBe(BenefitPeriodStatus::Calculated)
        ->and($change->fresh()->from_status)->toBe(BenefitPeriodStatus::Open)
        ->and($change->fresh()->to_status)->toBe(BenefitPeriodStatus::Calculated);

    expect(DB::table('benefit_adjustments')->value('reason'))->toBe('vacation')
        ->and(DB::table('benefit_adjustment_impacts')->value('benefit_type'))->toBe('vd');
});

test('domain dates are stored as plain dates and read as immutable dates', function () {
    $benefit = EmployeeBenefit::factory()->between('2026-10-01', '2026-12-31')->create();
    $period = BenefitPeriod::factory()->create(['competence' => CarbonImmutable::parse('2026-10-01 15:30:00')]);
    BenefitAdjustment::factory()->for($period)->vacation('2026-10-05T00:00:00-03:00', '2026-10-09', 5)->create();

    expect(DB::table('employee_benefits')->first(['starts_on', 'ends_on']))
        ->toEqual((object) ['starts_on' => '2026-10-01', 'ends_on' => '2026-12-31'])
        ->and(DB::table('benefit_periods')->where('id', $period->id)->value('competence'))->toBe('2026-10-01')
        ->and(DB::table('benefit_adjustments')->first(['starts_on', 'ends_on']))
        ->toEqual((object) ['starts_on' => '2026-10-05', 'ends_on' => '2026-10-09']);

    expect($benefit->fresh()->starts_on)->toBeInstanceOf(CarbonImmutable::class)
        ->and($benefit->fresh()->starts_on->toDateString())->toBe('2026-10-01')
        ->and($benefit->fresh()->ends_on->toDateString())->toBe('2026-12-31');
});

test('an open ended validity keeps a null end date', function () {
    $route = TransportRoute::factory()->between('2026-10-15')->create();

    expect($route->fresh()->ends_on)->toBeNull();
});

test('validity comparisons by date work on the stored value', function () {
    $fare = TransportFarePrice::factory()->validFrom('2026-10-01')->create()->transportFare;
    TransportFarePrice::factory()->for($fare)->validFrom('2026-10-15')->create();

    $validOnFirstDay = TransportFarePrice::query()
        ->where('transport_fare_id', $fare->id)
        ->where('valid_from', '<=', '2026-10-01')
        ->count();

    expect($validOnFirstDay)->toBe(1);
});

test('money columns keep exact two decimal strings', function () {
    $rate = BenefitRate::factory()->vr('27.5')->create();
    $price = TransportFarePrice::factory()->amount('10.32')->create();
    $calculation = BenefitCalculation::factory()->create(['unit_amount' => '27.60', 'total_amount' => '45000.84']);
    $item = BenefitCalculationTransportItem::factory()->create(['fare_amount' => '10.32', 'daily_amount' => '20.64']);

    expect($rate->fresh()->amount)->toBe('27.50')
        ->and($price->fresh()->amount)->toBe('10.32')
        ->and($calculation->fresh()->unit_amount)->toBe('27.60')
        ->and($calculation->fresh()->total_amount)->toBe('45000.84')
        ->and($item->fresh()->fare_amount)->toBe('10.32')
        ->and($item->fresh()->daily_amount)->toBe('20.64');
});

test('period employee snapshot copies the employee data', function () {
    $employee = Employee::factory()->create(['name' => 'Funcionário Snapshot', 'position' => 'Soldador']);

    $periodEmployee = BenefitPeriodEmployee::factory()->for($employee)->create();

    expect($periodEmployee->only('employee_name', 'pis', 'position'))->toBe([
        'employee_name' => 'Funcionário Snapshot',
        'pis' => $employee->pis,
        'position' => 'Soldador',
    ]);
});

test('status changes only record the creation time', function () {
    $change = BenefitPeriodStatusChange::factory()->create();

    expect($change->fresh()->created_at)->not->toBeNull()
        ->and($change->fresh()->getAttributes())->not->toHaveKey('updated_at');
});

test('basic relationships navigate the module', function () {
    $employee = Employee::factory()->create();
    $period = BenefitPeriod::factory()->create();
    $adjustment = BenefitAdjustment::factory()->for($employee)->for($period)->create();
    BenefitAdjustmentImpact::factory()->for($adjustment)->ofType(BenefitType::Vt, -1)->create();
    $replacement = BenefitAdjustment::factory()->for($employee)->for($period)->create(['related_adjustment_id' => $adjustment->id]);
    $route = TransportRoute::factory()->for($employee)->create();
    TransportFarePrice::factory()->for($route->transportFare)->create();
    $periodEmployee = BenefitPeriodEmployee::factory()->for($employee)->for($period)->create();
    $calculation = BenefitCalculation::factory()->for($periodEmployee)->ofType(BenefitType::Vt)->create();
    BenefitCalculationTransportItem::factory()->for($calculation, 'calculation')->create(['transport_route_id' => $route->id]);

    expect($period->adjustments)->toHaveCount(2)
        ->and($period->periodEmployees->first()->is($periodEmployee))->toBeTrue()
        ->and($adjustment->impacts)->toHaveCount(1)
        ->and($replacement->relatedAdjustment->is($adjustment))->toBeTrue()
        ->and($adjustment->relatedAdjustments->first()->is($replacement))->toBeTrue()
        ->and($route->transportFare->prices)->toHaveCount(1)
        ->and($route->transportFare->routes->first()->is($route))->toBeTrue()
        ->and($periodEmployee->calculations->first()->is($calculation))->toBeTrue()
        ->and($calculation->transportItems->first()->transportRoute->is($route))->toBeTrue()
        ->and($employee->transportRoutes)->toHaveCount(1)
        ->and($employee->benefitAdjustments)->toHaveCount(2)
        ->and($employee->benefitPeriodEmployees)->toHaveCount(1);
});

test('a calculation knows where its balance came from and where it went', function () {
    $origin = BenefitCalculation::factory()->ofType(BenefitType::Vt)->withCarriedOut(2)->create();
    $applied = BenefitCalculation::factory()->carriedFrom($origin)->create();

    expect($applied->carriedFromCalculation->is($origin))->toBeTrue()
        ->and($origin->carriedToCalculation->is($applied))->toBeTrue()
        ->and($applied->benefit_type)->toBe(BenefitType::Vt)
        ->and($applied->carried_in_days)->toBe(2);
});
