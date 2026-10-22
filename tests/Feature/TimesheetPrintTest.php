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
        ->assertSeeInOrder(['Data', 'Marcações', 'Hora', 'Extra', 'Atraso', 'Ocorrência', 'Justificativa da irregularidade', 'Abono da chefia', 'Ent.', 'Saída', 'Ent.', 'Saída'])
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

test('the print page shows overtime, delay and period totals from the shared mirror', function () {
    $ana = timesheetEmployee('11111111111', 'Ana', [
        '2026-07-06' => ['07:40', '12:00', '13:00', '18:00'],
        '2026-07-07' => ['09:10', '12:00', '13:00', '17:50'],
        '2026-07-08' => ['07:30', '16:30'],
        '2026-07-09' => ['07:30'],
        '2026-07-11' => ['07:00', '12:00'],
    ]);

    $this->get(route('reports.timesheet.print', [
        'start' => '2026-07-06',
        'end' => '2026-07-12',
        'employees' => [$ana->id],
    ]))
        ->assertOk()
        ->assertSeeInOrder(['06/07/2026 Seg', '07:40', '18:00', '00:20', '00:05'])
        ->assertSeeInOrder(['07/07/2026 Ter', '09:10', '17:50', '01:35'])
        ->assertSeeInOrder(['08/07/2026 Qua', '07:30', '16:30', '01:00'])
        ->assertSeeInOrder(['09/07/2026 Qui', '07:30', 'Marcações incompletas'])
        ->assertSeeInOrder(['10/07/2026 Sex', 'Sem marcação'])
        ->assertSeeInOrder(['11/07/2026 Sáb', '07:00', '12:00', '05:00', 'Trabalho fora da jornada'])
        ->assertSeeInOrder(['Totais do período:', '05:20', '02:40', '3 dias para conferência']);
});

test('the print page shows the configured company and never the PIS', function () {
    config([
        'timesheet.company.name' => 'Empresa Configurada Ltda',
        'timesheet.company.cnpj' => '00.000.000/0001-00',
    ]);

    $ana = timesheetEmployee('98765432109', 'Ana', ['2026-07-06' => ['07:30', '17:30']]);

    $this->get(route('reports.timesheet.print', [
        'start' => '2026-07-06',
        'end' => '2026-07-06',
        'employees' => [$ana->id],
    ]))
        ->assertOk()
        ->assertSee('Empresa Configurada Ltda')
        ->assertSee('00.000.000/0001-00')
        ->assertSee('Seg a Qui: 07:30 · 12:00–13:00 · 17:30', false)
        ->assertSee('Sex: 07:30 · 12:00–13:00 · 16:30', false)
        ->assertSee('Função:')
        ->assertDontSee('98765432109')
        ->assertDontSee('PIS');
});

test('the print page warns when the company is not configured', function () {
    config(['timesheet.company.name' => null, 'timesheet.company.cnpj' => '']);

    $ana = timesheetEmployee('11111111111', 'Ana');

    $this->get(route('reports.timesheet.print', [
        'start' => '2026-07-06',
        'end' => '2026-07-06',
        'employees' => [$ana->id],
    ]))
        ->assertOk()
        ->assertSee('Razão social não configurada')
        ->assertSee('não configurado');
});

test('the print page has one sheet per employee with its own totals', function () {
    $ana = timesheetEmployee('11111111111', 'Ana', ['2026-07-06' => ['08:00', '17:30']]);
    $bruno = timesheetEmployee('22222222222', 'Bruno', ['2026-07-06' => ['07:30', '17:00']]);

    $this->get(route('reports.timesheet.print', [
        'start' => '2026-07-06',
        'end' => '2026-07-07',
        'employees' => [$ana->id, $bruno->id],
    ]))
        ->assertOk()
        ->assertSeeInOrder(['Ana', '06/07/2026 Seg', '00:25', '07/07/2026 Ter', 'Sem marcação', 'Totais do período:', '00:25', '1 dia para conferência'])
        ->assertSeeInOrder(['Bruno', '06/07/2026 Seg', '00:30', '07/07/2026 Ter', 'Sem marcação', 'Totais do período:', '00:30', '1 dia para conferência']);
});
