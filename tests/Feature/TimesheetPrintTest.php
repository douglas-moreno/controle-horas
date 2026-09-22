<?php

use App\Livewire\TimesheetPrint;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Point;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * @param  array<string, array<int, string>>  $pointsByDate
 */
function timesheetEmployee(string $pis, string $name, array $pointsByDate = [], ?string $recisionDate = null): Employee
{
    $employee = Employee::create([
        'pis' => $pis,
        'name' => $name,
        'position' => 'Operador',
        'recision_date' => $recisionDate,
    ]);

    foreach ($pointsByDate as $date => $times) {
        Point::insert(collect($times)->map(fn (string $time) => [
            'pis' => $employee->pis,
            'date' => $date,
            'time' => $time.':00',
            'type' => 'importado',
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());
    }

    return $employee;
}

test('the timesheet page lists only active employees', function () {
    timesheetEmployee('11111111111', 'Ativo');
    timesheetEmployee('22222222222', 'Demitido', recisionDate: '2026-01-10');

    $this->get(route('reports.timesheet'))
        ->assertOk()
        ->assertSee('Ativo')
        ->assertDontSee('Demitido');
});

test('select all and clear selection toggle every active employee', function () {
    $ana = timesheetEmployee('11111111111', 'Ana');
    $bruno = timesheetEmployee('22222222222', 'Bruno');
    timesheetEmployee('33333333333', 'Demitido', recisionDate: '2026-01-10');

    $component = Livewire::test(TimesheetPrint::class)->call('selectAll');

    expect($component->get('employeeIds'))->toBe([(string) $ana->id, (string) $bruno->id]);

    $component->call('clearSelection');

    expect($component->get('employeeIds'))->toBe([]);
});

test('the print button links to the print page with the selected period and employees', function () {
    $ana = timesheetEmployee('11111111111', 'Ana');

    Livewire::test(TimesheetPrint::class)
        ->set('startDate', '2026-06-26')
        ->set('endDate', '2026-07-25')
        ->set('employeeIds', [(string) $ana->id])
        ->assertSee(e(route('reports.timesheet.print', [
            'start' => '2026-06-26',
            'end' => '2026-07-25',
            'employees' => [$ana->id],
        ])), false);
});

test('the print page shows the period and every day with the punches in their columns', function () {
    Holiday::factory()->create(['date' => '2026-06-29', 'description' => 'São Pedro']);

    $ana = timesheetEmployee('11111111111', 'Ana', [
        '2026-06-26' => ['07:30', '12:00', '13:00', '17:30'],
        '2026-06-27' => ['08:00', '12:00'],
    ]);

    $this->get(route('reports.timesheet.print', [
        'start' => '2026-06-26',
        'end' => '2026-06-29',
        'employees' => [$ana->id],
    ]))
        ->assertOk()
        ->assertSee('26/06/2026 a 29/06/2026')
        ->assertSee('Ana')
        ->assertSeeInOrder(['Data', 'Entrada', 'Almoço Início', 'Almoço Fim', 'Saída', 'Observação'])
        ->assertSeeInOrder(['26/06/2026 Sex', '07:30', '12:00', '13:00', '17:30'])
        ->assertSeeInOrder(['27/06/2026 Sáb', '08:00', '12:00'])
        ->assertSee('28/06/2026 Dom')
        ->assertSeeInOrder(['29/06/2026 Seg', 'Feriado: São Pedro']);
});

test('the print page includes only the selected active employees', function () {
    $ana = timesheetEmployee('11111111111', 'Ana');
    timesheetEmployee('22222222222', 'Bruno');
    $demitido = timesheetEmployee('33333333333', 'Demitido', recisionDate: '2026-01-10');

    $this->get(route('reports.timesheet.print', [
        'start' => '2026-06-26',
        'end' => '2026-06-27',
        'employees' => [$ana->id, $demitido->id],
    ]))
        ->assertOk()
        ->assertSee('Ana')
        ->assertDontSee('Bruno')
        ->assertDontSee('Demitido');
});

test('the print page validates the period and the employee selection', function () {
    $this->get(route('reports.timesheet.print', [
        'start' => '2026-06-26',
        'end' => '2026-06-20',
    ]))->assertSessionHasErrors(['end', 'employees']);
});

test('guests cannot access the timesheet pages', function () {
    auth()->logout();

    $this->get(route('reports.timesheet'))->assertRedirect(route('login'));
    $this->get(route('reports.timesheet.print'))->assertRedirect(route('login'));
});
