<?php

use App\Enums\JustificationHistoryAction;
use App\Enums\Permission;
use App\Livewire\EmployeePointsEdit;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\Point;
use App\Models\PointJustification;
use App\Models\PointJustificationHistory;
use App\Models\PointJustificationReason;
use App\Models\User;
use App\Services\PointJustificationHistoryPresenter;
use App\Services\PointJustificationService;
use Database\Seeders\PointJustificationReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * F6.1: histórico das justificativas (somente leitura), marcação vinculada e aviso de
 * justificativa movida, na tela de edição de pontos.
 */
beforeEach(function () {
    $this->seed(PointJustificationReasonSeeder::class);

    $this->employee = Employee::factory()->create(['name' => 'Ana Souza']);
    $this->other = Employee::factory()->create(['name' => 'Bruno Lima']);
    Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => '07:30:00']);

    $this->hr = User::factory()->create(['name' => 'Analista RH']);
    $this->hr->grantPermission(Permission::Hr);
    $this->hr->refresh();
    $this->common = User::factory()->create();
    $this->service = app(PointJustificationService::class);

    $this->reason = fn (string $code) => PointJustificationReason::query()->where('code', $code)->sole();
    $this->interval = fn (string $start, string $end, array $extra = []) => ['reason_id' => ($this->reason)('justified_delay')->id, 'treatment' => 'full_allowance', 'start_time' => $start, 'end_time' => $end, ...$extra];
    $this->punch = fn (string $time, string $kind, array $extra = []) => ['reason_id' => ($this->reason)('forgotten_punch')->id, 'treatment' => 'no_allowance', 'start_time' => $time, 'punch_kind' => $kind, ...$extra];

    $this->register = fn (array $input, string $date = '2026-09-15', ?Employee $employee = null, ?User $user = null) => $this->service
        ->register($employee ?? $this->employee, $date, ['request_uuid' => (string) Str::uuid(), ...$input], $user ?? $this->hr)['justification'];
    $this->update = fn (PointJustification $justification, array $input, ?User $user = null) => $this->service
        ->update($justification, $input, $justification->fresh()->version, $user ?? $this->hr);
    $this->cancel = fn (PointJustification $justification, string $reason = 'Lançado por engano', ?User $user = null) => $this->service
        ->cancel($justification, $reason, $justification->fresh()->version, $user ?? $this->hr);

    $this->screen = fn (User $user, string $date = '2026-09-15', ?Employee $employee = null) => Livewire::actingAs($user)
        ->test(EmployeePointsEdit::class, ['employee' => ($employee ?? $this->employee)->id, 'date' => $date]);

    $this->tables = fn () => collect(['points', 'point_justifications', 'point_justification_histories', 'benefit_periods'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])
        ->all();
});

describe('authorization and isolation', function () {
    test('a user without hr cannot open the history, even calling the method directly', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));

        ($this->screen)($this->common)
            ->assertDontSeeHtml('showJustificationHistory')
            ->call('showJustificationHistory', $justification->id)
            ->assertForbidden();
    });

    test('the history of another employee is refused and nothing is shown', function () {
        $foreign = ($this->register)(($this->interval)('07:30', '08:15', ['observation' => 'Dado de outro funcionário']), '2026-09-15', $this->other);

        ($this->screen)($this->hr)
            ->call('showJustificationHistory', $foreign->id)
            ->assertSet('showHistoryModal', false)
            ->assertSet('historyJustificationId', null)
            ->assertViewHas('justificationHistory', null)
            ->assertDontSee('Dado de outro funcionário');
    });

    test('the opened justification cannot be swapped from the browser', function () {
        $foreign = ($this->register)(($this->interval)('07:30', '08:15'), '2026-09-15', $this->other);

        expect(fn () => ($this->screen)($this->hr)->set('historyJustificationId', $foreign->id))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });

    test('opening the history writes nothing', function () {
        $justification = ($this->register)(($this->punch)('12:00', 'break_start'));
        ($this->cancel)($justification);
        $before = ($this->tables)();

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        ($this->screen)($this->hr)
            ->call('showJustificationHistory', $justification->id)
            ->assertSet('showHistoryModal', true)
            ->call('closeJustificationHistory')
            ->assertSet('showHistoryModal', false);

        expect($writes)->toBe([])
            ->and(($this->tables)())->toBe($before);
    });

    test('the history is read with a fixed number of queries', function () {
        $justification = ($this->register)(($this->punch)('12:00', 'break_start'));
        $queries = function () use ($justification): int {
            $fresh = $justification->fresh();
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(PointJustificationHistoryPresenter::class)->present($fresh);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $few = $queries();

        foreach (range(1, 6) as $minute) {
            ($this->update)($justification, ($this->punch)(sprintf('12:%02d', $minute), 'break_start'));
        }

        expect(PointJustificationHistory::query()->count())->toBe(14)
            ->and($queries())->toBeLessThanOrEqual($few)
            ->and($few)->toBeLessThanOrEqual(6);
    });
});

describe('timeline', function () {
    test('every operation is listed in order with action, user, competence and changed fields', function () {
        $justification = ($this->register)(($this->punch)('12:00', 'break_start', ['observation' => 'Esqueceu']));
        ($this->update)($justification, ($this->punch)('12:10', 'break_start', ['observation' => 'Esqueceu de marcar']));
        ($this->cancel)($justification, 'Registro duplicado');

        $history = app(PointJustificationHistoryPresenter::class)->present($justification->fresh());
        $actions = array_column($history['entries'], 'action');

        expect($actions)->toBe([
            'Justificativa registrada',
            'Marcação manual criada',
            'Justificativa alterada',
            'Marcação manual alterada',
            'Justificativa cancelada',
            'Marcação manual removida',
        ])
            ->and(collect($history['entries'])->pluck('performed_by')->unique()->all())->toBe(['Analista RH'])
            ->and(collect($history['entries'])->pluck('competence')->unique()->all())->toBe(['10/2026'])
            ->and(collect($history['entries'])->pluck('closed_period')->unique()->all())->toBe([false])
            ->and($history['entries'][2]['changes'])->toBe([
                ['field' => 'Horário inicial', 'before' => '12:00', 'after' => '12:10'],
                ['field' => 'Observação', 'before' => 'Esqueceu', 'after' => 'Esqueceu de marcar'],
            ])
            ->and($history['entries'][3]['point_before'])->toBe('15/09/2026 12:00')
            ->and($history['entries'][3]['point_after'])->toBe('15/09/2026 12:10')
            ->and($history['entries'][4]['cancellation_reason'])->toBe('Registro duplicado')
            ->and($history['entries'][0]['changes'])->toContain(['field' => 'Tipo de marcação', 'before' => null, 'after' => 'Início do intervalo'])
            ->and($history['entries'][0]['changes'])->toContain(['field' => 'Tratamento', 'before' => null, 'after' => 'Não abonar']);
    });

    test('the screen shows the timeline of the opened justification', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));
        ($this->update)($justification, ($this->interval)('07:30', '08:30'));

        ($this->screen)($this->hr)
            ->call('showJustificationHistory', $justification->id)
            ->assertSee('Histórico da Justificativa')
            ->assertSeeInOrder(['Justificativa registrada', 'Justificativa alterada'])
            ->assertSeeInOrder(['Justificativa alterada', '08:15', '08:30'])
            ->assertSee('Analista RH')
            ->assertSee('Competência 10/2026');
    });

    test('a canceled justification still has its history available', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));
        ($this->cancel)($justification, 'Lançado por engano');

        ($this->screen)($this->hr)
            ->assertSeeHtml('showJustificationHistory('.$justification->id.')')
            ->call('showJustificationHistory', $justification->id)
            ->assertSee('Justificativa cancelada')
            ->assertSee('Motivo do cancelamento: Lançado por engano');
    });

    test('a closed competence is flagged in the timeline', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));
        BenefitPeriod::factory()->forCompetence('2026-10')->closed()->create();
        $this->hr->grantPermission(Permission::HrClosedPeriod);
        ($this->update)($justification, ($this->interval)('07:00', '08:15'), $this->hr->fresh());

        $entries = app(PointJustificationHistoryPresenter::class)->present($justification->fresh())['entries'];

        expect(array_column($entries, 'closed_period'))->toBe([false, true])
            ->and($entries[1]['description'])->toBe('Alterado: horário inicial. Competência fechada (10/2026).');
    });

    test('the historical reason name is kept after the catalog is renamed', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));
        $originalName = ($this->reason)('justified_delay')->name;
        ($this->reason)('justified_delay')->forceFill(['name' => 'Nome novo do cadastro'])->save();

        $history = app(PointJustificationHistoryPresenter::class)->present($justification->fresh());

        expect($history['justification']['reason'])->toBe($originalName)
            ->and($history['entries'][0]['changes'])->toContain(['field' => 'Motivo', 'before' => null, 'after' => $originalName])
            ->and(json_encode($history))->not->toContain('Nome novo do cadastro');
    });

    test('a changed reason shows the historical names of both reasons', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));
        $oldName = ($this->reason)('justified_delay')->name;
        $newReason = ($this->reason)('authorized_early_leave');
        $newName = $newReason->name;
        ($this->update)($justification, ($this->interval)('07:30', '08:15', ['reason_id' => $newReason->id]));
        $newReason->forceFill(['name' => 'Renomeado 1'])->save();
        ($this->reason)('justified_delay')->forceFill(['name' => 'Renomeado 2'])->save();

        $change = collect(app(PointJustificationHistoryPresenter::class)->present($justification->fresh())['entries'][1]['changes'])->firstWhere('field', 'Motivo');

        expect($change)->toBe(['field' => 'Motivo', 'before' => $oldName, 'after' => $newName]);
    });

    test('without a reason snapshot the current catalog name is marked as such', function () {
        $justification = PointJustification::factory()->for($this->employee)->create(['occurrence_date' => '2026-09-15', 'reason_id' => ($this->reason)('justified_delay')->id]);

        expect(app(PointJustificationHistoryPresenter::class)->present($justification)['justification']['reason'])
            ->toBe(($this->reason)('justified_delay')->name.' (nome atual do cadastro)');
    });

    test('the copied name is shown after the user is deleted', function () {
        $temporary = User::factory()->create(['name' => 'Usuária Removida']);
        $temporary->grantPermission(Permission::Hr);
        $justification = ($this->register)(($this->interval)('07:30', '08:15'), '2026-09-15', null, $temporary->fresh());
        $temporary->delete();

        $entry = app(PointJustificationHistoryPresenter::class)->present($justification->fresh())['entries'][0];

        expect(PointJustificationHistory::query()->sole()->performed_by)->toBeNull()
            ->and($entry['performed_by'])->toBe('Usuária Removida');
    });

    test('recorded text is escaped on screen', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15', ['observation' => '<script>alert(1)</script>']));
        ($this->cancel)($justification, '<b>negrito</b>');

        ($this->screen)($this->hr)
            ->call('showJustificationHistory', $justification->id)
            ->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertDontSeeHtml('<b>negrito</b>')
            ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->assertSeeHtml('&lt;b&gt;negrito&lt;/b&gt;');
    });
});

describe('linked punch', function () {
    test('an existing linked punch is shown with its current data', function () {
        $justification = ($this->register)(($this->punch)('12:00', 'break_start'));

        $linked = app(PointJustificationHistoryPresenter::class)->present($justification->fresh())['linked_point'];

        expect($linked)->toMatchArray(['status' => 'current', 'point_id' => $justification->manual_point_id, 'date' => '15/09/2026', 'time' => '12:00', 'type' => 'manual', 'protection' => null]);
    });

    test('a punch removed by the cancellation is shown from the history data', function () {
        $justification = ($this->register)(($this->punch)('12:00', 'break_start'));
        $pointId = $justification->manual_point_id;
        ($this->cancel)($justification);

        $linked = app(PointJustificationHistoryPresenter::class)->present($justification->fresh())['linked_point'];

        expect(Point::query()->find($pointId))->toBeNull()
            ->and($justification->fresh()->manual_point_id)->toBeNull()
            ->and($linked)->toMatchArray(['status' => 'removed', 'point_id' => $pointId, 'date' => '15/09/2026', 'time' => '12:00', 'removed_by' => 'Analista RH']);

        ($this->screen)($this->hr)
            ->call('showJustificationHistory', $justification->id)
            ->assertSee('Marcação #'.$pointId.' de 15/09/2026 às 12:00 removida em');
    });

    test('a punch deleted outside the flow is reported as missing with its last record', function () {
        $justification = ($this->register)(($this->punch)('12:00', 'break_start'));
        $pointId = $justification->manual_point_id;
        DB::table('points')->where('id', $pointId)->delete();

        $linked = app(PointJustificationHistoryPresenter::class)->present($justification->fresh())['linked_point'];

        expect($linked)->toMatchArray(['status' => 'missing', 'point_id' => $pointId, 'date' => '15/09/2026', 'time' => '12:00', 'removed_at' => null]);

        ($this->screen)($this->hr)
            ->call('showJustificationHistory', $justification->id)
            ->assertSee('A marcação #'.$pointId.' não existe mais');
    });

    test('a justification without a punch has no linked punch panel', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));

        expect(app(PointJustificationHistoryPresenter::class)->present($justification->fresh())['linked_point'])->toBeNull();
    });
});

describe('justification moved to another date', function () {
    test('the old date warns about the move and opens the history', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));
        ($this->update)($justification, ($this->interval)('07:30', '08:15', ['occurrence_date' => '2026-09-18']));

        ($this->screen)($this->hr)
            ->assertViewHas('movedJustifications', fn (array $moved) => count($moved) === 1 && $moved[0]['id'] === $justification->id && $moved[0]['new_date'] === '18/09/2026')
            ->assertSee('foi movida para')
            ->assertSee('18/09/2026')
            ->call('showJustificationHistory', $justification->id)
            ->assertSet('showHistoryModal', true)
            ->assertSee('Justificativa alterada');

        ($this->screen)($this->hr, '2026-09-18')
            ->assertViewHas('movedJustifications', [])
            ->assertViewHas('justifications', fn ($justifications) => $justifications->pluck('id')->all() === [$justification->id]);
    });

    test('a justification moved back to its date is not warned', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));
        ($this->update)($justification, ($this->interval)('07:30', '08:15', ['occurrence_date' => '2026-09-18']));
        ($this->update)($justification, ($this->interval)('07:30', '08:15', ['occurrence_date' => '2026-09-15']));

        ($this->screen)($this->hr)->assertViewHas('movedJustifications', []);
    });

    test('the move warning is isolated by employee and hidden without hr', function () {
        $foreign = ($this->register)(($this->interval)('07:30', '08:15'), '2026-09-15', $this->other);
        ($this->update)($foreign, ($this->interval)('07:30', '08:15', ['occurrence_date' => '2026-09-18']));

        ($this->screen)($this->hr)->assertViewHas('movedJustifications', []);

        $own = ($this->register)(($this->interval)('07:30', '08:15'));
        ($this->update)($own, ($this->interval)('07:30', '08:15', ['occurrence_date' => '2026-09-18']));

        ($this->screen)($this->common)
            ->assertViewMissing('movedJustifications')
            ->assertDontSee('foi movida para');
    });

    test('moving across competences checks both periods and records origin and destination', function () {
        Point::factory()->forEmployee($this->employee)->on('2026-10-15')->create(['time' => '07:30:00']);
        $justification = ($this->register)(($this->punch)('12:00', 'break_start'));
        $pointId = $justification->manual_point_id;
        BenefitPeriod::factory()->forCompetence('2026-11')->closed()->create();

        $refused = null;

        try {
            ($this->update)($justification, ($this->punch)('12:00', 'break_start', ['occurrence_date' => '2026-10-15']));
        } catch (ValidationException $exception) {
            $refused = $exception->errors();
        }

        expect($refused)->toHaveKey('occurrence_date')
            ->and($justification->fresh()->occurrence_date->toDateString())->toBe('2026-09-15')
            ->and(Point::query()->find($pointId)->date->toDateString())->toBe('2026-09-15');

        $this->hr->grantPermission(Permission::HrClosedPeriod);
        ($this->update)($justification, ($this->punch)('12:00', 'break_start', ['occurrence_date' => '2026-10-15']), $this->hr->fresh());

        $updated = PointJustificationHistory::query()->where('action', JustificationHistoryAction::Updated)->sole();
        $moved = PointJustificationHistory::query()->where('action', JustificationHistoryAction::ManualPointUpdated)->sole();

        expect($updated->old_values['competence'])->toBe('2026-10-01')
            ->and($updated->new_values['competence'])->toBe('2026-11-01')
            ->and($updated->affected_competence->toDateString())->toBe('2026-11-01')
            ->and($updated->closed_period)->toBeTrue()
            ->and($updated->description)->toBe('Alterado: data. Competência fechada (11/2026).')
            ->and($moved->old_values['manual_point']['date'])->toBe('2026-09-15')
            ->and($moved->new_values['manual_point']['date'])->toBe('2026-10-15')
            ->and(Point::query()->find($pointId)->date->toDateString())->toBe('2026-10-15');

        ($this->screen)($this->hr)
            ->assertViewHas('movedJustifications', fn (array $list) => count($list) === 1 && $list[0]['new_date'] === '15/10/2026')
            ->assertViewHas('justifications', fn ($justifications) => $justifications->isEmpty());

        ($this->screen)($this->hr, '2026-10-15')
            ->assertViewHas('justifications', fn ($justifications) => $justifications->pluck('id')->all() === [$justification->id])
            ->call('showJustificationHistory', $justification->id)
            ->assertViewHas('justificationHistory', fn (array $history) => in_array(['field' => 'Data', 'before' => '15/09/2026', 'after' => '15/10/2026'], $history['entries'][2]['changes'], true))
            ->assertSee('15/09/2026 12:00 → 15/10/2026 12:00')
            ->assertSee('Competência fechada');
    });
});
