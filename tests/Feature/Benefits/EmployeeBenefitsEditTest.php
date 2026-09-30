<?php

use App\Enums\BenefitType;
use App\Livewire\Benefits\EmployeeBenefitsEdit;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\TransportFare;
use App\Models\TransportFarePrice;
use App\Models\TransportRoute;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->employee = Employee::factory()->create(['name' => 'Funcionário Benefícios', 'position' => 'Soldador']);
});

function benefitsScreen(Employee $employee, string $referenceDate = '2026-10-01'): Testable
{
    return Livewire::test(EmployeeBenefitsEdit::class, ['employee' => $employee])->set('referenceDate', $referenceDate);
}

function pricedFare(string $name, string $amount, string $validFrom = '2026-01-01'): TransportFare
{
    $fare = TransportFare::factory()->create(['name' => $name]);
    TransportFarePrice::factory()->for($fare)->amount($amount)->validFrom($validFrom)->create();

    return $fare;
}

describe('screen', function () {
    test('renders the employee with its validities and itinerary', function () {
        EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vt)->between('2026-09-01')->create();
        EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vd)->between('2026-09-01', '2026-09-30')->create();
        TransportRoute::factory()->for($this->employee)->for(pricedFare('CPTM', '5.40'))->tripsPerDay(2)->between('2026-09-01')->create();

        $this->get(route('employees.benefits', $this->employee))
            ->assertOk()
            ->assertSee('Benefícios — Funcionário Benefícios')
            ->assertSee($this->employee->pis)
            ->assertSee('Soldador')
            ->assertSee('01/09/2026 → Em aberto')
            ->assertSee('01/09/2026 → 30/09/2026')
            ->assertSee('CPTM');
    });

    test('shows eligibility and the daily vt amount on the chosen reference', function () {
        EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vt)->between('2026-09-01')->create();
        EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vd)->between('2026-09-01', '2026-09-30')->create();
        TransportRoute::factory()->for($this->employee)->for(pricedFare('CPTM', '5.40'))->tripsPerDay(2)->create();
        TransportRoute::factory()->for($this->employee)->for(pricedFare('SP', '8.40'))->tripsPerDay(2)->create();

        benefitsScreen($this->employee, '2026-10-01')
            ->assertSee('Elegível em 01/10/2026')
            ->assertSee('Não elegível em 01/10/2026')
            ->assertSee('R$ 10,80/dia')
            ->assertSee('R$ 16,80/dia')
            ->assertSee('R$ 27,60');
    });

    test('warns when a leg has no price in force', function () {
        TransportRoute::factory()->for($this->employee)->for(TransportFare::factory()->create(['name' => 'Linha Sem Preço']))->create();

        benefitsScreen($this->employee)
            ->assertSee('Sem preço vigente')
            ->assertSee('sem preço cadastrado nesta data')
            ->assertSee('Linha Sem Preço');
    });

    test('an unknown employee returns not found', function () {
        $this->get(route('employees.benefits', 999))->assertNotFound();
    });
});

describe('eligibility', function () {
    test('a validity is created', function () {
        benefitsScreen($this->employee)
            ->call('openBenefitModal')
            ->set('benefitType', 'vr')
            ->set('benefitStartsOn', '2026-09-15T00:00:00-03:00')
            ->set('benefitNotes', '  Admissão  ')
            ->call('createBenefit')
            ->assertHasNoErrors()
            ->assertSet('showBenefitModal', false);

        $benefit = $this->employee->employeeBenefits()->sole();

        expect($benefit->benefit_type)->toBe(BenefitType::Vr)
            ->and($benefit->starts_on->toDateString())->toBe('2026-09-15')
            ->and($benefit->ends_on)->toBeNull()
            ->and($benefit->notes)->toBe('Admissão')
            ->and($benefit->created_by)->toBe($this->user->id);
    });

    test('the start does not need to be the first day of the month', function () {
        benefitsScreen($this->employee)
            ->set('benefitType', 'vt')
            ->set('benefitStartsOn', '2026-09-17')
            ->call('createBenefit')
            ->assertHasNoErrors();

        expect($this->employee->employeeBenefits()->sole()->starts_on->toDateString())->toBe('2026-09-17');
    });

    test('the benefit type is validated', function () {
        benefitsScreen($this->employee)
            ->set('benefitType', 'vale-alimentacao')
            ->set('benefitStartsOn', '2026-09-01')
            ->call('createBenefit')
            ->assertHasErrors('benefitType');

        expect(EmployeeBenefit::count())->toBe(0);
    });

    test('dates are validated', function (?string $startsOn, ?string $endsOn, string $field) {
        benefitsScreen($this->employee)
            ->set('benefitType', 'vt')
            ->set('benefitStartsOn', $startsOn)
            ->set('benefitEndsOn', $endsOn)
            ->call('createBenefit')
            ->assertHasErrors($field);

        expect(EmployeeBenefit::count())->toBe(0);
    })->with([
        'missing start' => [null, null, 'benefitStartsOn'],
        'invalid start' => ['ontem', null, 'benefitStartsOn'],
        'end before start' => ['2026-09-15', '2026-09-14', 'benefitEndsOn'],
    ]);

    test('an overlapping validity of the same type is rejected', function () {
        EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vt)->between('2026-01-01', '2026-10-31')->create();

        benefitsScreen($this->employee)
            ->set('benefitType', 'vt')
            ->set('benefitStartsOn', '2026-10-01')
            ->call('createBenefit')
            ->assertHasErrors('benefitStartsOn');

        expect($this->employee->employeeBenefits()->count())->toBe(1);
    });

    test('another type may start on the same day', function () {
        EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vt)->between('2026-09-01')->create();

        benefitsScreen($this->employee)
            ->set('benefitType', 'vd')
            ->set('benefitStartsOn', '2026-09-01')
            ->call('createBenefit')
            ->assertHasNoErrors();

        expect($this->employee->employeeBenefits()->count())->toBe(2);
    });

    test('a validity is ended without creating a new row', function () {
        $benefit = EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vt)->between('2026-09-01')->create();

        benefitsScreen($this->employee)
            ->call('openEndBenefitModal', $benefit->id)
            ->set('benefitEndDate', '2026-09-30')
            ->call('endBenefit')
            ->assertHasNoErrors()
            ->assertSet('showEndBenefitModal', false);

        expect($this->employee->employeeBenefits()->count())->toBe(1)
            ->and($benefit->fresh()->ends_on->toDateString())->toBe('2026-09-30')
            ->and($benefit->fresh()->updated_by)->toBe($this->user->id);
    });

    test('a validity cannot end before it starts', function () {
        $benefit = EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vt)->between('2026-09-15')->create();

        benefitsScreen($this->employee)
            ->call('openEndBenefitModal', $benefit->id)
            ->set('benefitEndDate', '2026-09-14')
            ->call('endBenefit')
            ->assertHasErrors('benefitEndDate');

        expect($benefit->fresh()->ends_on)->toBeNull();
    });

    test('another employee validity cannot be ended from this screen', function () {
        $foreign = EmployeeBenefit::factory()->ofType(BenefitType::Vt)->between('2026-09-01')->create();

        expect(fn () => benefitsScreen($this->employee)->call('openEndBenefitModal', $foreign->id))
            ->toThrow(ModelNotFoundException::class)
            ->and($foreign->fresh()->ends_on)->toBeNull();
    });
});

describe('itinerary', function () {
    test('a leg is created', function () {
        $fare = pricedFare('CPTM', '5.40');

        benefitsScreen($this->employee)
            ->call('openRouteModal')
            ->set('routeFareId', $fare->id)
            ->set('routeTripsPerDay', '4')
            ->set('routeStartsOn', '2026-09-01')
            ->call('createRoute')
            ->assertHasNoErrors()
            ->assertSet('showRouteModal', false);

        $route = $this->employee->transportRoutes()->sole();

        expect($route->transport_fare_id)->toBe($fare->id)
            ->and($route->trips_per_day)->toBe(4)
            ->and($route->starts_on->toDateString())->toBe('2026-09-01')
            ->and($route->created_by)->toBe($this->user->id);
    });

    test('several simultaneous legs are allowed', function () {
        $cptm = pricedFare('CPTM', '5.40');
        $sp = pricedFare('SP', '8.40');

        foreach ([$cptm, $sp] as $fare) {
            benefitsScreen($this->employee)
                ->set('routeFareId', $fare->id)
                ->set('routeTripsPerDay', '2')
                ->set('routeStartsOn', '2026-09-01')
                ->call('createRoute')
                ->assertHasNoErrors();
        }

        expect($this->employee->transportRoutes()->count())->toBe(2);

        benefitsScreen($this->employee, '2026-10-01')->assertSee('R$ 27,60');
    });

    test('the fare must exist and be active', function (Closure $fareId) {
        benefitsScreen($this->employee)
            ->set('routeFareId', $fareId())
            ->set('routeTripsPerDay', '2')
            ->set('routeStartsOn', '2026-09-01')
            ->call('createRoute')
            ->assertHasErrors('routeFareId');

        expect(TransportRoute::count())->toBe(0);
    })->with([
        'missing' => [fn () => null],
        'unknown' => [fn () => 999],
        'inactive' => [fn () => TransportFare::factory()->inactive()->create()->id],
    ]);

    test('trips per day must be a positive integer', function (string $tripsPerDay) {
        benefitsScreen($this->employee)
            ->set('routeFareId', pricedFare('CPTM', '5.40')->id)
            ->set('routeTripsPerDay', $tripsPerDay)
            ->set('routeStartsOn', '2026-09-01')
            ->call('createRoute')
            ->assertHasErrors('routeTripsPerDay');

        expect(TransportRoute::count())->toBe(0);
    })->with(['', '0', '-1', '1.5', 'duas', '256']);

    test('the validity of the leg is validated', function (?string $startsOn, ?string $endsOn, string $field) {
        benefitsScreen($this->employee)
            ->set('routeFareId', pricedFare('CPTM', '5.40')->id)
            ->set('routeTripsPerDay', '2')
            ->set('routeStartsOn', $startsOn)
            ->set('routeEndsOn', $endsOn)
            ->call('createRoute')
            ->assertHasErrors($field);

        expect(TransportRoute::count())->toBe(0);
    })->with([
        'missing start' => [null, null, 'routeStartsOn'],
        'end before start' => ['2026-09-15', '2026-09-01', 'routeEndsOn'],
    ]);

    test('the same fare cannot overlap', function () {
        $fare = pricedFare('CPTM', '5.40');
        TransportRoute::factory()->for($this->employee)->for($fare)->between('2026-09-01')->create();

        benefitsScreen($this->employee)
            ->set('routeFareId', $fare->id)
            ->set('routeTripsPerDay', '4')
            ->set('routeStartsOn', '2026-10-01')
            ->call('createRoute')
            ->assertHasErrors('routeStartsOn');

        expect(TransportRoute::count())->toBe(1);
    });

    test('a leg is ended without creating a new row', function () {
        $route = TransportRoute::factory()->for($this->employee)->for(pricedFare('CPTM', '5.40'))->between('2026-09-01')->create();

        benefitsScreen($this->employee)
            ->call('openEndRouteModal', $route->id)
            ->set('routeEndDate', '2026-09-30')
            ->call('endRoute')
            ->assertHasNoErrors()
            ->assertSet('showEndRouteModal', false);

        expect(TransportRoute::count())->toBe(1)
            ->and($route->fresh()->ends_on->toDateString())->toBe('2026-09-30');
    });

    test('a leg cannot end before it starts', function () {
        $route = TransportRoute::factory()->for($this->employee)->for(pricedFare('CPTM', '5.40'))->between('2026-09-15')->create();

        benefitsScreen($this->employee)
            ->call('openEndRouteModal', $route->id)
            ->set('routeEndDate', '2026-09-01')
            ->call('endRoute')
            ->assertHasErrors('routeEndDate');

        expect($route->fresh()->ends_on)->toBeNull();
    });

    test('the reference date shown on the screen drives the displayed daily amount', function () {
        $cptm = pricedFare('CPTM', '5.40', '2026-09-01');
        TransportFarePrice::factory()->for($cptm)->amount('5.80')->validFrom('2026-10-15')->create();
        TransportRoute::factory()->for($this->employee)->for($cptm)->tripsPerDay(2)->between('2026-09-01')->create();

        benefitsScreen($this->employee, '2026-10-01')->assertSee('R$ 10,80/dia');
        benefitsScreen($this->employee, '2026-11-01')->assertSee('R$ 11,60/dia');
    });

    test('the default reference is today', function () {
        CarbonImmutable::setTestNow('2026-10-20');

        Livewire::test(EmployeeBenefitsEdit::class, ['employee' => $this->employee])
            ->assertSet('referenceDate', '2026-10-20');

        CarbonImmutable::setTestNow();
    });
});
