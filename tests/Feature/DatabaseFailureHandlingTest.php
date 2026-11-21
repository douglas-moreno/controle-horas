<?php

use App\Enums\BenefitPeriodStatus;
use App\Enums\DatabaseFailure;
use App\Enums\TimesheetSnapshotSource;
use App\Livewire\Benefits\BenefitPeriodCalculation;
use App\Livewire\EmployeeIndex;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\Point;
use App\Models\TimesheetSnapshot;
use App\Models\User;
use App\Services\BenefitPeriodWorkflow;
use App\Services\TimesheetSnapshotRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Auditoria B5: falhas de banco previsíveis no fechamento da competência e na exclusão de
 * funcionário viram mensagem na tela (com a causa no log); as demais seguem como erro
 * (propagadas e registradas pelo handler do framework).
 * As falhas de espera de bloqueio e deadlock são simuladas com a exceção do driver; a de
 * chave estrangeira na exclusão é real, no banco `testing`.
 */
beforeEach(function () {
    Exceptions::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->queryException = function (string $sqlState, int $driverCode, string $message): QueryException {
        $previous = new PDOException($message);
        $previous->errorInfo = [$sqlState, $driverCode, $message];

        return new QueryException('mysql', 'insert into `timesheet_snapshots` ...', [], $previous);
    };

    $this->employee = Employee::factory()->create(['name' => 'Ana Souza']);
    foreach (['07:30', '12:00', '13:00', '17:30'] as $time) {
        Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => $time.':00']);
    }

    $this->period = BenefitPeriod::factory()->forCompetence('2026-10')->create();
    app(BenefitPeriodWorkflow::class)->calculate($this->period);
    $this->tables = fn () => collect(['benefit_periods', 'benefit_period_status_changes', 'benefit_period_employees', 'timesheet_snapshots', 'employees'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])
        ->all();
});

test('the failures are classified by the driver code', function (string $sqlState, int $code, ?DatabaseFailure $expected) {
    expect(DatabaseFailure::fromException(($this->queryException)($sqlState, $code, 'falha')))->toBe($expected);
})->with([
    'lock wait timeout' => ['HY000', 1205, DatabaseFailure::Concurrency],
    'deadlock' => ['40001', 1213, DatabaseFailure::Concurrency],
    'foreign key' => ['23000', 1451, DatabaseFailure::Integrity],
    'duplicate' => ['23000', 1062, DatabaseFailure::Integrity],
    'missing table' => ['42S02', 1146, null],
    'lost connection' => ['HY000', 2006, null],
]);

describe('closing the competence', function () {
    test('a predictable database failure keeps the competence calculated and explains it on the screen', function (string $sqlState, int $code, string $message) {
        $before = ($this->tables)();
        TimesheetSnapshot::creating(fn () => throw ($this->queryException)($sqlState, $code, 'falha simulada'));

        Livewire::test(BenefitPeriodCalculation::class, ['benefitPeriod' => $this->period->fresh()])
            ->call('close')
            ->assertSet('closeBlockers', [$message])
            ->assertDispatched('wireui:notification', fn ($name, $params) => $params[0]['options']['title'] === 'Competência Não Fechada');

        expect($this->period->fresh()->status)->toBe(BenefitPeriodStatus::Calculated)
            ->and(($this->tables)())->toBe($before);
        Exceptions::assertReported(fn (QueryException $exception) => ($exception->errorInfo[1] ?? null) === $code);
    })->with([
        'lock wait timeout' => ['HY000', 1205, 'Outra operação estava usando os dados desta competência (espera de bloqueio esgotada ou conflito). Nada foi gravado; tente fechar novamente.'],
        'deadlock' => ['40001', 1213, 'Outra operação estava usando os dados desta competência (espera de bloqueio esgotada ou conflito). Nada foi gravado; tente fechar novamente.'],
        'integrity' => ['23000', 1062, 'Outra operação gravou ao mesmo tempo um registro relacionado e o fechamento foi desfeito. Nada foi gravado; confira a competência e tente novamente.'],
    ]);

    test('an unexpected database failure is not converted into a message', function () {
        $before = ($this->tables)();
        TimesheetSnapshot::creating(fn () => throw ($this->queryException)('42S02', 1146, 'tabela ausente'));

        expect(fn () => Livewire::test(BenefitPeriodCalculation::class, ['benefitPeriod' => $this->period->fresh()])->call('close'))
            ->toThrow(QueryException::class)
            ->and($this->period->fresh()->status)->toBe(BenefitPeriodStatus::Calculated)
            ->and(($this->tables)())->toBe($before);
        Exceptions::assertReported(fn (QueryException $exception) => ($exception->errorInfo[1] ?? null) === 1146);
    });

    test('without failure the closing still succeeds', function () {
        Livewire::test(BenefitPeriodCalculation::class, ['benefitPeriod' => $this->period->fresh()])
            ->call('close')
            ->assertSet('closeBlockers', [])
            ->assertDispatched('wireui:notification', fn ($name, $params) => $params[0]['options']['title'] === 'Competência Fechada');

        expect($this->period->fresh()->status)->toBe(BenefitPeriodStatus::Closed);
    });
});

describe('deleting an employee', function () {
    test('a snapshot recorded during the deletion is a real foreign key failure shown as a message', function () {
        $other = Employee::factory()->create(['name' => 'Bruno Lima']);
        Employee::deleting(function (Employee $employee) {
            app(TimesheetSnapshotRecorder::class)->record($employee, ['2026-09-15'], TimesheetSnapshotSource::Recalculation, null);
        });

        Livewire::test(EmployeeIndex::class)
            ->call('destroy', $other->id)
            ->assertDispatched('wireui:notification', fn ($name, $params) => $params[0]['options']['title'] === 'Exclusão Não Realizada'
                && str_contains($params[0]['options']['description'], 'Nada foi excluído. Informe a data de rescisão'));

        expect(Employee::query()->whereKey($other->id)->exists())->toBeTrue();
        Exceptions::assertReported(fn (QueryException $exception) => ($exception->errorInfo[1] ?? null) === 1451);
    });

    test('a lock wait timeout is shown as a message and nothing is deleted', function () {
        $other = Employee::factory()->create(['name' => 'Bruno Lima']);
        Employee::deleting(fn () => throw ($this->queryException)('HY000', 1205, 'Lock wait timeout exceeded'));

        Livewire::test(EmployeeIndex::class)
            ->call('destroy', $other->id)
            ->assertDispatched('wireui:notification', fn ($name, $params) => $params[0]['options']['title'] === 'Exclusão Não Realizada'
                && str_contains($params[0]['options']['description'], 'em uso por outra operação'));

        expect(Employee::query()->whereKey($other->id)->exists())->toBeTrue();
        Exceptions::assertReported(QueryException::class);
    });

    test('an unexpected database failure is not converted into a message', function () {
        $other = Employee::factory()->create(['name' => 'Bruno Lima']);
        Employee::deleting(fn () => throw ($this->queryException)('HY000', 2006, 'MySQL server has gone away'));

        expect(fn () => Livewire::test(EmployeeIndex::class)->call('destroy', $other->id))->toThrow(QueryException::class)
            ->and(Employee::query()->whereKey($other->id)->exists())->toBeTrue();
        Exceptions::assertReported(fn (QueryException $exception) => ($exception->errorInfo[1] ?? null) === 2006);
    });

    test('without failure the employee is still deleted', function () {
        $other = Employee::factory()->create(['name' => 'Bruno Lima']);

        Livewire::test(EmployeeIndex::class)
            ->call('destroy', $other->id)
            ->assertDispatched('wireui:notification', fn ($name, $params) => $params[0]['options']['title'] === 'Funcionário Excluído');

        expect(Employee::query()->whereKey($other->id)->exists())->toBeFalse();
    });
});
