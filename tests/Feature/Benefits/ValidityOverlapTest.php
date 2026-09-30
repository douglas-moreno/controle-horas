<?php

use App\Enums\BenefitType;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\TransportFare;
use App\Models\TransportRoute;
use App\Models\User;
use App\Services\EmployeeBenefitRegistrar;
use App\Services\TransportRouteRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function validityDay(?string $date): ?CarbonImmutable
{
    return $date === null ? null : CarbonImmutable::parse($date);
}

describe('employee benefit validities', function () {
    beforeEach(function () {
        $this->registrar = new EmployeeBenefitRegistrar;
        $this->employee = Employee::factory()->create();
    });

    test('overlapping intervals of the same type are rejected', function (string $existingStart, ?string $existingEnd, string $newStart, ?string $newEnd) {
        EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vt)->between($existingStart, $existingEnd)->create();

        expect($this->registrar->overlaps($this->employee, BenefitType::Vt, validityDay($newStart), validityDay($newEnd)))->toBeTrue()
            ->and(fn () => $this->registrar->register($this->employee, BenefitType::Vt, validityDay($newStart), validityDay($newEnd)))
            ->toThrow(InvalidArgumentException::class, 'sobrepõe');
    })->with([
        'fully inside' => ['2026-01-01', '2026-12-31', '2026-03-01', '2026-03-31'],
        'start inside' => ['2026-01-01', '2026-03-31', '2026-03-15', null],
        'end inside' => ['2026-03-01', '2026-06-30', '2026-01-01', '2026-03-01'],
        'surrounding' => ['2026-03-01', '2026-03-31', '2026-01-01', '2026-12-31'],
        'two open validities' => ['2026-01-01', null, '2026-05-01', null],
        'new open before an open one' => ['2026-05-01', null, '2026-01-01', null],
        'same single day' => ['2026-09-15', '2026-09-15', '2026-09-15', '2026-09-15'],
    ]);

    test('consecutive intervals are accepted', function () {
        $this->registrar->register($this->employee, BenefitType::Vt, validityDay('2026-09-01'), validityDay('2026-09-30'));
        $this->registrar->register($this->employee, BenefitType::Vt, validityDay('2026-10-01'), null);
        $this->registrar->register($this->employee, BenefitType::Vt, validityDay('2026-01-01'), validityDay('2026-08-31'));

        expect($this->employee->employeeBenefits()->count())->toBe(3);
    });

    test('different types of the same employee may coexist', function () {
        foreach (BenefitType::cases() as $benefitType) {
            $this->registrar->register($this->employee, $benefitType, validityDay('2026-09-01'), null);
        }

        expect($this->employee->employeeBenefits()->count())->toBe(3);
    });

    test('different employees may have identical validities', function () {
        $other = Employee::factory()->create();

        $this->registrar->register($this->employee, BenefitType::Vt, validityDay('2026-09-01'), null);
        $this->registrar->register($other, BenefitType::Vt, validityDay('2026-09-01'), null);

        expect(EmployeeBenefit::count())->toBe(2);
    });

    test('an end before the start is rejected', function () {
        $this->registrar->register($this->employee, BenefitType::Vt, validityDay('2026-09-15'), validityDay('2026-09-14'));
    })->throws(InvalidArgumentException::class, 'anterior ao início');

    test('the registration records notes and the author', function () {
        $user = User::factory()->create();

        $benefit = $this->registrar->register($this->employee, BenefitType::Vr, validityDay('2026-09-01'), null, 'Contratação', $user->id);

        expect($benefit->fresh()->only('notes', 'created_by', 'updated_by'))
            ->toBe(['notes' => 'Contratação', 'created_by' => $user->id, 'updated_by' => $user->id]);
    });

    test('ending a validity updates the same row', function () {
        $user = User::factory()->create();
        $benefit = $this->registrar->register($this->employee, BenefitType::Vt, validityDay('2026-09-01'), null);

        $this->registrar->end($benefit, validityDay('2026-09-30'), $user->id);

        expect($this->employee->employeeBenefits()->count())->toBe(1)
            ->and($benefit->fresh()->ends_on->toDateString())->toBe('2026-09-30')
            ->and($benefit->fresh()->updated_by)->toBe($user->id);
    });

    test('ending on the start day is allowed but before it is not', function () {
        $benefit = $this->registrar->register($this->employee, BenefitType::Vt, validityDay('2026-09-15'), null);

        $this->registrar->end($benefit, validityDay('2026-09-15'));

        expect($benefit->fresh()->ends_on->toDateString())->toBe('2026-09-15')
            ->and(fn () => $this->registrar->end($benefit->fresh(), validityDay('2026-09-14')))->toThrow(InvalidArgumentException::class);
    });

    test('extending an end over the next validity is rejected', function () {
        $first = $this->registrar->register($this->employee, BenefitType::Vt, validityDay('2026-09-01'), validityDay('2026-09-30'));
        $this->registrar->register($this->employee, BenefitType::Vt, validityDay('2026-10-01'), null);

        expect(fn () => $this->registrar->end($first, validityDay('2026-10-15')))->toThrow(InvalidArgumentException::class, 'sobrepor');
        expect($first->fresh()->ends_on->toDateString())->toBe('2026-09-30');
    });
});

describe('transport route validities', function () {
    beforeEach(function () {
        $this->registrar = new TransportRouteRegistrar;
        $this->employee = Employee::factory()->create();
        $this->cptm = TransportFare::factory()->create(['name' => 'CPTM']);
        $this->sp = TransportFare::factory()->create(['name' => 'SP']);
    });

    test('several simultaneous legs with different fares are allowed', function () {
        $this->registrar->register($this->employee, $this->cptm, 2, validityDay('2026-09-01'), null);
        $this->registrar->register($this->employee, $this->sp, 2, validityDay('2026-09-01'), null);

        expect($this->employee->transportRoutes()->count())->toBe(2);
    });

    test('the same fare cannot overlap for the same employee', function () {
        $this->registrar->register($this->employee, $this->cptm, 2, validityDay('2026-09-01'), null);

        expect(fn () => $this->registrar->register($this->employee, $this->cptm, 4, validityDay('2026-10-01'), null))
            ->toThrow(InvalidArgumentException::class, 'sobrepõe');
    });

    test('changing the itinerary means ending the old leg and starting a new one', function () {
        $old = $this->registrar->register($this->employee, $this->cptm, 2, validityDay('2026-09-01'), null);

        $this->registrar->end($old, validityDay('2026-10-14'));
        $this->registrar->register($this->employee, $this->cptm, 4, validityDay('2026-10-15'), null);

        expect($this->employee->transportRoutes()->count())->toBe(2)
            ->and($old->fresh()->trips_per_day)->toBe(2)
            ->and($old->fresh()->ends_on->toDateString())->toBe('2026-10-14');
    });

    test('different employees may use the same fare at the same time', function () {
        $this->registrar->register($this->employee, $this->cptm, 2, validityDay('2026-09-01'), null);
        $this->registrar->register(Employee::factory()->create(), $this->cptm, 2, validityDay('2026-09-01'), null);

        expect(TransportRoute::count())->toBe(2);
    });

    test('trips per day must be positive and fit the column', function (int $tripsPerDay) {
        $this->registrar->register($this->employee, $this->cptm, $tripsPerDay, validityDay('2026-09-01'), null);
    })->throws(InvalidArgumentException::class)->with([0, -1, 256]);

    test('an end before the start is rejected', function () {
        $this->registrar->register($this->employee, $this->cptm, 2, validityDay('2026-09-15'), validityDay('2026-09-01'));
    })->throws(InvalidArgumentException::class, 'anterior ao início');

    test('ending a leg keeps its fare and updates the same row', function () {
        $user = User::factory()->create();
        $route = $this->registrar->register($this->employee, $this->cptm, 2, validityDay('2026-09-01'), null, null, $user->id);

        $this->registrar->end($route, validityDay('2026-09-30'), $user->id);

        expect(TransportRoute::count())->toBe(1)
            ->and($route->fresh()->transport_fare_id)->toBe($this->cptm->id)
            ->and($route->fresh()->ends_on->toDateString())->toBe('2026-09-30')
            ->and($route->fresh()->only('created_by', 'updated_by'))->toBe(['created_by' => $user->id, 'updated_by' => $user->id]);
    });
});
