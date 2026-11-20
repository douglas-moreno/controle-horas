<?php

use App\Enums\JustificationCategory;
use App\Enums\JustificationTimeMode;
use App\Enums\JustificationTreatment;
use App\Enums\Permission;
use App\Livewire\EmployeePointsEdit;
use App\Models\AfdHistoricalNsr;
use App\Models\AfdPointDecision;
use App\Models\Employee;
use App\Models\Point;
use App\Models\PointJustification;
use App\Models\PointJustificationReason;
use App\Models\User;
use App\Services\PointJustificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->employee = Employee::factory()->create(['name' => 'Ana Souza']);
    $this->importedPoint = Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => '07:30:00']);

    $this->hr = User::factory()->create();
    $this->hr->grantPermission(Permission::Hr);
    $this->common = User::factory()->create();

    $this->screen = fn (User $user) => Livewire::actingAs($user)->test(EmployeePointsEdit::class, ['employee' => $this->employee->id, 'date' => '2026-09-15']);

    $this->fullDayReason = PointJustificationReason::factory()->create(['name' => 'Atestado médico', 'time_mode' => JustificationTimeMode::FullDayOrInterval]);
    $this->intervalReason = PointJustificationReason::factory()->create(['name' => 'Atraso justificado', 'category' => JustificationCategory::DelayAndLeave, 'time_mode' => JustificationTimeMode::Interval]);
    $this->punchReason = PointJustificationReason::factory()->forgottenPunch()->create(['name' => 'Esquecimento de marcação']);
    $this->inactiveReason = PointJustificationReason::factory()->inactive()->create(['name' => 'Motivo desativado']);
});

test('a user without hr does not see the justifications of the day', function () {
    PointJustification::factory()->for($this->employee)->create(['occurrence_date' => '2026-09-15', 'reason_id' => $this->fullDayReason->id, 'observation' => 'Consulta sigilosa']);

    ($this->screen)($this->common)
        ->assertOk()
        ->assertDontSee('Justificativas')
        ->assertDontSee('Consulta sigilosa')
        ->assertDontSeeHtml('openJustificationForm')
        ->assertViewHas('canManageJustifications', false)
        ->assertViewMissing('justifications');
});

test('a user without hr cannot call the protected methods directly', function (string $method, array $parameters) {
    ($this->screen)($this->common)
        ->call($method, ...$parameters)
        ->assertForbidden();
})->with([
    'open form' => ['openJustificationForm', []],
    'submit' => ['submitJustification', []],
    'edit' => ['editJustification', [1]],
    'confirm cancel' => ['confirmCancelJustification', [1]],
    'cancel' => ['cancelJustification', []],
]);

test('a user without hr cannot drive the form by setting its fields', function () {
    ($this->screen)($this->common)
        ->set('justification.reason_id', $this->fullDayReason->id)
        ->assertForbidden();
});

test('the manual punch features stay available without hr', function () {
    ($this->screen)($this->common)
        ->call('addPoint')
        ->set('newPointTime', '17:30')
        ->call('saveNewPoint')
        ->assertHasNoErrors();

    expect(Point::query()->where('type', 'manual')->where('time', '17:30:00')->exists())->toBeTrue();
});

test('an hr user sees the justifications of the employee on the date', function () {
    PointJustification::factory()->for($this->employee)->between('2026-09-15', '07:30', '08:15')->create([
        'reason_id' => $this->intervalReason->id,
        'treatment' => JustificationTreatment::PartialAllowance,
        'allowed_minutes' => 30,
        'observation' => 'Ônibus quebrou',
        'created_by' => $this->hr->id,
    ]);
    PointJustification::factory()->for($this->employee)->canceled()->create(['occurrence_date' => '2026-09-15', 'reason_id' => $this->fullDayReason->id]);
    PointJustification::factory()->for($this->employee)->create(['occurrence_date' => '2026-09-16', 'reason_id' => $this->fullDayReason->id, 'observation' => 'Outro dia']);
    PointJustification::factory()->create(['occurrence_date' => '2026-09-15', 'reason_id' => $this->fullDayReason->id, 'observation' => 'Outro funcionário']);

    ($this->screen)($this->hr)
        ->assertOk()
        ->assertSee('Justificativas')
        ->assertSee('Atraso justificado')
        ->assertSee('Atrasos e saídas')
        ->assertSee('Abonar parcialmente')
        ->assertSee('00:30 abonadas')
        ->assertSee('07:30 – 08:15')
        ->assertSee('00:45')
        ->assertSee('Ônibus quebrou')
        ->assertSee($this->hr->name)
        ->assertSee('Cancelada')
        ->assertSee('Ativa')
        ->assertDontSee('Outro dia')
        ->assertDontSee('Outro funcionário')
        ->assertViewHas('justifications', fn ($justifications) => $justifications->count() === 2);
});

test('the form lists only active reasons', function () {
    ($this->screen)($this->hr)
        ->call('openJustificationForm')
        ->assertSet('showJustificationModal', true)
        ->assertViewHas('reasonOptions', function (array $options) {
            $names = array_column($options, 'name');

            return count($options) === 3
                && in_array('Ausências — Atestado médico', $names, true)
                && ! collect($names)->contains(fn ($name) => str_contains($name, 'Motivo desativado'));
        });
});

test('choosing a reason applies its default treatment and offers only supported treatments', function () {
    $restricted = PointJustificationReason::factory()->create([
        'time_mode' => JustificationTimeMode::FullDay,
        'default_treatment' => JustificationTreatment::HourBank,
        'allowed_treatments' => ['hour_bank', 'no_allowance'],
    ]);

    ($this->screen)($this->hr)
        ->call('openJustificationForm')
        ->set('justification.reason_id', $this->intervalReason->id)
        ->assertSet('justification.treatment', 'full_allowance')
        ->assertViewHas('treatmentOptions', fn (array $options) => array_column($options, 'id') === ['full_allowance', 'partial_allowance', 'no_allowance'])
        ->set('justification.reason_id', $restricted->id)
        ->assertSet('justification.treatment', 'no_allowance')
        ->assertViewHas('treatmentOptions', fn (array $options) => array_column($options, 'id') === ['no_allowance']);
});

test('the fields follow the time mode of the reason', function (string $reason, array $set, array $expected) {
    $component = ($this->screen)($this->hr)
        ->call('openJustificationForm')
        ->set('justification.reason_id', $this->{$reason}->id);

    foreach ($set as $field => $value) {
        $component->set('justification.'.$field, $value);
    }

    foreach ($expected as $flag => $value) {
        $component->assertViewHas($flag, $value);
    }
})->with([
    'full day or interval, full day' => ['fullDayReason', [], ['showsFullDayChoice' => true, 'showsInterval' => false, 'showsSinglePunch' => false]],
    'full day or interval, interval' => ['fullDayReason', ['full_day' => false], ['showsFullDayChoice' => true, 'showsInterval' => true, 'showsSinglePunch' => false]],
    'interval' => ['intervalReason', [], ['showsFullDayChoice' => false, 'showsInterval' => true, 'showsSinglePunch' => false]],
    'single punch' => ['punchReason', [], ['showsFullDayChoice' => false, 'showsInterval' => false, 'showsSinglePunch' => true]],
    'partial allowance' => ['intervalReason', ['treatment' => 'partial_allowance'], ['showsAllowedMinutes' => true]],
    'full allowance' => ['intervalReason', ['treatment' => 'full_allowance'], ['showsAllowedMinutes' => false]],
]);

test('changing the reason clears the times of the previous reason', function () {
    ($this->screen)($this->hr)
        ->call('openJustificationForm')
        ->set('justification.reason_id', $this->intervalReason->id)
        ->set('justification.start_time', '08:00')
        ->set('justification.end_time', '09:00')
        ->set('justification.observation', 'Mantida')
        ->set('justification.reason_id', $this->punchReason->id)
        ->assertSet('justification.start_time', null)
        ->assertSet('justification.end_time', null)
        ->assertSet('justification.observation', 'Mantida');
});

test('submitting validates on the server and maps the errors to the form fields', function () {
    ($this->screen)($this->hr)
        ->call('openJustificationForm')
        ->set('justification.reason_id', $this->intervalReason->id)
        ->set('justification.start_time', '10:00')
        ->set('justification.end_time', '09:00')
        ->call('submitJustification')
        ->assertHasErrors(['justification.end_time'])
        ->assertHasNoErrors(['justification.form']);
});

test('the hour bank cannot be submitted even when set by the client', function () {
    ($this->screen)($this->hr)
        ->call('openJustificationForm')
        ->set('justification.reason_id', $this->intervalReason->id)
        ->set('justification.treatment', 'hour_bank')
        ->set('justification.start_time', '08:00')
        ->set('justification.end_time', '09:00')
        ->call('submitJustification')
        ->assertHasErrors(['justification.treatment']);
});

test('an inactive reason cannot be submitted even when set by the client', function () {
    ($this->screen)($this->hr)
        ->call('openJustificationForm')
        ->set('justification.reason_id', $this->inactiveReason->id)
        ->set('justification.treatment', 'full_allowance')
        ->call('submitJustification')
        ->assertHasErrors(['justification.reason_id']);
});

test('each new form gets a new request identifier, kept across failed submissions', function () {
    $component = ($this->screen)($this->hr)->assertSet('justificationRequestUuid', null);

    $component->call('openJustificationForm');
    $first = $component->get('justificationRequestUuid');

    $component->set('justification.reason_id', $this->intervalReason->id)
        ->call('submitJustification')
        ->assertHasErrors()
        ->call('submitJustification')
        ->assertSet('justificationRequestUuid', $first);

    $component->call('openJustificationForm');
    $second = $component->get('justificationRequestUuid');

    expect(Str::isUuid($first))->toBeTrue()
        ->and(Str::isUuid($second))->toBeTrue()
        ->and($second)->not->toBe($first);
});

test('the request identifier cannot be changed by the client', function () {
    expect(fn () => ($this->screen)($this->hr)->call('openJustificationForm')->set('justificationRequestUuid', (string) Str::uuid()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('opening a new form resets the previous fields and errors', function () {
    ($this->screen)($this->hr)
        ->call('openJustificationForm')
        ->set('justification.reason_id', $this->intervalReason->id)
        ->call('submitJustification')
        ->assertHasErrors()
        ->call('openJustificationForm')
        ->assertHasNoErrors()
        ->assertSet('justification.reason_id', null)
        ->assertSet('justification.treatment', null);
});

test('a valid justification is recorded, listed and the form is closed with a new identifier', function () {
    $component = ($this->screen)($this->hr)->call('openJustificationForm');
    $uuid = $component->get('justificationRequestUuid');

    $component->set('justification.reason_id', $this->intervalReason->id)
        ->set('justification.start_time', '07:30')
        ->set('justification.end_time', '08:15')
        ->set('justification.observation', 'Ônibus quebrou')
        ->call('submitJustification')
        ->assertHasNoErrors()
        ->assertSet('showJustificationModal', false)
        ->assertSet('justification.reason_id', null)
        ->assertNotSet('justificationRequestUuid', $uuid)
        ->assertDispatched('wireui:notification')
        ->assertSee('Ônibus quebrou')
        ->assertViewHas('justifications', fn ($justifications) => $justifications->count() === 1);

    $justification = PointJustification::query()->sole();

    expect($justification->request_uuid)->toBe($uuid)
        ->and($justification->created_by)->toBe($this->hr->id)
        ->and($justification->duration_minutes)->toBe(45);
});

test('a forgotten punch creates the manual punch and refreshes the punches of the screen', function () {
    ($this->screen)($this->hr)
        ->call('openJustificationForm')
        ->set('justification.reason_id', $this->punchReason->id)
        ->set('justification.punch_kind', 'break_start')
        ->set('justification.start_time', '12:00')
        ->call('submitJustification')
        ->assertHasNoErrors()
        ->assertViewHas('points', fn ($points) => $points->pluck('type')->sort()->values()->all() === ['importado', 'manual'])
        ->assertSee('Marcação manual');

    expect(PointJustification::query()->sole()->manualPoint->type)->toBe('manual');
});

test('a resubmission of the same operation does not duplicate anything', function () {
    $component = ($this->screen)($this->hr)
        ->call('openJustificationForm')
        ->set('justification.reason_id', $this->punchReason->id)
        ->set('justification.punch_kind', 'exit')
        ->set('justification.start_time', '17:30');
    $uuid = $component->get('justificationRequestUuid');
    $snapshot = $component->get('justification');

    $component->call('submitJustification')->assertHasNoErrors();

    app(PointJustificationService::class)->register($this->employee, '2026-09-15', [...$snapshot, 'request_uuid' => $uuid], $this->hr->fresh());

    expect(PointJustification::query()->count())->toBe(1)
        ->and(Point::query()->where('type', 'manual')->count())->toBe(1);
});

test('duplicate and overlap errors are shown on the form without recording', function () {
    PointJustification::factory()->for($this->employee)->between('2026-09-15', '08:00', '10:00')->create(['reason_id' => $this->intervalReason->id]);

    ($this->screen)($this->hr)
        ->call('openJustificationForm')
        ->set('justification.reason_id', $this->intervalReason->id)
        ->set('justification.start_time', '09:00')
        ->set('justification.end_time', '11:00')
        ->call('submitJustification')
        ->assertHasErrors(['justification.period'])
        ->assertSee('Já existe uma justificativa ativa incompatível para o período')
        ->assertSet('showJustificationModal', true)
        ->set('justification.reason_id', $this->punchReason->id)
        ->set('justification.punch_kind', 'entry')
        ->set('justification.start_time', '07:30')
        ->call('submitJustification')
        ->assertHasErrors(['justification.start_time'])
        ->assertNotDispatched('wireui:notification');

    expect(PointJustification::query()->count())->toBe(1)
        ->and(Point::query()->count())->toBe(1);
});

test('the confirm button is disabled while the operation is running', function () {
    ($this->screen)($this->hr)
        ->call('openJustificationForm')
        ->set('justification.reason_id', $this->intervalReason->id)
        ->assertSeeHtml('wire:loading.attr="disabled"')
        ->assertSeeHtml('wire:target="submitJustification"');
});

describe('edition and cancellation on the screen', function () {
    beforeEach(function () {
        $this->registerOnScreen = fn (array $input) => app(PointJustificationService::class)
            ->register($this->employee, '2026-09-15', ['request_uuid' => (string) Str::uuid(), 'treatment' => 'no_allowance', ...$input], $this->hr)['justification'];
    });

    test('active justifications show the edit and cancel actions, canceled ones do not', function () {
        $active = ($this->registerOnScreen)(['reason_id' => $this->intervalReason->id, 'start_time' => '07:30', 'end_time' => '08:00']);
        $canceled = ($this->registerOnScreen)(['reason_id' => $this->intervalReason->id, 'start_time' => '09:00', 'end_time' => '10:00']);
        app(PointJustificationService::class)->cancel($canceled, 'Lançado em dobro', 1, $this->hr);

        ($this->screen)($this->hr)
            ->assertSeeHtml('editJustification('.$active->id.')')
            ->assertSeeHtml('confirmCancelJustification('.$active->id.')')
            ->assertDontSeeHtml('editJustification('.$canceled->id.')')
            ->assertDontSeeHtml('confirmCancelJustification('.$canceled->id.')')
            ->assertSee('Lançado em dobro');
    });

    test('the edit form loads the current values and version and saves the changes', function () {
        $justification = ($this->registerOnScreen)(['reason_id' => $this->intervalReason->id, 'start_time' => '07:30', 'end_time' => '08:00', 'observation' => 'Trânsito']);

        ($this->screen)($this->hr)
            ->call('editJustification', $justification->id)
            ->assertSet('showJustificationModal', true)
            ->assertSet('editingJustificationId', $justification->id)
            ->assertSet('editingVersion', 1)
            ->assertSet('justification.start_time', '07:30')
            ->assertSet('justification.end_time', '08:00')
            ->assertSet('justification.observation', 'Trânsito')
            ->assertSee('Editar Justificativa')
            ->assertSee('Salvar Alterações')
            ->set('justification.end_time', '08:30')
            ->call('submitJustification')
            ->assertHasNoErrors()
            ->assertSet('showJustificationModal', false)
            ->assertSet('editingJustificationId', null)
            ->assertDispatched('wireui:notification');

        expect($justification->fresh()->end_time)->toBe('08:30:00')
            ->and($justification->fresh()->version)->toBe(2)
            ->and(PointJustification::query()->count())->toBe(1);
    });

    test('a concurrent change shows the version conflict and keeps the form open', function () {
        $justification = ($this->registerOnScreen)(['reason_id' => $this->intervalReason->id, 'start_time' => '07:30', 'end_time' => '08:00']);

        $component = ($this->screen)($this->hr)->call('editJustification', $justification->id);

        app(PointJustificationService::class)->update($justification, ['reason_id' => $this->intervalReason->id, 'treatment' => 'no_allowance', 'start_time' => '07:30', 'end_time' => '09:00'], 1, $this->hr);

        $component->set('justification.end_time', '08:15')
            ->call('submitJustification')
            ->assertHasErrors(['justification.version'])
            ->assertSee('alterada por outro usuário')
            ->assertSet('showJustificationModal', true);

        expect($justification->fresh()->end_time)->toBe('09:00:00');
    });

    test('the version and the edited record cannot be tampered with', function (string $property) {
        $justification = ($this->registerOnScreen)(['reason_id' => $this->intervalReason->id, 'start_time' => '07:30', 'end_time' => '08:00']);

        ($this->screen)($this->hr)
            ->call('editJustification', $justification->id)
            ->set($property, 99);
    })->with(['editingVersion', 'editingJustificationId', 'cancelingVersion', 'cancelingJustificationId'])
        ->throws(CannotUpdateLockedPropertyException::class);

    test('justifications of another employee or date cannot be opened', function () {
        $other = PointJustification::factory()->create(['occurrence_date' => '2026-09-15']);
        $otherDate = PointJustification::factory()->for($this->employee)->create(['occurrence_date' => '2026-09-16']);

        ($this->screen)($this->hr)
            ->call('editJustification', $other->id)
            ->assertSet('editingJustificationId', null)
            ->call('confirmCancelJustification', $otherDate->id)
            ->assertSet('cancelingJustificationId', null)
            ->assertSet('showCancelJustificationModal', false);
    });

    test('cancellation asks for confirmation and a reason, then removes the linked punch', function () {
        $justification = ($this->registerOnScreen)(['reason_id' => $this->punchReason->id, 'start_time' => '12:00', 'punch_kind' => 'break_start']);

        $component = ($this->screen)($this->hr)
            ->call('confirmCancelJustification', $justification->id)
            ->assertSet('showCancelJustificationModal', true)
            ->assertSee('Confirma o cancelamento')
            ->assertSee('A marcação manual das 12:00 criada por esta justificativa será removida.')
            ->call('cancelJustification')
            ->assertHasErrors(['cancellationReason'])
            ->assertSet('showCancelJustificationModal', true);

        expect($justification->fresh()->isCanceled())->toBeFalse();

        $component->set('cancellationReason', 'Batida localizada no relógio')
            ->call('cancelJustification')
            ->assertHasNoErrors()
            ->assertSet('showCancelJustificationModal', false)
            ->assertDispatched('wireui:notification')
            ->assertViewHas('points', fn ($points) => $points->pluck('type')->all() === ['importado']);

        expect($justification->fresh()->isCanceled())->toBeTrue()
            ->and(Point::query()->where('type', 'manual')->count())->toBe(0);
    });

    test('a protected punch shows why the cancellation is blocked', function () {
        $justification = ($this->registerOnScreen)(['reason_id' => $this->punchReason->id, 'start_time' => '12:00', 'punch_kind' => 'break_start']);
        AfdHistoricalNsr::factory()->manualRetained($justification->manual_point_id)->create();

        ($this->screen)($this->hr)
            ->call('confirmCancelJustification', $justification->id)
            ->set('cancellationReason', 'Engano')
            ->call('cancelJustification')
            ->assertHasErrors(['cancellation.form'])
            ->assertSee('Cancelamento bloqueado')
            ->assertSet('showCancelJustificationModal', true);

        expect($justification->fresh()->isCanceled())->toBeFalse()
            ->and(Point::query()->whereKey($justification->manual_point_id)->exists())->toBeTrue();
    });

    test('the cancel button is disabled while the operation is running', function () {
        $justification = ($this->registerOnScreen)(['reason_id' => $this->intervalReason->id, 'start_time' => '07:30', 'end_time' => '08:00']);

        ($this->screen)($this->hr)
            ->call('confirmCancelJustification', $justification->id)
            ->assertSeeHtml('wire:target="cancelJustification"');
    });
});

describe('manual deletion of punches linked to a justification', function () {
    beforeEach(function () {
        $this->linked = app(PointJustificationService::class)->register($this->employee, '2026-09-15', [
            'request_uuid' => (string) Str::uuid(),
            'reason_id' => $this->punchReason->id,
            'treatment' => 'no_allowance',
            'start_time' => '12:00',
            'punch_kind' => 'break_start',
        ], $this->hr)['justification'];
        $this->table = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    });

    test('deleting the linked punch is blocked for any user, even calling the method directly', function (string $who) {
        $points = ($this->table)('points');
        $justifications = ($this->table)('point_justifications');
        $histories = ($this->table)('point_justification_histories');

        ($this->screen)($who === 'hr' ? $this->hr : $this->common)
            ->call('removePoint', $this->linked->manual_point_id)
            ->assertDispatched('wireui:notification', fn (string $event, array $params) => str_contains(json_encode($params, JSON_UNESCAPED_UNICODE), 'criada por uma justificativa ativa'));

        expect(($this->table)('points'))->toBe($points)
            ->and(($this->table)('point_justifications'))->toBe($justifications)
            ->and(($this->table)('point_justification_histories'))->toBe($histories)
            ->and($this->linked->fresh()->isCanceled())->toBeFalse()
            ->and($this->linked->fresh()->manual_point_id)->not->toBeNull();
    })->with(['hr', 'common user']);

    test('the linked punch shows a label instead of the delete button', function () {
        ($this->screen)($this->hr)
            ->assertDontSeeHtml('removePoint('.$this->linked->manual_point_id.')')
            ->assertSeeHtml(e(Point::LINKED_TO_JUSTIFICATION_MESSAGE));
    });

    test('a manual punch without a link can still be deleted', function () {
        $free = Point::factory()->forEmployee($this->employee)->on('2026-09-15')->manual()->create(['time' => '17:30:00']);

        ($this->screen)($this->common)
            ->assertSeeHtml('removePoint('.$free->id.')')
            ->call('removePoint', $free->id);

        expect(Point::query()->whereKey($free->id)->exists())->toBeFalse()
            ->and(Point::query()->whereKey($this->linked->manual_point_id)->exists())->toBeTrue();
    });

    test('protected and imported punches stay protected', function () {
        $retained = Point::factory()->forEmployee($this->employee)->on('2026-09-15')->manual()->create(['time' => '17:30:00']);
        AfdHistoricalNsr::factory()->manualRetained($retained->id)->create();
        $annotated = Point::factory()->forEmployee($this->employee)->on('2026-09-15')->manual()->create(['time' => '18:00:00']);
        AfdPointDecision::factory()->create(['point_id' => $annotated->id]);
        $points = ($this->table)('points');

        ($this->screen)($this->hr)
            ->call('removePoint', $retained->id)
            ->call('removePoint', $annotated->id)
            ->call('removePoint', $this->importedPoint->id);

        expect(($this->table)('points'))->toBe($points);
    });

    test('after the cancellation the punch is removed by the justification flow', function () {
        $pointId = $this->linked->manual_point_id;

        app(PointJustificationService::class)->cancel($this->linked, 'Engano', 1, $this->hr);

        expect(Point::query()->whereKey($pointId)->exists())->toBeFalse()
            ->and(Point::query()->whereKey($this->importedPoint->id)->exists())->toBeTrue();
    });
});

describe('deactivated reasons in existing justifications', function () {
    beforeEach(function () {
        $this->justification = app(PointJustificationService::class)->register($this->employee, '2026-09-15', [
            'request_uuid' => (string) Str::uuid(),
            'reason_id' => $this->intervalReason->id,
            'treatment' => 'full_allowance',
            'start_time' => '07:30',
            'end_time' => '08:00',
        ], $this->hr)['justification'];
        $this->intervalReason->update(['active' => false]);
        $this->catalog = fn () => DB::table('point_justification_reasons')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->catalogBefore = ($this->catalog)();
    });

    test('the edit form shows the current reason even when deactivated', function () {
        ($this->screen)($this->hr)
            ->call('editJustification', $this->justification->id)
            ->assertSet('justification.reason_id', $this->intervalReason->id)
            ->assertViewHas('selectedReason', fn ($reason) => $reason?->is($this->intervalReason))
            ->assertViewHas('reasonOptions', fn (array $options) => collect($options)->contains(fn (array $option) => $option['id'] === $this->intervalReason->id && str_ends_with($option['name'], '(desativado)')))
            ->assertSee('Horário inicial');
    });

    test('other fields are saved keeping the deactivated reason', function () {
        ($this->screen)($this->hr)
            ->call('editJustification', $this->justification->id)
            ->set('justification.end_time', '08:30')
            ->set('justification.observation', 'Conferido')
            ->call('submitJustification')
            ->assertHasNoErrors();

        $fresh = $this->justification->fresh();

        expect($fresh->reason_id)->toBe($this->intervalReason->id)
            ->and($fresh->end_time)->toBe('08:30:00')
            ->and($fresh->observation)->toBe('Conferido')
            ->and($fresh->version)->toBe(2)
            ->and($this->intervalReason->fresh()->active)->toBeFalse()
            ->and(($this->catalog)())->toBe($this->catalogBefore);
    });

    test('switching to another active reason is allowed', function () {
        $active = PointJustificationReason::factory()->create(['name' => 'Saída antecipada', 'time_mode' => JustificationTimeMode::Interval]);
        $this->catalogBefore = ($this->catalog)();

        ($this->screen)($this->hr)
            ->call('editJustification', $this->justification->id)
            ->set('justification.reason_id', $active->id)
            ->set('justification.treatment', 'full_allowance')
            ->set('justification.start_time', '16:00')
            ->set('justification.end_time', '17:00')
            ->call('submitJustification')
            ->assertHasNoErrors();

        expect($this->justification->fresh()->reason_id)->toBe($active->id)
            ->and(($this->catalog)())->toBe($this->catalogBefore);
    });

    test('another deactivated reason cannot be selected, on the screen or in the service', function () {
        ($this->screen)($this->hr)
            ->call('editJustification', $this->justification->id)
            ->set('justification.reason_id', $this->inactiveReason->id)
            ->assertViewHas('selectedReason', null)
            ->assertViewHas('reasonOptions', fn (array $options) => ! collect($options)->contains('id', $this->inactiveReason->id))
            ->set('justification.treatment', 'full_allowance')
            ->set('justification.start_time', '07:30')
            ->set('justification.end_time', '08:00')
            ->call('submitJustification')
            ->assertHasErrors(['justification.reason_id']);

        expect(fn () => app(PointJustificationService::class)->update($this->justification, [
            'reason_id' => $this->inactiveReason->id, 'treatment' => 'full_allowance', 'start_time' => '07:30', 'end_time' => '08:00',
        ], 1, $this->hr))->toThrow(ValidationException::class);

        expect($this->justification->fresh()->reason_id)->toBe($this->intervalReason->id)
            ->and($this->justification->fresh()->version)->toBe(1);
    });

    test('a new justification never accepts a deactivated reason, not even the one kept by another record', function () {
        ($this->screen)($this->hr)
            ->call('openJustificationForm')
            ->assertViewHas('reasonOptions', fn (array $options) => ! collect($options)->contains('id', $this->intervalReason->id))
            ->set('justification.reason_id', $this->intervalReason->id)
            ->set('justification.treatment', 'full_allowance')
            ->set('justification.start_time', '09:00')
            ->set('justification.end_time', '10:00')
            ->call('submitJustification')
            ->assertHasErrors(['justification.reason_id']);

        expect(PointJustification::query()->count())->toBe(1)
            ->and(($this->catalog)())->toBe($this->catalogBefore);
    });
});
