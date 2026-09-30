<?php

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
use App\Models\TransportFare;
use App\Models\TransportFarePrice;
use App\Models\TransportRoute;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

describe('tables', function () {
    test('every table of the module exists with the specified columns', function (string $table, array $columns) {
        expect(Schema::hasTable($table))->toBeTrue()
            ->and(Schema::getColumnListing($table))->toEqualCanonicalizing($columns);
    })->with([
        'employee_benefits' => ['employee_benefits', ['id', 'employee_id', 'benefit_type', 'starts_on', 'ends_on', 'notes', 'created_by', 'updated_by', 'created_at', 'updated_at']],
        'benefit_rates' => ['benefit_rates', ['id', 'benefit_type', 'amount', 'valid_from', 'created_by', 'created_at', 'updated_at']],
        'transport_fares' => ['transport_fares', ['id', 'name', 'operator', 'is_active', 'created_at', 'updated_at']],
        'transport_fare_prices' => ['transport_fare_prices', ['id', 'transport_fare_id', 'amount', 'valid_from', 'created_by', 'created_at', 'updated_at']],
        'transport_routes' => ['transport_routes', ['id', 'employee_id', 'transport_fare_id', 'trips_per_day', 'starts_on', 'ends_on', 'notes', 'created_by', 'updated_by', 'created_at', 'updated_at']],
        'benefit_periods' => ['benefit_periods', ['id', 'competence', 'status', 'business_days', 'calculated_at', 'calculated_by', 'closed_at', 'closed_by', 'notes', 'created_by', 'created_at', 'updated_at']],
        'benefit_period_status_changes' => ['benefit_period_status_changes', ['id', 'benefit_period_id', 'from_status', 'to_status', 'reason', 'user_id', 'created_at']],
        'benefit_adjustments' => ['benefit_adjustments', ['id', 'benefit_period_id', 'employee_id', 'reason', 'source', 'status', 'starts_on', 'ends_on', 'days_count', 'notes', 'dedupe_key', 'related_adjustment_id', 'reviewed_by', 'reviewed_at', 'review_notes', 'created_by', 'updated_by', 'created_at', 'updated_at']],
        'benefit_adjustment_impacts' => ['benefit_adjustment_impacts', ['id', 'benefit_adjustment_id', 'benefit_type', 'quantity', 'created_at', 'updated_at']],
        'benefit_period_employees' => ['benefit_period_employees', ['id', 'benefit_period_id', 'employee_id', 'employee_name', 'pis', 'position', 'created_at', 'updated_at']],
        'benefit_calculations' => ['benefit_calculations', ['id', 'benefit_period_employee_id', 'benefit_type', 'base_days', 'positive_days', 'negative_days', 'carried_in_days', 'carried_from_calculation_id', 'raw_days', 'final_days', 'carried_out_days', 'unit_amount', 'total_amount', 'benefit_rate_id', 'created_at', 'updated_at']],
        'benefit_calculation_transport_items' => ['benefit_calculation_transport_items', ['id', 'benefit_calculation_id', 'transport_route_id', 'transport_fare_id', 'fare_name', 'fare_amount', 'trips_per_day', 'daily_amount', 'created_at', 'updated_at']],
    ]);
});

describe('restrict on delete', function () {
    test('an employee with benefit history cannot be deleted', function (Closure $createHistory) {
        $employee = Employee::factory()->create();
        $createHistory($employee);

        expect(fn () => $employee->delete())->toThrow(QueryException::class);
        $this->assertModelExists($employee);
    })->with([
        'employee benefit' => [fn (Employee $employee) => EmployeeBenefit::factory()->for($employee)->create()],
        'transport route' => [fn (Employee $employee) => TransportRoute::factory()->for($employee)->create()],
        'benefit adjustment' => [fn (Employee $employee) => BenefitAdjustment::factory()->for($employee)->create()],
        'benefit period employee' => [fn (Employee $employee) => BenefitPeriodEmployee::factory()->for($employee)->create()],
    ]);

    test('a fare used by a route cannot be deleted', function () {
        $route = TransportRoute::factory()->create();

        expect(fn () => $route->transportFare->delete())->toThrow(QueryException::class);
    });

    test('a fare with price history cannot be deleted', function () {
        $price = TransportFarePrice::factory()->create();

        expect(fn () => $price->transportFare->delete())->toThrow(QueryException::class);
    });

    test('a period referenced by an adjustment cannot be deleted', function () {
        $adjustment = BenefitAdjustment::factory()->create();

        expect(fn () => $adjustment->benefitPeriod->delete())->toThrow(QueryException::class);
        $this->assertModelExists($adjustment);
    });

    test('a calculation that is the origin of an applied balance cannot be deleted', function () {
        $origin = BenefitCalculation::factory()->withCarriedOut(2)->create();
        BenefitCalculation::factory()->carriedFrom($origin)->create();

        expect(fn () => $origin->delete())->toThrow(QueryException::class);
        $this->assertModelExists($origin);
    });

    test('the snapshot of a period cannot be discarded while the next period applies its balance', function () {
        $origin = BenefitCalculation::factory()->withCarriedOut(2)->create();
        BenefitCalculation::factory()->carriedFrom($origin)->create();

        expect(fn () => $origin->benefitPeriodEmployee->delete())->toThrow(QueryException::class);
        $this->assertModelExists($origin);
    });
});

describe('cascade on delete', function () {
    test('deleting a period removes its status changes', function () {
        $change = BenefitPeriodStatusChange::factory()->create();

        $change->benefitPeriod->delete();

        $this->assertModelMissing($change);
    });

    test('deleting a period discards the whole snapshot', function () {
        $item = BenefitCalculationTransportItem::factory()->create();
        $calculation = $item->calculation;
        $periodEmployee = $calculation->benefitPeriodEmployee;

        $periodEmployee->benefitPeriod->delete();

        $this->assertModelMissing($periodEmployee);
        $this->assertModelMissing($calculation);
        $this->assertModelMissing($item);
        $this->assertModelExists($periodEmployee->employee);
    });

    test('deleting an adjustment removes its impacts', function () {
        $impact = BenefitAdjustmentImpact::factory()->create();

        $impact->benefitAdjustment->delete();

        $this->assertModelMissing($impact);
    });

    test('deleting a period employee removes its calculations', function () {
        $calculation = BenefitCalculation::factory()->create();

        $calculation->benefitPeriodEmployee->delete();

        $this->assertModelMissing($calculation);
    });

    test('deleting a calculation removes its transport items', function () {
        $item = BenefitCalculationTransportItem::factory()->create();

        $item->calculation->delete();

        $this->assertModelMissing($item);
    });
});

describe('set null on delete', function () {
    test('deleting a user keeps the records and clears the audit columns', function () {
        $user = User::factory()->create();

        $benefit = EmployeeBenefit::factory()->create(['created_by' => $user->id, 'updated_by' => $user->id]);
        $rate = BenefitRate::factory()->create(['created_by' => $user->id]);
        $price = TransportFarePrice::factory()->create(['created_by' => $user->id]);
        $route = TransportRoute::factory()->create(['created_by' => $user->id, 'updated_by' => $user->id]);
        $period = BenefitPeriod::factory()->closed()->create(['created_by' => $user->id, 'calculated_by' => $user->id, 'closed_by' => $user->id]);
        $change = BenefitPeriodStatusChange::factory()->create(['user_id' => $user->id]);
        $adjustment = BenefitAdjustment::factory()->rejected()->create([
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'reviewed_by' => $user->id,
        ]);

        $user->delete();

        expect($benefit->fresh()->only('created_by', 'updated_by'))->toBe(['created_by' => null, 'updated_by' => null])
            ->and($rate->fresh()->created_by)->toBeNull()
            ->and($price->fresh()->created_by)->toBeNull()
            ->and($route->fresh()->only('created_by', 'updated_by'))->toBe(['created_by' => null, 'updated_by' => null])
            ->and($period->fresh()->only('created_by', 'calculated_by', 'closed_by'))->toBe(['created_by' => null, 'calculated_by' => null, 'closed_by' => null])
            ->and($change->fresh()->user_id)->toBeNull()
            ->and($adjustment->fresh()->only('created_by', 'updated_by', 'reviewed_by'))->toBe(['created_by' => null, 'updated_by' => null, 'reviewed_by' => null]);
    });

    test('deleting a rate keeps the historical calculation', function () {
        $rate = BenefitRate::factory()->create();
        $calculation = BenefitCalculation::factory()->create(['benefit_rate_id' => $rate->id]);

        $rate->delete();

        expect($calculation->fresh())->not->toBeNull()
            ->and($calculation->fresh()->benefit_rate_id)->toBeNull()
            ->and($calculation->fresh()->unit_amount)->toBe('27.50');
    });

    test('deleting a route or fare keeps the transport snapshot', function () {
        $route = TransportRoute::factory()->create();
        $unusedFare = TransportFare::factory()->create();
        $item = BenefitCalculationTransportItem::factory()->create([
            'transport_route_id' => $route->id,
            'transport_fare_id' => $unusedFare->id,
        ]);

        $route->delete();
        $unusedFare->delete();

        expect($item->fresh()->transport_route_id)->toBeNull()
            ->and($item->fresh()->transport_fare_id)->toBeNull()
            ->and($item->fresh()->fare_name)->toBe('CPTM')
            ->and($item->fresh()->daily_amount)->toBe('10.80');
    });

    test('deleting the original adjustment keeps the replacement', function () {
        $original = BenefitAdjustment::factory()->create();
        $replacement = BenefitAdjustment::factory()->create(['related_adjustment_id' => $original->id]);

        $original->delete();

        expect($replacement->fresh()->related_adjustment_id)->toBeNull();
    });
});

describe('unique constraints', function () {
    test('the database rejects duplicates', function (Closure $createDuplicate) {
        expect($createDuplicate)->toThrow(QueryException::class);
    })->with([
        'employee benefit per type and start' => [function () {
            $benefit = EmployeeBenefit::factory()->create();
            EmployeeBenefit::factory()->create($benefit->only('employee_id', 'benefit_type', 'starts_on'));
        }],
        'benefit rate per type and start' => [function () {
            BenefitRate::factory()->vr()->validFrom('2026-10-01')->create();
            BenefitRate::factory()->vr('30.00')->validFrom('2026-10-01')->create();
        }],
        'transport fare name' => [function () {
            TransportFare::factory()->create(['name' => 'CPTM']);
            TransportFare::factory()->create(['name' => 'CPTM']);
        }],
        'fare price per start' => [function () {
            $price = TransportFarePrice::factory()->validFrom('2026-10-01')->create();
            TransportFarePrice::factory()->for($price->transportFare)->validFrom('2026-10-01')->create();
        }],
        'period competence' => [function () {
            BenefitPeriod::factory()->forCompetence('2026-10')->create();
            BenefitPeriod::factory()->forCompetence('2026-10')->create();
        }],
        'adjustment dedupe key' => [function () {
            BenefitAdjustment::factory()->create(['dedupe_key' => 'timesheet:1:1:2026-09-12:saturday_worked']);
            BenefitAdjustment::factory()->create(['dedupe_key' => 'timesheet:1:1:2026-09-12:saturday_worked']);
        }],
        'impact per adjustment and type' => [function () {
            $impact = BenefitAdjustmentImpact::factory()->ofType(BenefitType::Vr, -1)->create();
            BenefitAdjustmentImpact::factory()->for($impact->benefitAdjustment)->ofType(BenefitType::Vr, -2)->create();
        }],
        'period employee per period' => [function () {
            $periodEmployee = BenefitPeriodEmployee::factory()->create();
            BenefitPeriodEmployee::factory()->create($periodEmployee->only('benefit_period_id', 'employee_id'));
        }],
        'calculation per period employee and type' => [function () {
            $calculation = BenefitCalculation::factory()->create();
            BenefitCalculation::factory()->for($calculation->benefitPeriodEmployee)->create();
        }],
        'balance applied twice' => [function () {
            $origin = BenefitCalculation::factory()->withCarriedOut(2)->create();
            BenefitCalculation::factory()->carriedFrom($origin)->create();
            BenefitCalculation::factory()->carriedFrom($origin)->create();
        }],
    ]);

    test('the same fare may have prices on different dates and different fares on the same date', function () {
        $price = TransportFarePrice::factory()->validFrom('2026-10-01')->create();
        TransportFarePrice::factory()->for($price->transportFare)->validFrom('2026-11-01')->create();
        TransportFarePrice::factory()->validFrom('2026-10-01')->create();

        expect(TransportFarePrice::count())->toBe(3);
    });

    test('vr and vd may start on the same date', function () {
        BenefitRate::factory()->vr()->validFrom('2026-10-01')->create();
        BenefitRate::factory()->vd()->validFrom('2026-10-01')->create();

        expect(BenefitRate::count())->toBe(2);
    });

    test('many adjustments may have no dedupe key', function () {
        BenefitAdjustment::factory()->count(3)->create(['dedupe_key' => null]);

        expect(BenefitAdjustment::whereNull('dedupe_key')->count())->toBe(3);
    });

    test('many calculations may have no balance origin while each origin is applied once', function () {
        BenefitCalculation::factory()->count(3)->create();
        $origin = BenefitCalculation::factory()->withCarriedOut(2)->create();
        BenefitCalculation::factory()->carriedFrom($origin)->create();

        expect(BenefitCalculation::whereNull('carried_from_calculation_id')->count())->toBe(4)
            ->and(BenefitCalculation::where('carried_from_calculation_id', $origin->id)->count())->toBe(1);
    });
});
