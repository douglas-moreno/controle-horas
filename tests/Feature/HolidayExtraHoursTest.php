<?php

use App\Exports\EmployeeResumeReportExport;
use App\Livewire\EmployeeHorasExtras;
use App\Livewire\EmployeeResumeReport;
use App\Livewire\EmployeesExtraReport;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Point;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * 2026-07-09 é uma quinta-feira: jornada padrão de 9h, logo 9h trabalhadas
 * resultam em zero hora extra quando o dia não é feriado.
 */
const HOLIDAY_DATE = '2026-07-09';

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * @param  array<int, string>  $times
 */
function employeeWithPoints(array $times = ['07:30', '12:00', '13:00', '17:30'], string $date = HOLIDAY_DATE): Employee
{
    $employee = Employee::create([
        'pis' => '12345678901',
        'name' => 'Funcionário Teste',
        'position' => 'Operador',
    ]);

    // Insere como a importação de pontos faz, mantendo a data no formato Y-m-d
    Point::insert(collect($times)->map(fn (string $time) => [
        'pis' => $employee->pis,
        'date' => $date,
        'time' => $time.':00',
        'type' => 'importado',
        'created_at' => now(),
        'updated_at' => now(),
    ])->all());

    return $employee;
}

function extraHoursComponent(Employee $employee, string $date = HOLIDAY_DATE): Testable
{
    return Livewire::test(EmployeeHorasExtras::class, ['employee' => $employee->id])
        ->set('startDate', $date)
        ->set('endDate', $date);
}

test('a normal weekday keeps the existing overtime rules', function () {
    $employee = employeeWithPoints();

    extraHoursComponent($employee)
        ->assertSet('totalExtraHours', '00:00');
});

test('every worked hour on a holiday becomes overtime', function () {
    Holiday::create(['date' => HOLIDAY_DATE, 'description' => 'Revolução Constitucionalista']);

    $employee = employeeWithPoints();

    extraHoursComponent($employee)
        ->assertSet('totalExtraHours', '09:00')
        ->assertSet('totalHolidayHours', '09:00')
        ->assertSet('totalWeekdayHours', '00:00');
});

test('a holiday without a marked lunch break discounts one hour after six worked hours', function () {
    Holiday::create(['date' => HOLIDAY_DATE, 'description' => 'Revolução Constitucionalista']);

    $employee = employeeWithPoints(['07:30', '17:30']);

    extraHoursComponent($employee)
        ->assertSet('totalExtraHours', '09:00');
});

test('a holiday shorter than six hours is fully counted as overtime', function () {
    Holiday::create(['date' => HOLIDAY_DATE, 'description' => 'Revolução Constitucionalista']);

    $employee = employeeWithPoints(['08:00', '12:00']);

    extraHoursComponent($employee)
        ->assertSet('totalExtraHours', '04:00');
});

test('holidays do not change overtime of the surrounding days', function () {
    Holiday::create(['date' => HOLIDAY_DATE, 'description' => 'Revolução Constitucionalista']);

    // 2026-07-08 (quarta-feira), 10h trabalhadas = 1h extra pela regra atual
    $employee = employeeWithPoints(['07:30', '12:00', '13:00', '18:30'], '2026-07-08');

    Livewire::test(EmployeeHorasExtras::class, ['employee' => $employee->id])
        ->set('startDate', '2026-07-08')
        ->set('endDate', '2026-07-08')
        ->assertSet('totalExtraHours', '01:00');
});

test('a holiday falling on a weekend is counted in the holiday column', function () {
    // 2026-07-11 é um sábado
    Holiday::create(['date' => '2026-07-11', 'description' => 'Feriado no sábado']);

    $employee = employeeWithPoints(['07:30', '12:00', '13:00', '17:30'], '2026-07-11');

    extraHoursComponent($employee, '2026-07-11')
        ->assertSet('totalHolidayHours', '09:00')
        ->assertSet('totalSaturdayHours', '00:00')
        ->assertSet('totalExtraHours', '09:00');
});

test('a saturday without holiday keeps counting in the saturday column', function () {
    $employee = employeeWithPoints(['07:30', '12:00', '13:00', '17:30'], '2026-07-11');

    extraHoursComponent($employee, '2026-07-11')
        ->assertSet('totalSaturdayHours', '09:00')
        ->assertSet('totalHolidayHours', '00:00');
});

test('the extra hours report counts holiday hours as overtime', function () {
    Holiday::create(['date' => HOLIDAY_DATE, 'description' => 'Revolução Constitucionalista']);

    employeeWithPoints();

    $component = Livewire::test(EmployeesExtraReport::class)
        ->set('minutesFilter', 0)
        ->set('startDate', HOLIDAY_DATE)
        ->set('endDate', HOLIDAY_DATE);

    expect($component->get('results'))->toHaveCount(1)
        ->and($component->get('results')[0]['hours'])->toBe('09:00')
        ->and($component->get('results')[0]['holiday_hours'])->toBe('09:00');
});

test('the resume report counts holiday hours as overtime', function () {
    Holiday::create(['date' => HOLIDAY_DATE, 'description' => 'Revolução Constitucionalista']);

    employeeWithPoints();

    $component = Livewire::test(EmployeeResumeReport::class)
        ->set('startDate', HOLIDAY_DATE)
        ->set('endDate', HOLIDAY_DATE);

    expect($component->get('results')[0]['total_hours'])->toBe('09:00')
        ->and($component->get('results')[0]['holiday_hours'])->toBe('09:00')
        ->and($component->get('results')[0]['weekday_hours'])->toBe('00:00');
});

test('the resume export has a holiday column', function () {
    Holiday::create(['date' => HOLIDAY_DATE, 'description' => 'Revolução Constitucionalista']);

    employeeWithPoints();

    $results = Livewire::test(EmployeeResumeReport::class)
        ->set('startDate', HOLIDAY_DATE)
        ->set('endDate', HOLIDAY_DATE)
        ->get('results');

    $export = new EmployeeResumeReportExport($results, HOLIDAY_DATE, HOLIDAY_DATE);

    expect($export->headings())->toBe(['Nome', 'Seg-Sex', 'Sábado', 'Domingo', 'Feriado', 'Total'])
        ->and($export->array()[0])->toBe(['Funcionário Teste', '00:00', '00:00', '00:00', '09:00', '09:00']);
});

test('the resume report keeps zero overtime on a regular workday', function () {
    employeeWithPoints();

    $component = Livewire::test(EmployeeResumeReport::class)
        ->set('startDate', HOLIDAY_DATE)
        ->set('endDate', HOLIDAY_DATE);

    expect($component->get('results')[0]['total_hours'])->toBe('00:00');
});
