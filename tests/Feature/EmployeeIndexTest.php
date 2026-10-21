<?php

use App\Livewire\EmployeeIndex;
use App\Models\AfdImport;
use App\Models\AfdRep;
use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriodEmployee;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\Point;
use App\Models\TransportRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Support\AfdFileBuilder;

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

test('the import of an afd shows the totals and creates only the punches', function () {
    $contents = AfdFileBuilder::make()
        ->company()
        ->employee('012345678901')
        ->punch('012345678901', '15092026', '0812')
        ->punch('012345678901', '15092026', '1730')
        ->punch('012345678901', '31022026', '1800')
        ->contents();

    Livewire::test(EmployeeIndex::class)
        ->set('file', UploadedFile::fake()->createWithContent('afd.txt', $contents))
        ->call('importPoints')
        ->assertDispatched('wireui:notification', function (string $name, array $params) {
            $options = $params[0]['options'];
            $description = $options['description'];

            return $options['title'] === 'Importação Concluída'
                && str_contains($description, '2 marcação(ões) importada(s); 0 já existente(s)')
                && str_contains($description, '1 de empresa, 0 de ajuste do relógio e 1 de empregado')
                && str_contains($description, '1 marcação(ões) inválida(s), não importada(s): NSR 5 (Data inválida)')
                && str_contains($description, 'Processado até o NSR 5')
                && ! str_contains($description, '12345678901');
        });

    expect(Point::query()->orderBy('nsr')->pluck('nsr')->all())->toBe([3, 4])
        ->and(AfdImport::sole()->user_id)->toBe(auth()->id());
});

test('an invalid afd shows the error and writes nothing', function () {
    $contents = AfdFileBuilder::make()
        ->punch('012345678901', '15092026', '0812')
        ->trailerCounts(['company' => 0, 'punch' => 2, 'clock_adjustment' => 0, 'employee' => 0])
        ->contents();

    Livewire::test(EmployeeIndex::class)
        ->set('file', UploadedFile::fake()->createWithContent('afd.txt', $contents))
        ->call('importPoints')
        ->assertDispatched('wireui:notification', fn (string $name, array $params) => $params[0]['options']['title'] === 'Erro na Importação'
            && str_contains($params[0]['options']['description'], 'contadores do trailer'));

    expect(Point::count())->toBe(0)
        ->and(AfdRep::count())->toBe(0)
        ->and(AfdImport::count())->toBe(0);
});
