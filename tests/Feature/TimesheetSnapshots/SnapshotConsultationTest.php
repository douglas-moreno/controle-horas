<?php

use App\Enums\BenefitPeriodStatus;
use App\Enums\Permission;
use App\Livewire\EmployeePointsEdit;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Point;
use App\Models\PointJustificationReason;
use App\Models\TimesheetSnapshot;
use App\Models\User;
use App\Services\BenefitPeriodWorkflow;
use App\Services\PointJustificationService;
use Database\Seeders\PointJustificationReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * F6.2d: consulta (tela do dia) e impressão de versões históricas do espelho, e aviso de
 * divergência na impressão atual. Competência 10/2026: janela de 01/09/2026 a 30/09/2026.
 */
beforeEach(function () {
    $this->seed(PointJustificationReasonSeeder::class);
    Holiday::factory()->create(['date' => '2026-09-07', 'description' => 'Independência']);
    Holiday::flushCachedHolidays();

    $this->hr = User::factory()->create(['name' => 'Analista RH']);
    $this->hr->grantPermission(Permission::Hr);
    $this->hr->grantPermission(Permission::HrClosedPeriod);
    $this->hr->refresh();
    $this->other = User::factory()->create(['name' => 'Sem Permissao']);
    $this->actingAs($this->hr);

    $this->workflow = app(BenefitPeriodWorkflow::class);
    $this->service = app(PointJustificationService::class);

    $this->punches = function (Employee $employee, string $date, array $times = ['07:30', '12:00', '13:00', '17:30']): void {
        foreach ($times as $time) {
            Point::factory()->forEmployee($employee)->on($date)->create(['time' => $time.':00']);
        }
    };

    $this->ana = Employee::factory()->create(['name' => 'Ana Souza']);
    $this->bruno = Employee::factory()->create(['name' => 'Bruno Lima']);
    ($this->punches)($this->ana, '2026-09-15');
    ($this->punches)($this->bruno, '2026-09-15', ['08:00', '12:00', '13:00', '17:00']);

    $this->justify = fn (Employee $employee, string $date, string $start, string $end) => $this->service->register($employee, $date, [
        'request_uuid' => (string) Str::uuid(),
        'reason_id' => PointJustificationReason::query()->where('code', 'justified_delay')->sole()->id,
        'treatment' => 'full_allowance',
        'start_time' => $start,
        'end_time' => $end,
        'ends_next_day' => false,
    ], $this->hr)['justification'];

    $this->period = BenefitPeriod::factory()->forCompetence('2026-10')->create();
    $this->close = function (): void {
        $this->workflow->calculate($this->period);
        $this->workflow->close($this->period);
    };

    $this->version = fn (Employee $employee, string $date, int $sequence) => TimesheetSnapshot::query()
        ->where('employee_id', $employee->id)->where('date', $date)->where('sequence', $sequence)->sole();
    $this->screen = fn (User $user, string $date = '2026-09-15', ?Employee $employee = null) => Livewire::actingAs($user)
        ->test(EmployeePointsEdit::class, ['employee' => ($employee ?? $this->ana)->id, 'date' => $date]);
    $this->rows = fn () => DB::table('timesheet_snapshots')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
});

describe('day screen', function () {
    test('every kind of snapshot of the day is listed in order, with origin, author, operation and the current reference', function () {
        BenefitPeriod::query()->whereKey($this->period->id)->update(['status' => BenefitPeriodStatus::Closed->value, 'closed_at' => now()]);
        ($this->justify)($this->ana, '2026-09-15', '07:30', '07:40');
        BenefitPeriod::query()->whereKey($this->period->id)->update(['status' => BenefitPeriodStatus::Calculated->value]);
        $this->workflow->close($this->period->fresh());

        $versions = ($this->screen)($this->hr)->call('showSnapshotVersions')->viewData('snapshotVersions');

        expect(collect($versions['versions'])->map(fn ($version) => [$version['sequence'], $version['source'], $version['is_reference']])->all())->toBe([
            [1, 'late_baseline', false],
            [2, 'before_change', false],
            [3, 'after_change', false],
            [4, 'closure_baseline', true],
        ])
            ->and($versions['versions'][0]['source_label'])->toBe('Ponto de partida tardio (não é o fechamento original)')
            ->and($versions['versions'][0]['reason'])->toContain('não o fechamento original')
            ->and($versions['versions'][3]['source_label'])->toBe('Fechamento da competência (original)')
            ->and($versions['versions'][3]['reason'])->toBe('Fechamento nº 1 da competência 10/2026.')
            ->and($versions['versions'][0]['operation_uuid'])->toBe($versions['versions'][1]['operation_uuid'])
            ->and($versions['versions'][3]['operation_uuid'])->not->toBe($versions['versions'][0]['operation_uuid'])
            ->and(collect($versions['versions'])->pluck('created_by_name')->unique()->all())->toBe(['Analista RH'])
            ->and($versions['reference_state'])->toBe('reference')
            ->and($versions['competence'])->toBe('10/2026');
    });

    test('the detail shows the stored day, not the current one, and compares them', function () {
        ($this->close)();
        ($this->punches)($this->ana, '2026-09-15', ['18:30']);
        $closure = ($this->version)($this->ana, '2026-09-15', 1);

        $detail = ($this->screen)($this->hr)->call('showSnapshot', $closure->id)->viewData('snapshotDetail');

        expect($detail['id'])->toBe($closure->id)
            ->and($detail['stored']['Entrada'])->toBe('07:30')
            ->and($detail['stored']['Saída'])->toBe('17:30')
            ->and($detail['inputs']['Marcações'])->toBe('07:30 (importado), 12:00 (importado), 13:00 (importado), 17:30 (importado)')
            ->and($detail['inputs']['Jornada'])->toBe('07:30 · 12:00–13:00 · 17:30')
            ->and($detail['comparison']['result_changed'])->toBeTrue()
            ->and($detail['comparison']['fields'])->toContain('Quantidade de marcações');
    });

    test('the divergence notice appears on the day screen only when the current day differs from the reference', function () {
        ($this->close)();

        ($this->screen)($this->hr)->assertViewHas('snapshotNotice', null)
            ->assertDontSee('Espelho atual diverge do registro histórico');

        ($this->punches)($this->ana, '2026-09-15', ['18:30']);

        ($this->screen)($this->other)
            ->assertSee('Espelho atual diverge do registro histórico')
            ->assertDontSee('Versões do Espelho');
    });

    test('the author of the reference is shown only to HR users', function () {
        ($this->close)();
        ($this->punches)($this->ana, '2026-09-15', ['18:30']);

        $hr = ($this->screen)($this->hr)->assertSee('por Analista RH');

        expect($hr->viewData('snapshotNotice')['message'])->toContain('por Analista RH')
            ->and($hr->viewData('snapshotNotice')['reference']['created_by_name'])->toBe('Analista RH');

        $other = ($this->screen)($this->other)
            ->assertSee('Espelho atual diverge do registro histórico')
            ->assertDontSee('Analista RH');

        expect($other->viewData('snapshotNotice')['message'])->not->toContain('Analista RH')
            ->and($other->viewData('snapshotNotice')['reference'])->not->toHaveKey('created_by_name')
            ->and(json_encode($other->viewData('snapshotNotice')))->not->toContain('Analista RH')
            ->and(json_encode($other->snapshot))->not->toContain('Analista RH');
    });

    test('the integrity warning also hides the author from users without HR', function () {
        ($this->close)();
        DB::table('timesheet_snapshots')->where('id', ($this->version)($this->ana, '2026-09-15', 1)->id)->update(['result' => json_encode(['entrada' => '06:00'])]);

        ($this->screen)($this->other)->assertSee('Registro histórico com falha de integridade')->assertDontSee('Analista RH');
        ($this->screen)($this->hr)->assertSee('por Analista RH');
    });

    test('a tampered snapshot is shown as an integrity failure', function () {
        ($this->close)();
        $closure = ($this->version)($this->ana, '2026-09-15', 1);
        DB::table('timesheet_snapshots')->where('id', $closure->id)->update(['created_by_name' => 'Outra Pessoa']);

        $screen = ($this->screen)($this->hr)->call('showSnapshot', $closure->id);

        expect($screen->viewData('snapshotNotice')['level'])->toBe('danger')
            ->and($screen->viewData('snapshotDetail')['integrity_problems'])->toBe(['record_hash'])
            ->and($screen->viewData('snapshotDetail')['comparison'])->toBeNull()
            ->and($screen->viewData('snapshotVersions')['reference_state'])->toBe('integrity');
    });

    test('a day without snapshots explains why there is no reference', function () {
        ($this->screen)($this->hr)->call('showSnapshotVersions')
            ->assertViewHas('snapshotVersions', fn ($versions) => $versions['versions'] === [] && $versions['reference_state'] === 'open');

        BenefitPeriod::query()->whereKey($this->period->id)->update(['status' => BenefitPeriodStatus::Closed->value]);

        ($this->screen)($this->hr)->call('showSnapshotVersions')
            ->assertViewHas('snapshotVersions', fn ($versions) => $versions['reference_state'] === 'missing')
            ->assertSee('sem ponto de partida registrado');
    });

    test('without the HR permission the versions cannot be consulted', function () {
        ($this->close)();
        $closure = ($this->version)($this->ana, '2026-09-15', 1);

        ($this->screen)($this->other)->call('showSnapshotVersions')->assertForbidden();
        ($this->screen)($this->other)->call('showSnapshot', $closure->id)->assertForbidden();
    });

    test('a snapshot of another employee or another day is refused', function (string $whose) {
        ($this->close)();
        $snapshot = $whose === 'employee' ? ($this->version)($this->bruno, '2026-09-15', 1) : ($this->version)($this->ana, '2026-09-16', 1);

        ($this->screen)($this->hr)->call('showSnapshot', $snapshot->id)
            ->assertSet('snapshotId', null)
            ->assertViewHas('snapshotDetail', null)
            ->assertDispatched('wireui:notification', fn ($name, $params) => $params[0]['options']['title'] === 'Operação Não Permitida');
    })->with(['employee', 'day']);

    test('consulting the versions writes nothing', function () {
        ($this->close)();
        ($this->punches)($this->ana, '2026-09-15', ['18:30']);
        $before = ($this->rows)();

        ($this->screen)($this->hr)->call('showSnapshotVersions')->call('showSnapshot', ($this->version)($this->ana, '2026-09-15', 1)->id)->call('closeSnapshotVersions');

        expect(($this->rows)())->toBe($before);
    });
});

describe('historical print', function () {
    test('the closing version prints every stored day of the employee with its identification', function () {
        ($this->close)();
        $closure = ($this->version)($this->ana, '2026-09-15', 1);

        $response = $this->get(route('reports.timesheet.snapshot-print', $closure));

        $response->assertOk()
            ->assertSee('VERSÃO HISTÓRICA DO ESPELHO DE PONTO — NÃO É O ESPELHO ATUAL')
            ->assertSee('Fechamento da competência (original)')
            ->assertSee('Gravado na transação do fechamento da competência.')
            ->assertSee('Fechamento nº 1 da competência 10/2026.')
            ->assertSee('Analista RH')
            ->assertSee($closure->operation_uuid)
            ->assertSee('Ana Souza')
            ->assertDontSee('Bruno Lima')
            ->assertSee('10/2026')
            ->assertSee('01/09/2026 a 30/09/2026 (30)', false)
            ->assertSee('Situação atual: confere com esta versão');

        expect($response->viewData('days'))->toHaveCount(30);
    });

    test('the print shows the stored data even after the current mirror, rules inputs and holiday changed', function () {
        ($this->close)();
        $closure = ($this->version)($this->ana, '2026-09-15', 1);
        $holiday = ($this->version)($this->ana, '2026-09-07', 1);

        Point::query()->where('pis', $this->ana->pis)->where('date', '2026-09-15')->where('time', '17:30:00')->update(['time' => '19:00:00']);
        Holiday::query()->where('date', '2026-09-07')->update(['description' => 'Nome Novo']);
        Holiday::flushCachedHolidays();
        config(['timesheet.schedule.2' => ['entry' => '09:00', 'break_start' => '12:00', 'break_end' => '13:00', 'exit' => '18:00']]);

        $response = $this->get(route('reports.timesheet.snapshot-print', $closure))->assertOk();
        $days = $response->viewData('days')->keyBy(fn ($day) => $day['snapshot']['id']);

        expect($days[$closure->id]['result']['saida'])->toBe('17:30')
            ->and($days[$closure->id]['result'])->toEqual($closure->result)
            ->and($days[$closure->id]['current']['result_changed'])->toBeTrue()
            ->and($days[$holiday->id]['result']['holiday'])->toBe('Independência');

        $response->assertSee('Feriado: Independência')
            ->assertDontSee('Nome Novo')
            ->assertDontSee('09:00 · 12:00–13:00 · 18:00')
            ->assertSee('Seg a Qui: 07:30 · 12:00–13:00 · 17:30', false)
            ->assertSee('Situação atual: difere');
    });

    test('a late baseline prints as such and lists the later versions', function () {
        BenefitPeriod::query()->whereKey($this->period->id)->update(['status' => BenefitPeriodStatus::Closed->value, 'closed_at' => now()]);
        ($this->justify)($this->ana, '2026-09-15', '07:30', '07:40');
        $late = ($this->version)($this->ana, '2026-09-15', 1);

        $response = $this->get(route('reports.timesheet.snapshot-print', $late))->assertOk();

        expect($response->viewData('days'))->toHaveCount(1)
            ->and($response->viewData('days')->first()['later'])->toHaveCount(2);

        $response->assertSee('Ponto de partida tardio (não é o fechamento original)')
            ->assertSee('não é o resultado do fechamento original')
            ->assertDontSee('Gravado na transação do fechamento')
            ->assertSee('v2 — Antes de alteração autorizada', false)
            ->assertSee('v3 — Depois de alteração autorizada', false);
    });

    test('a tampered day is printed with an integrity warning', function () {
        ($this->close)();
        $closure = ($this->version)($this->ana, '2026-09-15', 1);
        DB::table('timesheet_snapshots')->where('id', $closure->id)->update(['result' => json_encode(['entrada' => '06:00'])]);

        $response = $this->get(route('reports.timesheet.snapshot-print', $closure))->assertOk();

        $response->assertSee('FALHA DE INTEGRIDADE (result_hash)', false)
            ->assertSee('06:00');
        expect($response->viewData('days')->first(fn ($day) => $day['snapshot']['id'] === $closure->id)['current'])->toBeNull();
    });

    test('the historical print requires the HR permission and an existing snapshot', function () {
        ($this->close)();
        $closure = ($this->version)($this->ana, '2026-09-15', 1);

        $this->actingAs($this->other)->get(route('reports.timesheet.snapshot-print', $closure))->assertForbidden();
        $this->actingAs($this->hr)->get(route('reports.timesheet.snapshot-print', ['timesheetSnapshot' => 999999]))->assertNotFound();
        auth()->logout();
        $this->get(route('reports.timesheet.snapshot-print', $closure))->assertRedirect();
    });

    test('printing writes nothing', function () {
        ($this->close)();
        ($this->punches)($this->ana, '2026-09-15', ['18:30']);
        $before = ($this->rows)();

        $this->get(route('reports.timesheet.snapshot-print', ($this->version)($this->ana, '2026-09-15', 1)))->assertOk();
        $this->get(route('reports.timesheet.print', ['start' => '2026-08-26', 'end' => '2026-09-25', 'employees' => [$this->ana->id, $this->bruno->id]]))->assertOk();

        expect(($this->rows)())->toBe($before);
    });
});

describe('current print', function () {
    test('the current print warns about the diverging days of a closed competence and keeps the current values', function () {
        ($this->close)();
        Point::query()->where('pis', $this->ana->pis)->where('date', '2026-09-15')->where('time', '17:30:00')->update(['time' => '19:00:00']);

        $response = $this->get(route('reports.timesheet.print', ['start' => '2026-08-26', 'end' => '2026-09-25', 'employees' => [$this->ana->id, $this->bruno->id]]))->assertOk();
        $sheets = $response->viewData('timesheets')->keyBy(fn ($sheet) => $sheet['employee']->id);

        expect($sheets[$this->ana->id]['snapshot_notices'])->toHaveCount(1)
            ->and($sheets[$this->ana->id]['snapshot_notices'][0]['date'])->toBe('15/09/2026')
            ->and($sheets[$this->bruno->id]['snapshot_notices'])->toBe([])
            ->and(collect($sheets[$this->ana->id]['days'])->firstWhere('date', '2026-09-15')['saida'])->toBe('19:00');

        $response->assertSee('Atenção — espelho atual diverge do registro histórico da competência fechada.', false)
            ->assertSee('15/09/2026: difere de fechamento da competência (original) (versão 1', false);
    });

    test('without divergence the current print has no warning', function () {
        ($this->close)();

        $this->get(route('reports.timesheet.print', ['start' => '2026-08-26', 'end' => '2026-09-25', 'employees' => [$this->ana->id]]))
            ->assertOk()
            ->assertDontSee('espelho atual diverge do registro histórico');
    });
});
