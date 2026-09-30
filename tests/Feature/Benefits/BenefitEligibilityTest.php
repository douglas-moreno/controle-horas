<?php

use App\Enums\BenefitType;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Services\BenefitEligibility;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->eligibility = new BenefitEligibility;
    $this->employee = Employee::factory()->create();
});

function eligibleOn(Employee $employee, BenefitType $benefitType, string $date): bool
{
    return (new BenefitEligibility)->isEligible($employee, $benefitType, CarbonImmutable::parse($date));
}

test('an open validity makes the employee eligible from its start', function () {
    EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vt)->between('2026-09-01')->create();

    expect(eligibleOn($this->employee, BenefitType::Vt, '2026-09-01'))->toBeTrue()
        ->and(eligibleOn($this->employee, BenefitType::Vt, '2030-01-01'))->toBeTrue()
        ->and(eligibleOn($this->employee, BenefitType::Vt, '2026-08-31'))->toBeFalse();
});

test('a closed validity covers only its interval including both ends', function (string $date, bool $expected) {
    EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vt)->between('2026-09-01', '2026-09-30')->create();

    expect(eligibleOn($this->employee, BenefitType::Vt, $date))->toBe($expected);
})->with([
    'first day' => ['2026-09-01', true],
    'middle' => ['2026-09-15', true],
    'last day' => ['2026-09-30', true],
    'reference of the next period' => ['2026-10-01', false],
]);

test('a validity starting mid month counts only from the next reference', function () {
    EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vt)->between('2026-09-15', '2026-10-31')->create();

    expect(eligibleOn($this->employee, BenefitType::Vt, '2026-09-01'))->toBeFalse()
        ->and(eligibleOn($this->employee, BenefitType::Vt, '2026-10-01'))->toBeTrue()
        ->and(eligibleOn($this->employee, BenefitType::Vt, '2026-11-01'))->toBeFalse();
});

test('a validity ending exactly on the reference still counts', function () {
    EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vr)->between('2026-09-01', '2026-10-01')->create();

    expect(eligibleOn($this->employee, BenefitType::Vr, '2026-10-01'))->toBeTrue();
});

test('a future validity does not count yet', function () {
    EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vr)->between('2026-10-01')->create();

    expect(eligibleOn($this->employee, BenefitType::Vr, '2026-09-01'))->toBeFalse();
});

test('benefit types are independent', function () {
    EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vt)->between('2026-09-01')->create();
    EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vr)->between('2026-09-01')->create();
    EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vd)->between('2026-09-01', '2026-09-30')->create();

    $reference = CarbonImmutable::parse('2026-10-01');

    expect(eligibleOn($this->employee, BenefitType::Vt, '2026-10-01'))->toBeTrue()
        ->and(eligibleOn($this->employee, BenefitType::Vr, '2026-10-01'))->toBeTrue()
        ->and(eligibleOn($this->employee, BenefitType::Vd, '2026-10-01'))->toBeFalse()
        ->and($this->eligibility->eligibleTypes($this->employee, $reference))->toBe([BenefitType::Vt, BenefitType::Vr]);
});

test('an employee without vt may still receive vr and vd', function () {
    EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vr)->between('2026-09-01')->create();
    EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vd)->between('2026-09-01')->create();

    expect($this->eligibility->eligibleTypes($this->employee, CarbonImmutable::parse('2026-10-01')))
        ->toBe([BenefitType::Vr, BenefitType::Vd]);
});

test('the recision date does not cancel an open validity', function () {
    $terminated = Employee::factory()->terminated('2026-08-15')->create();
    EmployeeBenefit::factory()->for($terminated)->ofType(BenefitType::Vt)->between('2026-01-01')->create();

    expect(eligibleOn($terminated, BenefitType::Vt, '2026-10-01'))->toBeTrue()
        ->and($this->eligibility->participants(CarbonImmutable::parse('2026-10-01'))->keys()->all())->toBe([$terminated->id]);
});

test('an employee without validities is not eligible', function () {
    expect(eligibleOn($this->employee, BenefitType::Vt, '2026-10-01'))->toBeFalse()
        ->and($this->eligibility->eligibleTypes($this->employee, CarbonImmutable::parse('2026-10-01')))->toBe([]);
});

test('participants list each employee with the types in force on the reference', function () {
    $other = Employee::factory()->create();
    $withoutBenefits = Employee::factory()->create();
    $expired = Employee::factory()->create();

    EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vd)->between('2026-09-01')->create();
    EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vt)->between('2026-09-01')->create();
    EmployeeBenefit::factory()->for($other)->ofType(BenefitType::Vr)->between('2026-10-01')->create();
    EmployeeBenefit::factory()->for($expired)->ofType(BenefitType::Vr)->between('2026-01-01', '2026-09-30')->create();

    $participants = $this->eligibility->participants(CarbonImmutable::parse('2026-10-01'));

    expect($participants->keys()->sort()->values()->all())->toBe(collect([$this->employee->id, $other->id])->sort()->values()->all())
        ->and($participants->get($this->employee->id))->toBe([BenefitType::Vt, BenefitType::Vd])
        ->and($participants->get($other->id))->toBe([BenefitType::Vr])
        ->and($participants->has($withoutBenefits->id))->toBeFalse()
        ->and($participants->has($expired->id))->toBeFalse();
});
