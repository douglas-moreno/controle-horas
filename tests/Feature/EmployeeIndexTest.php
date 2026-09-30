<?php

use App\Livewire\EmployeeIndex;
use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriodEmployee;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\Point;
use App\Models\TransportRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the employee list renders active employees', function () {
    $employee = Employee::factory()->create(['name' => 'Funcionário Ativo']);
    Employee::factory()->terminated()->create(['name' => 'Funcionário Desligado']);

    Livewire::test(EmployeeIndex::class)
        ->assertSee($employee->name)
        ->assertDontSee('Funcionário Desligado');
});

test('an employee without history can still be deleted', function () {
    $employee = Employee::factory()->create(['name' => 'Funcionário Excluído']);
    $otherEmployee = Employee::factory()->create(['name' => 'Funcionário Mantido']);

    Livewire::test(EmployeeIndex::class)
        ->call('destroy', $employee->id)
        ->assertHasNoErrors()
        ->assertDontSee('Funcionário Excluído')
        ->assertSee('Funcionário Mantido');

    $this->assertModelMissing($employee);
    $this->assertModelExists($otherEmployee);
});

test('deleting an employee keeps the time clock punches untouched', function () {
    $employee = Employee::factory()->create();
    $otherEmployee = Employee::factory()->create();
    Point::factory()->forEmployee($employee)->count(2)->create();
    Point::factory()->forEmployee($otherEmployee)->count(3)->create();

    Livewire::test(EmployeeIndex::class)->call('destroy', $employee->id);

    expect(Point::count())->toBe(5)
        ->and($otherEmployee->points()->count())->toBe(3);
});

test('the list links each employee to the benefits screen', function () {
    $employee = Employee::factory()->create();

    Livewire::test(EmployeeIndex::class)
        ->assertSee(route('employees.benefits', $employee))
        ->assertSee('Benefícios');
});

test('an employee with benefit history cannot be deleted', function (Closure $createHistory) {
    $employee = Employee::factory()->create(['name' => 'Funcionário Com Histórico']);
    $createHistory($employee);

    Livewire::test(EmployeeIndex::class)
        ->call('destroy', $employee->id)
        ->assertSee('Funcionário Com Histórico');

    $this->assertModelExists($employee);
})->with([
    'eligibility' => [fn (Employee $employee) => EmployeeBenefit::factory()->for($employee)->create()],
    'itinerary' => [fn (Employee $employee) => TransportRoute::factory()->for($employee)->create()],
    'adjustment' => [fn (Employee $employee) => BenefitAdjustment::factory()->for($employee)->create()],
    'period participant' => [fn (Employee $employee) => BenefitPeriodEmployee::factory()->for($employee)->create()],
]);

test('an employee with only time clock punches can still be deleted', function () {
    $employee = Employee::factory()->create();
    Point::factory()->forEmployee($employee)->count(3)->create();

    Livewire::test(EmployeeIndex::class)->call('destroy', $employee->id);

    $this->assertModelMissing($employee);
});

test('a terminated employee without benefit history can still be deleted', function () {
    $employee = Employee::factory()->terminated()->create();

    Livewire::test(EmployeeIndex::class)->call('destroy', $employee->id);

    $this->assertModelMissing($employee);
});
