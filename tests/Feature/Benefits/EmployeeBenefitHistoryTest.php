<?php

use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriodEmployee;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\Point;
use App\Models\TransportRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an employee without benefit records has no history', function () {
    expect(Employee::factory()->create()->hasBenefitHistory())->toBeFalse();
});

test('any benefit record counts as history', function (Closure $createHistory) {
    $employee = Employee::factory()->create();
    $otherEmployee = Employee::factory()->create();

    $createHistory($employee);

    expect($employee->hasBenefitHistory())->toBeTrue()
        ->and($otherEmployee->hasBenefitHistory())->toBeFalse();
})->with([
    'employee benefit' => [fn (Employee $employee) => EmployeeBenefit::factory()->for($employee)->create()],
    'transport route' => [fn (Employee $employee) => TransportRoute::factory()->for($employee)->create()],
    'benefit adjustment' => [fn (Employee $employee) => BenefitAdjustment::factory()->for($employee)->create()],
    'benefit period employee' => [fn (Employee $employee) => BenefitPeriodEmployee::factory()->for($employee)->create()],
]);

test('time clock punches are not benefit history', function () {
    $employee = Employee::factory()->create();
    Point::factory()->forEmployee($employee)->count(3)->create();

    expect($employee->points()->count())->toBe(3)
        ->and($employee->hasBenefitHistory())->toBeFalse();
});

test('a terminated employee without benefit records has no history', function () {
    expect(Employee::factory()->terminated()->create()->hasBenefitHistory())->toBeFalse();
});
