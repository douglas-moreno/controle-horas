<?php

use App\Livewire\EmployeeResumeReport;
use App\Models\Employee;
use App\Models\Point;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * 2026-07-11 é um sábado: toda hora trabalhada vira hora extra,
 * então o total de cada funcionário é controlado pelo horário de saída.
 */
const SORTING_DATE = '2026-07-11';

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function employeeWorkingUntil(string $pis, string $name, string $saida): Employee
{
    $employee = Employee::create([
        'pis' => $pis,
        'name' => $name,
        'position' => 'Operador',
    ]);

    Point::insert(collect(['07:00', $saida])->map(fn (string $time) => [
        'pis' => $employee->pis,
        'date' => SORTING_DATE,
        'time' => $time.':00',
        'type' => 'importado',
        'created_at' => now(),
        'updated_at' => now(),
    ])->all());

    return $employee;
}

function resumeReportComponent(): Testable
{
    return Livewire::test(EmployeeResumeReport::class)
        ->set('startDate', SORTING_DATE)
        ->set('endDate', SORTING_DATE);
}

/**
 * @return array<int, string>
 */
function reportNames(Testable $component): array
{
    return array_map(fn (array $row) => $row['employee']->name, $component->get('results'));
}

beforeEach(function () {
    employeeWorkingUntil('11111111111', 'Carlos', '09:00');   // 2h
    employeeWorkingUntil('22222222222', 'Ana', '12:00');      // 5h
    employeeWorkingUntil('33333333333', 'Ávila', '10:00');    // 3h
});

test('results are sorted by total overtime descending by default', function () {
    $component = resumeReportComponent();

    expect($component->get('sortField'))->toBe('total_minutes')
        ->and($component->get('sortDirection'))->toBe('desc')
        ->and(reportNames($component))->toBe(['Ana', 'Ávila', 'Carlos']);
});

test('sorting by name orders alphabetically ascending', function () {
    $component = resumeReportComponent()->call('sortBy', 'name');

    expect($component->get('sortField'))->toBe('name')
        ->and($component->get('sortDirection'))->toBe('asc')
        ->and(reportNames($component))->toBe(['Ana', 'Ávila', 'Carlos']);
});

test('clicking the name column twice reverses the alphabetical order', function () {
    $component = resumeReportComponent()
        ->call('sortBy', 'name')
        ->call('sortBy', 'name');

    expect($component->get('sortDirection'))->toBe('desc')
        ->and(reportNames($component))->toBe(['Carlos', 'Ávila', 'Ana']);
});

test('sorting back by total returns to the descending overtime order', function () {
    $component = resumeReportComponent()
        ->call('sortBy', 'name')
        ->call('sortBy', 'total_minutes');

    expect($component->get('sortField'))->toBe('total_minutes')
        ->and($component->get('sortDirection'))->toBe('desc')
        ->and(reportNames($component))->toBe(['Ana', 'Ávila', 'Carlos']);
});

test('sorting by total ascending puts the smallest overtime first', function () {
    $component = resumeReportComponent()
        ->call('sortBy', 'total_minutes');

    expect($component->get('sortDirection'))->toBe('asc')
        ->and(reportNames($component))->toBe(['Carlos', 'Ávila', 'Ana']);
});

test('an unknown sort field is ignored', function () {
    $component = resumeReportComponent()->call('sortBy', 'employee');

    expect($component->get('sortField'))->toBe('total_minutes')
        ->and($component->get('sortDirection'))->toBe('desc');
});

test('the period navigation keeps the chosen sorting', function () {
    $component = resumeReportComponent()
        ->call('sortBy', 'name')
        ->call('periodoAnterior');

    expect($component->get('sortField'))->toBe('name')
        ->and($component->get('sortDirection'))->toBe('asc');
});
