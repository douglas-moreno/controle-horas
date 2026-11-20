<?php

use App\Enums\JustificationCategory;
use App\Enums\JustificationTimeMode;
use App\Enums\JustificationTreatment;
use App\Enums\Permission;
use App\Livewire\EmployeePointsEdit;
use App\Models\Employee;
use App\Models\Point;
use App\Models\PointJustification;
use App\Models\PointJustificationReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

test('valid data is not recorded and no success is reported', function () {
    $before = DB::table('points')->orderBy('id')->get();

    ($this->screen)($this->hr)
        ->call('openJustificationForm')
        ->set('justification.reason_id', $this->punchReason->id)
        ->set('justification.punch_kind', 'exit')
        ->set('justification.start_time', '17:30')
        ->call('submitJustification')
        ->assertHasErrors(['justification.form'])
        ->assertSee('Nada foi registrado.')
        ->assertNotDispatched('wireui:notification');

    expect(PointJustification::query()->count())->toBe(0)
        ->and(DB::table('points')->orderBy('id')->get())->toEqual($before);
});

test('each new form gets a new request identifier, kept across submissions', function () {
    $component = ($this->screen)($this->hr)->assertSet('justificationRequestUuid', null);

    $component->call('openJustificationForm');
    $first = $component->get('justificationRequestUuid');

    $component->set('justification.reason_id', $this->fullDayReason->id)
        ->call('submitJustification')
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
