<?php

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\BenefitPeriodStatus;
use App\Enums\BenefitType;
use App\Livewire\Benefits\BenefitPeriodCalculation;
use App\Models\BenefitAdjustment;
use App\Models\BenefitCalculation;
use App\Models\BenefitPeriod;
use App\Models\BenefitPeriodEmployee;
use App\Models\BenefitRate;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\Holiday;
use App\Models\Point;
use App\Models\TransportFare;
use App\Models\TransportFarePrice;
use App\Models\TransportRoute;
use App\Models\User;
use App\Services\BenefitAdjustmentRegistrar;
use App\Services\BenefitPeriodCalculator;
use App\Services\BenefitPeriodWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Competência 10/2026: 21 dias-base (22 dias úteis − 12/10). Janela: setembro/2026.
 * Setembro/2026 tem 21 dias-base (07/09 é feriado); janela de setembro = agosto/2026.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Holiday::factory()->create(['date' => '2026-09-07', 'description' => 'Independência']);
    Holiday::factory()->create(['date' => '2026-10-12', 'description' => 'Nossa Senhora Aparecida']);
    Holiday::factory()->create(['date' => '2026-11-02', 'description' => 'Finados']);

    $this->vr = BenefitRate::factory()->vr('27.50')->validFrom('2026-01-01')->create();
    $this->vd = BenefitRate::factory()->vd('7.50')->validFrom('2026-01-01')->create();

    $this->workflow = app(BenefitPeriodWorkflow::class);
    $this->registrar = app(BenefitAdjustmentRegistrar::class);
    $this->period = BenefitPeriod::factory()->forCompetence('2026-10')->create();

    $this->fare = function (string $amount, ?string $name = null, string $validFrom = '2026-01-01'): TransportFare {
        $fare = TransportFare::factory()->create($name ? ['name' => $name] : []);
        TransportFarePrice::factory()->for($fare)->amount($amount)->validFrom($validFrom)->create();

        return $fare;
    };

    /**
     * @param  list<BenefitType>  $types
     * @param  list<array{0: TransportFare, 1: int}>  $routes
     */
    $this->participant = function (array $types = [BenefitType::Vt, BenefitType::Vr, BenefitType::Vd], array $routes = [], array $attributes = []): Employee {
        $employee = Employee::factory()->create($attributes);

        foreach ($types as $type) {
            EmployeeBenefit::factory()->for($employee)->ofType($type)->between('2026-01-01')->create();
        }

        foreach ($routes as [$fare, $trips]) {
            TransportRoute::factory()->for($employee)->for($fare)->tripsPerDay($trips)->between('2026-01-01')->create();
        }

        return $employee;
    };

    $this->adjust = fn (Employee $employee, AdjustmentReason $reason, string $startsOn, ?string $endsOn = null, AdjustmentSource $source = AdjustmentSource::Manual, ?string $notes = null, array $impacts = [], ?BenefitPeriod $period = null) => $this->registrar->register(
        $period ?? $this->period,
        $employee,
        $reason,
        $source,
        CarbonImmutable::parse($startsOn),
        CarbonImmutable::parse($endsOn ?? $startsOn),
        $notes,
        $impacts,
    )['adjustment'];

    $this->calc = fn (Employee $employee, BenefitType $type, ?BenefitPeriod $period = null): ?BenefitCalculation => BenefitCalculation::query()
        ->whereHas('benefitPeriodEmployee', fn ($query) => $query->where('benefit_period_id', ($period ?? $this->period)->id)->where('employee_id', $employee->id))
        ->where('benefit_type', $type)
        ->first();
});

describe('quantity', function () {
    test('only the base days are used when there are no adjustments', function () {
        $employee = ($this->participant)([BenefitType::Vr]);

        $this->workflow->calculate($this->period);

        $calculation = ($this->calc)($employee, BenefitType::Vr);

        expect($calculation->base_days)->toBe(21)
            ->and($calculation->positive_days)->toBe(0)
            ->and($calculation->negative_days)->toBe(0)
            ->and($calculation->carried_in_days)->toBe(0)
            ->and($calculation->raw_days)->toBe(21)
            ->and($calculation->final_days)->toBe(21)
            ->and($calculation->carried_out_days)->toBe(0)
            ->and($calculation->unit_amount)->toBe('27.50')
            ->and($calculation->total_amount)->toBe('577.50')
            ->and($calculation->benefit_rate_id)->toBe($this->vr->id)
            ->and($this->period->fresh()->business_days)->toBe(21);
    });

    test('positive and negative impacts are added and subtracted', function () {
        $employee = ($this->participant)([BenefitType::Vr]);
        ($this->adjust)($employee, AdjustmentReason::SaturdayWorked, '2026-09-12');
        ($this->adjust)($employee, AdjustmentReason::SundayWorked, '2026-09-13');
        ($this->adjust)($employee, AdjustmentReason::Vacation, '2026-09-21', '2026-09-23');

        $this->workflow->calculate($this->period);

        $calculation = ($this->calc)($employee, BenefitType::Vr);

        expect($calculation->positive_days)->toBe(2)
            ->and($calculation->negative_days)->toBe(3)
            ->and($calculation->final_days)->toBe(20);
    });

    test('a balance that reaches exactly zero generates no carry', function () {
        $employee = ($this->participant)([BenefitType::Vr]);
        ($this->adjust)($employee, AdjustmentReason::Manual, '2026-09-15', notes: 'Pago a maior.', impacts: ['vr' => -21]);

        $this->workflow->calculate($this->period);

        $calculation = ($this->calc)($employee, BenefitType::Vr);

        expect($calculation->raw_days)->toBe(0)
            ->and($calculation->final_days)->toBe(0)
            ->and($calculation->carried_out_days)->toBe(0)
            ->and($calculation->total_amount)->toBe('0.00');
    });

    test('a negative balance ends at zero and generates carried out days', function () {
        $employee = ($this->participant)([BenefitType::Vr]);
        ($this->adjust)($employee, AdjustmentReason::Manual, '2026-09-15', notes: 'Pago a maior.', impacts: ['vr' => -23]);

        $this->workflow->calculate($this->period);

        $calculation = ($this->calc)($employee, BenefitType::Vr);

        expect($calculation->negative_days)->toBe(23)
            ->and($calculation->raw_days)->toBe(-2)
            ->and($calculation->final_days)->toBe(0)
            ->and($calculation->carried_out_days)->toBe(2)
            ->and($calculation->total_amount)->toBe('0.00')
            ->and(BenefitAdjustment::count())->toBe(1);
    });
});

describe('carry forward', function () {
    beforeEach(function () {
        $this->september = BenefitPeriod::factory()->forCompetence('2026-09')->create();

        $this->closeSeptemberWithCarry = function (Employee $employee, array $impacts): void {
            ($this->adjust)($employee, AdjustmentReason::Manual, '2026-08-17', notes: 'Pago a maior em agosto.', impacts: $impacts, period: $this->september);
            $this->workflow->calculate($this->september);
            $this->september->forceFill(['status' => BenefitPeriodStatus::Closed])->save();
        };
    });

    test('the carry is applied to the next competence when the employee is eligible', function () {
        $employee = ($this->participant)([BenefitType::Vr]);
        ($this->closeSeptemberWithCarry)($employee, ['vr' => -23]);

        $this->workflow->calculate($this->period);

        $origin = ($this->calc)($employee, BenefitType::Vr, $this->september);
        $calculation = ($this->calc)($employee, BenefitType::Vr);

        expect($origin->carried_out_days)->toBe(2)
            ->and($calculation->carried_in_days)->toBe(2)
            ->and($calculation->carried_from_calculation_id)->toBe($origin->id)
            ->and($calculation->raw_days)->toBe(19)
            ->and($calculation->final_days)->toBe(19);
    });

    test('the carry is not applied when the employee is not eligible to the type', function () {
        $employee = Employee::factory()->create();
        EmployeeBenefit::factory()->for($employee)->ofType(BenefitType::Vt)->between('2026-01-01', '2026-09-30')->create();
        EmployeeBenefit::factory()->for($employee)->ofType(BenefitType::Vr)->between('2026-01-01')->create();
        TransportRoute::factory()->for($employee)->for(($this->fare)('5.40'))->between('2026-01-01')->create();
        ($this->closeSeptemberWithCarry)($employee, ['vt' => -25]);

        $this->workflow->calculate($this->period);

        expect(($this->calc)($employee, BenefitType::Vt, $this->september)->carried_out_days)->toBe(4)
            ->and(($this->calc)($employee, BenefitType::Vt))->toBeNull()
            ->and(($this->calc)($employee, BenefitType::Vr)->carried_in_days)->toBe(0)
            ->and(($this->calc)($employee, BenefitType::Vr)->final_days)->toBe(21);
    });

    test('the carry never reaches the competence after the next one', function () {
        $employee = Employee::factory()->create();
        EmployeeBenefit::factory()->for($employee)->ofType(BenefitType::Vr)->between('2026-01-01', '2026-09-30')->create();
        EmployeeBenefit::factory()->for($employee)->ofType(BenefitType::Vd)->between('2026-01-01')->create();
        ($this->closeSeptemberWithCarry)($employee, ['vr' => -23]);

        $this->workflow->calculate($this->period);
        $this->period->forceFill(['status' => BenefitPeriodStatus::Closed])->save();

        EmployeeBenefit::factory()->for($employee)->ofType(BenefitType::Vr)->between('2026-11-01')->create();
        $november = BenefitPeriod::factory()->forCompetence('2026-11')->create();

        $this->workflow->calculate($november);

        expect(($this->calc)($employee, BenefitType::Vr))->toBeNull()
            ->and(($this->calc)($employee, BenefitType::Vr, $november)->carried_in_days)->toBe(0)
            ->and(($this->calc)($employee, BenefitType::Vr, $november)->carried_from_calculation_id)->toBeNull();
    });

    test('the carry is specific to each benefit', function () {
        $employee = ($this->participant)([BenefitType::Vt, BenefitType::Vr, BenefitType::Vd], [[($this->fare)('5.40'), 2]]);
        ($this->closeSeptemberWithCarry)($employee, ['vt' => -23]);

        $this->workflow->calculate($this->period);

        expect(($this->calc)($employee, BenefitType::Vt)->carried_in_days)->toBe(2)
            ->and(($this->calc)($employee, BenefitType::Vt)->final_days)->toBe(19)
            ->and(($this->calc)($employee, BenefitType::Vr)->carried_in_days)->toBe(0)
            ->and(($this->calc)($employee, BenefitType::Vd)->carried_in_days)->toBe(0)
            ->and(($this->calc)($employee, BenefitType::Vr)->final_days)->toBe(21);
    });

    test('the first competence has no carried in days', function () {
        $employee = ($this->participant)([BenefitType::Vr]);
        ($this->adjust)($employee, AdjustmentReason::Manual, '2026-08-17', notes: 'x', impacts: ['vr' => -23], period: $this->september);

        $this->workflow->calculate($this->september);

        expect(($this->calc)($employee, BenefitType::Vr, $this->september)->carried_in_days)->toBe(0);
    });

    test('a previous competence that is not closed blocks the calculation', function (BenefitPeriodStatus $status) {
        ($this->participant)([BenefitType::Vr]);
        $this->september->forceFill(['status' => $status])->save();

        expect(fn () => $this->workflow->calculate($this->period))
            ->toThrow(DomainException::class, 'competência anterior (09/2026) precisa estar fechada');

        expect($this->period->fresh()->status)->toBe(BenefitPeriodStatus::Open)
            ->and(BenefitPeriodEmployee::count())->toBe(0);
    })->with([BenefitPeriodStatus::Open, BenefitPeriodStatus::Calculated]);

    test('recalculating a competence whose carry was already applied is blocked', function () {
        $employee = ($this->participant)([BenefitType::Vr]);
        ($this->closeSeptemberWithCarry)($employee, ['vr' => -23]);
        $this->workflow->calculate($this->period);
        $this->september->forceFill(['status' => BenefitPeriodStatus::Calculated])->save();

        expect(fn () => $this->workflow->calculate($this->september))
            ->toThrow(DomainException::class, 'já foi aplicado na competência seguinte');

        expect(($this->calc)($employee, BenefitType::Vr)->carried_in_days)->toBe(2);
    });
});

describe('eligibility', function () {
    test('an employee without benefits is not calculated', function () {
        $outsider = Employee::factory()->create();

        $this->workflow->calculate($this->period);

        expect(BenefitPeriodEmployee::query()->where('employee_id', $outsider->id)->exists())->toBeFalse();
    });

    test('only eligible types are calculated for a participant', function () {
        $employee = ($this->participant)([BenefitType::Vt, BenefitType::Vr], [[($this->fare)('5.40'), 2]]);

        $this->workflow->calculate($this->period);

        $periodEmployee = BenefitPeriodEmployee::sole();

        expect($periodEmployee->employee_id)->toBe($employee->id)
            ->and($periodEmployee->employee_name)->toBe($employee->name)
            ->and($periodEmployee->pis)->toBe((string) $employee->pis)
            ->and($periodEmployee->calculations()->pluck('benefit_type')->map->value->sort()->values()->all())->toBe(['vr', 'vt']);
    });

    test('vt eligible without routes is a blocking issue', function () {
        $employee = ($this->participant)([BenefitType::Vt]);

        $issues = $this->workflow->calculate($this->period)['issues'];

        expect($issues)->toHaveCount(1)
            ->and($issues[0]['severity'])->toBe('blocking')
            ->and($issues[0]['employee_id'])->toBe($employee->id)
            ->and($issues[0]['benefit_type'])->toBe('vt')
            ->and($issues[0]['message'])->toContain('não possui itinerário vigente em 01/10/2026')
            ->and($this->period->fresh()->status)->toBe(BenefitPeriodStatus::Calculated);
    });

    test('routes without vt eligibility do not create a vt calculation', function () {
        $employee = ($this->participant)([BenefitType::Vr], [[($this->fare)('5.40'), 2]]);

        $result = $this->workflow->calculate($this->period);

        expect(($this->calc)($employee, BenefitType::Vt))->toBeNull()
            ->and($result['issues'])->toBe([]);
    });

    test('missing vr or vd rate blocks the calculation', function (BenefitType $type) {
        ($type === BenefitType::Vr ? $this->vr : $this->vd)->delete();
        ($this->participant)([$type]);

        expect(fn () => $this->workflow->calculate($this->period))
            ->toThrow(DomainException::class, 'Não há valor de '.$type->label().' vigente em 01/10/2026');

        expect(BenefitPeriodEmployee::count())->toBe(0)
            ->and($this->period->fresh()->status)->toBe(BenefitPeriodStatus::Open)
            ->and(collect(app(BenefitPeriodCalculator::class)->issues($this->period))->pluck('severity')->all())->toBe(['blocking']);
    })->with([BenefitType::Vr, BenefitType::Vd]);

    test('a missing rate of a type nobody uses is not an issue', function () {
        $this->vd->delete();
        ($this->participant)([BenefitType::Vr]);

        expect($this->workflow->calculate($this->period)['issues'])->toBe([]);
    });

    test('vr and vd rates are independent', function () {
        $this->vr->delete();
        BenefitRate::factory()->vr('30.00')->validFrom('2026-10-01')->create();
        $employee = ($this->participant)([BenefitType::Vr, BenefitType::Vd]);

        $this->workflow->calculate($this->period);

        expect(($this->calc)($employee, BenefitType::Vr)->unit_amount)->toBe('30.00')
            ->and(($this->calc)($employee, BenefitType::Vd)->unit_amount)->toBe('7.50')
            ->and(($this->calc)($employee, BenefitType::Vd)->benefit_rate_id)->toBe($this->vd->id);
    });

    test('a rescinded participant is still calculated', function () {
        $employee = ($this->participant)([BenefitType::Vr], attributes: ['recision_date' => '2026-09-10']);

        $this->workflow->calculate($this->period);

        expect(($this->calc)($employee, BenefitType::Vr)->final_days)->toBe(21);
    });
});

describe('adjustments', function () {
    test('only confirmed adjustments count', function () {
        $employee = ($this->participant)([BenefitType::Vr]);
        ($this->adjust)($employee, AdjustmentReason::Vacation, '2026-09-14');
        ($this->adjust)($employee, AdjustmentReason::Vacation, '2026-09-15', source: AdjustmentSource::Import);
        $rejected = ($this->adjust)($employee, AdjustmentReason::Vacation, '2026-09-16');
        $this->registrar->reject($rejected, 'Lançado errado.');

        $this->workflow->calculate($this->period);

        expect(($this->calc)($employee, BenefitType::Vr)->negative_days)->toBe(1)
            ->and(($this->calc)($employee, BenefitType::Vr)->final_days)->toBe(20);
    });

    test('an impact on a single benefit affects only that benefit', function (string $type) {
        $employee = ($this->participant)([BenefitType::Vt, BenefitType::Vr, BenefitType::Vd], [[($this->fare)('5.40'), 2]]);
        ($this->adjust)($employee, AdjustmentReason::Manual, '2026-09-15', notes: 'Correção.', impacts: [$type => -3]);

        $this->workflow->calculate($this->period);

        foreach (BenefitType::cases() as $benefitType) {
            expect(($this->calc)($employee, $benefitType)->final_days)->toBe($benefitType->value === $type ? 18 : 21);
        }
    })->with(['vt', 'vr', 'vd']);

    test('several impacts are aggregated per employee and benefit', function () {
        $employee = ($this->participant)([BenefitType::Vt, BenefitType::Vr], [[($this->fare)('5.40'), 2]]);
        $other = ($this->participant)([BenefitType::Vr]);
        ($this->adjust)($employee, AdjustmentReason::Manual, '2026-09-15', notes: 'a', impacts: ['vt' => 1, 'vr' => 2]);
        ($this->adjust)($employee, AdjustmentReason::Manual, '2026-09-16', notes: 'b', impacts: ['vt' => -3, 'vr' => 2]);
        ($this->adjust)($employee, AdjustmentReason::Manual, '2026-09-17', notes: 'c', impacts: ['vt' => -1]);
        ($this->adjust)($other, AdjustmentReason::Vacation, '2026-09-15');

        $this->workflow->calculate($this->period);

        $vt = ($this->calc)($employee, BenefitType::Vt);

        expect($vt->positive_days)->toBe(1)
            ->and($vt->negative_days)->toBe(4)
            ->and($vt->final_days)->toBe(18)
            ->and(($this->calc)($employee, BenefitType::Vr)->positive_days)->toBe(4)
            ->and(($this->calc)($employee, BenefitType::Vr)->negative_days)->toBe(0)
            ->and(($this->calc)($other, BenefitType::Vr)->negative_days)->toBe(1);
    });

    test('impacts of a type the employee is not eligible to are ignored', function () {
        $employee = ($this->participant)([BenefitType::Vr]);
        ($this->adjust)($employee, AdjustmentReason::Vacation, '2026-09-15');

        $this->workflow->calculate($this->period);

        expect(($this->calc)($employee, BenefitType::Vt))->toBeNull()
            ->and(BenefitCalculation::count())->toBe(1);
    });

    test('the calculation creates no adjustments and does not touch points', function () {
        $employee = ($this->participant)([BenefitType::Vr]);
        Point::factory()->forEmployee($employee)->on('2026-09-15')->create();
        $points = DB::table('points')->get()->toArray();

        $this->workflow->calculate($this->period);

        expect(BenefitAdjustment::count())->toBe(0)
            ->and(DB::table('points')->get()->toArray())->toEqual($points);
    });
});

describe('vt', function () {
    test('several routes sum their daily amounts', function () {
        $employee = ($this->participant)([BenefitType::Vt], [[($this->fare)('5.00', 'CPTM'), 2], [($this->fare)('4.40', 'SP'), 2]]);

        $this->workflow->calculate($this->period);

        $vt = ($this->calc)($employee, BenefitType::Vt);

        expect($vt->unit_amount)->toBe('18.80')
            ->and($vt->total_amount)->toBe('394.80')
            ->and($vt->benefit_rate_id)->toBeNull();
    });

    test('the price valid on the first day of the month is used', function () {
        $fare = ($this->fare)('5.00', 'CPTM');
        TransportFarePrice::factory()->for($fare)->amount('5.40')->validFrom('2026-10-01')->create();
        TransportFarePrice::factory()->for($fare)->amount('6.00')->validFrom('2026-10-15')->create();
        $employee = ($this->participant)([BenefitType::Vt], [[$fare, 2]]);

        $this->workflow->calculate($this->period);

        expect(($this->calc)($employee, BenefitType::Vt)->unit_amount)->toBe('10.80');
    });

    test('a route changed during the month does not change the competence', function () {
        $cptm = ($this->fare)('5.40', 'CPTM');
        $employee = ($this->participant)([BenefitType::Vt]);
        TransportRoute::factory()->for($employee)->for($cptm)->tripsPerDay(2)->between('2026-01-01', '2026-10-14')->create();
        TransportRoute::factory()->for($employee)->for(($this->fare)('8.40', 'SP'))->tripsPerDay(2)->between('2026-10-15')->create();

        $this->workflow->calculate($this->period);

        $vt = ($this->calc)($employee, BenefitType::Vt);

        expect($vt->unit_amount)->toBe('10.80')
            ->and($vt->transportItems()->pluck('fare_name')->all())->toBe(['CPTM']);
    });

    test('a route without a valid price is a blocking issue', function () {
        $fare = TransportFare::factory()->create(['name' => 'Sem preço']);
        TransportFarePrice::factory()->for($fare)->amount('5.00')->validFrom('2026-11-01')->create();
        $employee = ($this->participant)([BenefitType::Vt], [[$fare, 2], [($this->fare)('5.40', 'CPTM'), 2]]);

        $issues = $this->workflow->calculate($this->period)['issues'];

        expect($issues)->toHaveCount(1)
            ->and($issues[0]['severity'])->toBe('blocking')
            ->and($issues[0]['employee_id'])->toBe($employee->id)
            ->and($issues[0]['message'])->toContain('sem preço vigente em 01/10/2026 (competência 10/2026)')
            ->and(($this->calc)($employee, BenefitType::Vt)->unit_amount)->toBe('10.80');
    });

    test('the snapshot keeps the routes used', function () {
        $cptm = ($this->fare)('5.40', 'CPTM');
        $employee = ($this->participant)([BenefitType::Vt], [[$cptm, 2], [($this->fare)('8.40', 'SP'), 3]]);

        $this->workflow->calculate($this->period);

        $items = ($this->calc)($employee, BenefitType::Vt)->transportItems()->orderBy('fare_name')->get();

        expect($items)->toHaveCount(2)
            ->and($items[0]->only(['fare_name', 'fare_amount', 'trips_per_day', 'daily_amount', 'transport_fare_id']))
            ->toBe(['fare_name' => 'CPTM', 'fare_amount' => '5.40', 'trips_per_day' => 2, 'daily_amount' => '10.80', 'transport_fare_id' => $cptm->id])
            ->and($items[1]->daily_amount)->toBe('25.20')
            ->and($items[0]->transport_route_id)->not->toBeNull();
    });
});

describe('amounts', function () {
    test('a rate valid from the next month does not change the competence', function () {
        BenefitRate::factory()->vr('30.00')->validFrom('2026-11-01')->create();
        $employee = ($this->participant)([BenefitType::Vr]);

        $this->workflow->calculate($this->period);

        expect(($this->calc)($employee, BenefitType::Vr)->unit_amount)->toBe('27.50');
    });

    test('cents are exact', function () {
        $employee = ($this->participant)([BenefitType::Vt], [[($this->fare)('10.32', 'SP TRANS'), 2]]);

        $this->workflow->calculate($this->period);

        expect(($this->calc)($employee, BenefitType::Vt)->total_amount)->toBe('433.44');
    });

    test('the snapshot is not changed by later configuration changes', function () {
        $fare = ($this->fare)('5.40', 'CPTM');
        $employee = ($this->participant)([BenefitType::Vt, BenefitType::Vr], [[$fare, 2]]);
        $this->workflow->calculate($this->period);

        BenefitRate::factory()->vr('30.00')->validFrom('2026-11-01')->create();
        $fare->prices()->update(['amount' => '9.99']);
        $fare->update(['name' => 'Renomeada']);
        TransportRoute::query()->update(['trips_per_day' => 4]);

        $vt = ($this->calc)($employee, BenefitType::Vt);

        expect(($this->calc)($employee, BenefitType::Vr)->total_amount)->toBe('577.50')
            ->and($vt->total_amount)->toBe('226.80')
            ->and($vt->transportItems()->first()->only(['fare_name', 'fare_amount', 'trips_per_day']))
            ->toBe(['fare_name' => 'CPTM', 'fare_amount' => '5.40', 'trips_per_day' => 2]);
    });
});

describe('workflow', function () {
    test('a successful calculation leaves the competence calculated with history', function () {
        ($this->participant)([BenefitType::Vr]);

        $this->workflow->calculate($this->period);

        $period = $this->period->fresh();

        expect($period->status)->toBe(BenefitPeriodStatus::Calculated)
            ->and($period->calculated_by)->toBe($this->user->id)
            ->and($period->calculated_at)->not->toBeNull()
            ->and($period->statusChanges()->latest('id')->first()->only(['from_status', 'to_status', 'reason']))
            ->toBe(['from_status' => BenefitPeriodStatus::Open, 'to_status' => BenefitPeriodStatus::Calculated, 'reason' => 'Prévia calculada.']);
    });

    test('a calculated competence can be recalculated and the snapshot is replaced', function () {
        $employee = ($this->participant)([BenefitType::Vr]);
        $this->workflow->calculate($this->period);
        $firstId = ($this->calc)($employee, BenefitType::Vr)->id;

        BenefitAdjustment::factory()->for($this->period)->for($employee)->absence('2026-09-15')->create()
            ->impacts()->create(['benefit_type' => BenefitType::Vr, 'quantity' => -1]);

        $this->workflow->calculate($this->period);

        expect(BenefitPeriodEmployee::count())->toBe(1)
            ->and(BenefitCalculation::count())->toBe(1)
            ->and(($this->calc)($employee, BenefitType::Vr)->id)->not->toBe($firstId)
            ->and(($this->calc)($employee, BenefitType::Vr)->final_days)->toBe(20)
            ->and($this->period->fresh()->statusChanges()->latest('id')->first()->reason)->toBe('Prévia recalculada.');
    });

    test('a closed competence cannot be calculated', function () {
        $this->period->forceFill(['status' => BenefitPeriodStatus::Closed])->save();

        expect(fn () => $this->workflow->calculate($this->period))->toThrow(DomainException::class, 'está fechada');
    });

    test('a failure keeps the previous snapshot intact', function () {
        $employee = ($this->participant)([BenefitType::Vr]);
        $this->workflow->calculate($this->period);
        $this->vr->delete();

        expect(fn () => $this->workflow->calculate($this->period))->toThrow(DomainException::class);

        expect(($this->calc)($employee, BenefitType::Vr)->total_amount)->toBe('577.50')
            ->and($this->period->fresh()->status)->toBe(BenefitPeriodStatus::Calculated);
    });

    test('a missing holiday calendar is a warning', function () {
        Holiday::query()->delete();
        Holiday::flushCachedHolidays();
        ($this->participant)([BenefitType::Vr]);

        $issues = $this->workflow->calculate($this->period)['issues'];

        expect($issues[0]['severity'])->toBe('warning')
            ->and($issues[0]['message'])->toContain('Não há feriados cadastrados em 2026');
    });
});

describe('queries', function () {
    test('the number of queries does not grow with the number of employees', function () {
        $fares = [($this->fare)('5.40', 'CPTM'), ($this->fare)('8.40', 'SP')];

        $countQueries = function (int $employees) use ($fares): int {
            DB::table('benefit_adjustment_impacts')->delete();
            DB::table('benefit_adjustments')->delete();
            DB::table('benefit_period_employees')->delete();
            DB::table('transport_routes')->delete();
            DB::table('employee_benefits')->delete();

            foreach (range(1, $employees) as $index) {
                $employee = ($this->participant)(BenefitType::cases(), [[$fares[0], 2], [$fares[1], 2]]);
                ($this->adjust)($employee, AdjustmentReason::Vacation, '2026-09-15');
                ($this->adjust)($employee, AdjustmentReason::Manual, '2026-09-16', notes: 'x', impacts: ['vt' => 1]);
            }

            $this->period->forceFill(['status' => BenefitPeriodStatus::Open])->save();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->workflow->calculate($this->period);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $few = $countQueries(3);
        $many = $countQueries(12);

        expect(BenefitCalculation::count())->toBe(36)
            ->and(BenefitCalculation::query()->where('benefit_type', 'vt')->first()->transportItems()->count())->toBe(2)
            ->and($many)->toBe($few)
            ->and($few)->toBeLessThan(30);
    });
});

describe('screen', function () {
    test('the calculation screen shows the main table, totals and details', function () {
        $employee = ($this->participant)(BenefitType::cases(), [[($this->fare)('5.00', 'CPTM'), 2], [($this->fare)('4.40', 'SP'), 2]], ['name' => 'João Pereira']);
        $this->workflow->calculate($this->period);

        $this->get(route('benefits.periods.calculation', $this->period))
            ->assertOk()
            ->assertSee('Apuração — Competência 10/2026')
            ->assertSeeInOrder(['Funcionário', 'VT qtd', 'VT valor', 'VR qtd', 'VR valor', 'VD qtd', 'VD valor', 'Total'])
            ->assertSeeInOrder(['João Pereira', '21', 'R$ 394,80', '21', 'R$ 577,50', '21', 'R$ 157,50', 'R$ 1.129,80'])
            ->assertSee('Totais')
            ->assertSee('Detalhar apuração')
            ->assertSee('CPTM: R$ 5,00 × 2 = R$ 10,00/dia');
    });

    test('the calculate button goes through the workflow', function () {
        ($this->participant)([BenefitType::Vr]);

        Livewire::test(BenefitPeriodCalculation::class, ['benefitPeriod' => $this->period])
            ->assertSee('Calcular')
            ->call('calculate');

        expect($this->period->fresh()->status)->toBe(BenefitPeriodStatus::Calculated);
    });

    test('issues are shown and the amount is not presented as valid', function () {
        ($this->participant)([BenefitType::Vt, BenefitType::Vr], attributes: ['name' => 'Maria Silva']);

        Livewire::test(BenefitPeriodCalculation::class, ['benefitPeriod' => $this->period])
            ->call('calculate')
            ->assertSee('Pendências de cálculo')
            ->assertSee('Maria Silva é elegível a VT, mas não possui itinerário vigente em 01/10/2026.')
            ->assertSee('Pendente');
    });

    test('a blocked calculation keeps the competence open', function () {
        $this->vr->delete();
        ($this->participant)([BenefitType::Vr]);

        Livewire::test(BenefitPeriodCalculation::class, ['benefitPeriod' => $this->period])->call('calculate');

        expect($this->period->fresh()->status)->toBe(BenefitPeriodStatus::Open);
    });

    test('a closed competence cannot be calculated from the screen', function () {
        $this->period->forceFill(['status' => BenefitPeriodStatus::Closed])->save();

        Livewire::test(BenefitPeriodCalculation::class, ['benefitPeriod' => $this->period])
            ->assertDontSee('wire:click="calculate"', false)
            ->assertSee('wire:click="openReopenModal"', false);
    });

    test('the periods list links to the calculation screen', function () {
        $this->get(route('benefits.periods.index'))->assertSee(route('benefits.periods.calculation', $this->period));
    });
});
