<?php

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\AdjustmentStatus;
use App\Enums\BenefitPeriodStatus;
use App\Livewire\Benefits\BenefitPeriodAdjustments;
use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use App\Services\BenefitAdjustmentRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Holiday::factory()->create(['date' => '2026-09-07', 'description' => 'Independência']);

    $this->registrar = app(BenefitAdjustmentRegistrar::class);
    $this->period = BenefitPeriod::factory()->forCompetence('2026-10')->create();
    $this->employee = Employee::factory()->create(['name' => 'Ana Souza']);

    $this->screen = fn () => Livewire::test(BenefitPeriodAdjustments::class, ['benefitPeriod' => $this->period]);

    $this->manual = fn (AdjustmentReason $reason, string $startsOn, ?string $endsOn = null, ?string $notes = null, ?Employee $employee = null, array $impacts = []) => $this->registrar->register(
        $this->period,
        $employee ?? $this->employee,
        $reason,
        AdjustmentSource::Manual,
        CarbonImmutable::parse($startsOn),
        CarbonImmutable::parse($endsOn ?? $startsOn),
        $notes,
        $impacts,
    )['adjustment'];

    $this->suggestion = fn (string $date, AdjustmentReason $reason = AdjustmentReason::UnjustifiedAbsence) => $this->registrar
        ->registerSuggestion($this->period, $this->employee, $reason, CarbonImmutable::parse($date))['adjustment'];
});

/**
 * @return array<string, list<int>> ids por seção (forecast, realized, retroactive)
 */
function sectionIds($component): array
{
    return $component->viewData('sections')
        ->map(fn (array $section) => $section['adjustments']->modelKeys())
        ->all();
}

test('the route renders the adjustments of the competence', function () {
    ($this->manual)(AdjustmentReason::Vacation, '2026-09-15');

    $this->get(route('benefits.periods.adjustments', $this->period))
        ->assertOk()
        ->assertSee('Ajustes — Competência 10/2026')
        ->assertSee('01/09/2026 a 30/09/2026')
        ->assertSee('Ana Souza')
        ->assertSee('Férias');
});

test('the periods list links to the adjustments screen', function () {
    $this->get(route('benefits.periods.index'))->assertSee(route('benefits.periods.adjustments', $this->period));
});

test('only adjustments of the loaded competence are listed', function () {
    $mine = ($this->manual)(AdjustmentReason::Vacation, '2026-09-15');
    $other = BenefitPeriod::factory()->forCompetence('2026-09')->create();
    $foreign = BenefitAdjustment::factory()->for($other)->for($this->employee)->absence('2026-08-14')->create();

    $ids = collect(sectionIds(($this->screen)()))->flatten()->all();

    expect($ids)->toContain($mine->id)->not->toContain($foreign->id);
});

test('adjustments are split into forecast, realized and retroactive sections', function () {
    $forecast = ($this->manual)(AdjustmentReason::Vacation, '2026-10-05');
    $realized = ($this->manual)(AdjustmentReason::Vacation, '2026-09-15');
    $retroactive = ($this->manual)(AdjustmentReason::Vacation, '2026-08-14', notes: 'Falta de agosto.');

    ($this->screen)()
        ->assertSeeInOrder(['Previstos', 'Realizados', 'Correções retroativas'])
        ->assertSee('Retroativo')
        ->tap(fn ($component) => expect(sectionIds($component))->toBe([
            'forecast' => [$forecast->id],
            'realized' => [$realized->id],
            'retroactive' => [$retroactive->id],
        ]));
});

test('filters narrow the list', function () {
    $other = Employee::factory()->create(['name' => 'Bruno Lima']);
    $vacation = ($this->manual)(AdjustmentReason::Vacation, '2026-09-15');
    $suggestion = ($this->suggestion)('2026-09-16');
    $otherVacation = ($this->manual)(AdjustmentReason::Vacation, '2026-09-15', employee: $other);
    $rejected = $this->registrar->reject(($this->suggestion)('2026-09-17'), 'Não procede.');

    $ids = fn ($component) => collect(sectionIds($component))->flatten()->sort()->values()->all();

    expect($ids(($this->screen)()->set('filterEmployeeId', (string) $other->id)))->toBe([$otherVacation->id])
        ->and($ids(($this->screen)()->set('filterReason', 'vacation')))->toBe([$vacation->id, $otherVacation->id])
        ->and($ids(($this->screen)()->set('filterSource', 'timesheet')))->toBe([$suggestion->id, $rejected->id])
        ->and($ids(($this->screen)()->set('filterStatus', 'rejected')))->toBe([$rejected->id]);
});

test('the new adjustment button opens the form', function () {
    ($this->screen)()
        ->assertSee('Novo ajuste')
        ->call('openCreateModal')
        ->assertSet('showFormModal', true)
        ->assertSet('formMode', 'create');
});

test('a manual adjustment is created through the registrar', function () {
    ($this->screen)()
        ->call('openCreateModal')
        ->set('formEmployeeId', $this->employee->id)
        ->set('formReason', 'vacation')
        ->set('formStartsOn', '2026-09-05')
        ->set('formEndsOn', '2026-09-14')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showFormModal', false);

    $adjustment = BenefitAdjustment::sole();

    expect($adjustment->source)->toBe(AdjustmentSource::Manual)
        ->and($adjustment->status)->toBe(AdjustmentStatus::Confirmed)
        ->and($adjustment->days_count)->toBe(5)
        ->and($adjustment->impacts()->pluck('quantity')->all())->toBe([-5, -5, -5]);
});

test('the form validates required fields', function () {
    ($this->screen)()
        ->call('openCreateModal')
        ->call('save')
        ->assertHasErrors(['formEmployeeId', 'formStartsOn']);

    expect(BenefitAdjustment::count())->toBe(0);
});

test('standard reasons show the automatic impact instead of editable inputs', function () {
    ($this->screen)()
        ->call('openCreateModal')
        ->set('formEmployeeId', $this->employee->id)
        ->set('formReason', 'vacation')
        ->set('formStartsOn', '2026-09-05')
        ->set('formEndsOn', '2026-09-14')
        ->assertSee('Impacto automático')
        ->assertSee('5 dia(s)')
        ->assertDontSee('Impacto VT');
});

test('standard reasons ignore impact inputs sent to the component', function () {
    ($this->screen)()
        ->call('openCreateModal')
        ->set('formEmployeeId', $this->employee->id)
        ->set('formReason', 'vacation')
        ->set('formStartsOn', '2026-09-15')
        ->set('formImpactVt', '9')
        ->call('save')
        ->assertHasNoErrors();

    expect(BenefitAdjustment::sole()->impacts()->pluck('quantity')->all())->toBe([-1, -1, -1]);
});

test('the manual reason shows free impact inputs and saves them', function () {
    ($this->screen)()
        ->call('openCreateModal')
        ->set('formEmployeeId', $this->employee->id)
        ->set('formReason', 'manual')
        ->set('formStartsOn', '2026-09-15')
        ->assertSee('Impacto VT')
        ->assertSee('Impacto VR')
        ->assertSee('Impacto VD')
        ->set('formImpactVt', '2')
        ->set('formImpactVd', '-1')
        ->set('formNotes', 'Pago a menor.')
        ->call('save')
        ->assertHasNoErrors();

    expect(BenefitAdjustment::sole()->impacts()->orderBy('benefit_type')->pluck('quantity', 'benefit_type')->all())
        ->toBe(['vd' => -1, 'vt' => 2]);
});

test('the service rules are shown as blocking errors', function () {
    ($this->screen)()
        ->call('openCreateModal')
        ->set('formEmployeeId', $this->employee->id)
        ->set('formReason', 'manual')
        ->set('formStartsOn', '2026-09-15')
        ->set('formImpactVt', '2')
        ->call('save')
        ->assertHasErrors('form')
        ->assertSee('O ajuste manual exige observação');

    expect(BenefitAdjustment::count())->toBe(0);
});

test('DD2 and DD3 are shown as blocking before and after saving', function (AdjustmentReason $existing, string $newReason, string $date, string $message) {
    ($this->manual)($existing, $date);

    ($this->screen)()
        ->call('openCreateModal')
        ->set('formEmployeeId', $this->employee->id)
        ->set('formReason', $newReason)
        ->set('formStartsOn', $date)
        ->assertSee('Bloqueio: '.$message, false)
        ->call('save')
        ->assertHasErrors('form');

    expect(BenefitAdjustment::count())->toBe(1);
})->with([
    'DD2' => [AdjustmentReason::Vacation, 'medical_certificate', '2026-09-15', 'Já existe uma ausência não rejeitada'],
    'DD3' => [AdjustmentReason::SaturdayWorked, 'sunday_worked', '2026-09-12', 'Já existe um trabalho não rejeitado'],
]);

test('the DD5 alert is shown and does not block', function () {
    ($this->manual)(AdjustmentReason::SaturdayWorked, '2026-09-12');

    ($this->screen)()
        ->call('openCreateModal')
        ->set('formEmployeeId', $this->employee->id)
        ->set('formReason', 'manual')
        ->set('formStartsOn', '2026-09-12')
        ->assertSee('Alerta: Já existe outro ajuste para este funcionário nesta data')
        ->set('formImpactVt', '2')
        ->set('formNotes', 'Pago a menor.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Alerta: Já existe outro ajuste para este funcionário nesta data');

    expect(BenefitAdjustment::count())->toBe(2);
});

test('the DD6 informative alert is shown and does not block', function () {
    $september = BenefitPeriod::factory()->forCompetence('2026-09')->closed()->create();
    BenefitAdjustment::factory()->for($september)->for($this->employee)->absence('2026-08-14')->create();

    ($this->screen)()
        ->call('openCreateModal')
        ->set('formEmployeeId', $this->employee->id)
        ->set('formReason', 'unjustified_absence')
        ->set('formStartsOn', '2026-08-14')
        ->assertSee('Correção retroativa')
        ->assertSee('Informativo: Este funcionário já possui efeito registrado para esta data na competência 09/2026')
        ->set('formNotes', 'Estorno.')
        ->call('save')
        ->assertHasNoErrors();

    expect(BenefitAdjustment::count())->toBe(2);
});

test('actions depend on the adjustment status', function () {
    $pending = ($this->suggestion)('2026-09-15');
    $confirmed = ($this->manual)(AdjustmentReason::Vacation, '2026-09-21');
    $rejected = $this->registrar->reject(($this->suggestion)('2026-09-16'), 'Não procede.');

    ($this->screen)()
        ->assertSee('openUpdateModal('.$pending->id.')')
        ->assertSee('confirm('.$pending->id.')')
        ->assertSee('openRejectModal('.$pending->id.')')
        ->assertSee('openReplaceModal('.$confirmed->id.')')
        ->assertDontSee('openUpdateModal('.$confirmed->id.')')
        ->assertSee('reconsider('.$rejected->id.')')
        ->assertDontSee('confirm('.$rejected->id.')')
        ->assertDontSee('Excluir');
});

test('a pending adjustment is updated through the form', function () {
    $pending = ($this->suggestion)('2026-09-15');

    ($this->screen)()
        ->call('openUpdateModal', $pending->id)
        ->assertSet('formMode', 'update')
        ->assertSet('formReason', 'unjustified_absence')
        ->set('formReason', 'medical_certificate')
        ->call('save')
        ->assertHasNoErrors();

    expect($pending->fresh()->reason)->toBe(AdjustmentReason::MedicalCertificate)
        ->and($pending->fresh()->source)->toBe(AdjustmentSource::Timesheet)
        ->and(BenefitAdjustment::count())->toBe(1);
});

test('a confirmed adjustment is replaced through the form', function () {
    $confirmed = ($this->manual)(AdjustmentReason::Vacation, '2026-10-01', '2026-10-05');

    ($this->screen)()
        ->call('openReplaceModal', $confirmed->id)
        ->assertSet('formMode', 'replace')
        ->set('formEndsOn', '2026-10-02')
        ->call('save')
        ->assertHasNoErrors();

    $replacement = BenefitAdjustment::whereKeyNot($confirmed->id)->sole();

    expect($confirmed->fresh()->status)->toBe(AdjustmentStatus::Rejected)
        ->and($replacement->related_adjustment_id)->toBe($confirmed->id)
        ->and($replacement->days_count)->toBe(2);
});

test('a pending adjustment is confirmed individually', function () {
    $pending = ($this->suggestion)('2026-09-15');

    ($this->screen)()->call('confirm', $pending->id);

    expect($pending->fresh()->status)->toBe(AdjustmentStatus::Confirmed);
});

test('rejection requires a note', function () {
    $pending = ($this->suggestion)('2026-09-15');

    ($this->screen)()
        ->call('openRejectModal', $pending->id)
        ->assertSet('showRejectModal', true)
        ->call('reject')
        ->assertHasErrors('rejectNotes')
        ->set('rejectNotes', 'Treinamento externo.')
        ->call('reject')
        ->assertHasNoErrors()
        ->assertSet('showRejectModal', false);

    expect($pending->fresh()->status)->toBe(AdjustmentStatus::Rejected)
        ->and($pending->fresh()->review_notes)->toBe('Treinamento externo.');
});

test('a rejected adjustment is reconsidered', function () {
    $rejected = $this->registrar->reject(($this->suggestion)('2026-09-15'), 'Não procede.');

    ($this->screen)()->call('reconsider', $rejected->id);

    expect($rejected->fresh()->status)->toBe(AdjustmentStatus::Pending);
});

test('selected adjustments are confirmed in batch', function () {
    $first = ($this->suggestion)('2026-09-15');
    $second = ($this->suggestion)('2026-09-16');
    $untouched = ($this->suggestion)('2026-09-17');

    ($this->screen)()
        ->set('selected', [(string) $first->id, (string) $second->id])
        ->call('confirmSelected')
        ->assertSet('selected', []);

    expect($first->fresh()->status)->toBe(AdjustmentStatus::Confirmed)
        ->and($second->fresh()->status)->toBe(AdjustmentStatus::Confirmed)
        ->and($untouched->fresh()->status)->toBe(AdjustmentStatus::Pending);
});

test('a failing batch item keeps every adjustment unchanged', function () {
    $first = ($this->suggestion)('2026-09-15');
    $confirmed = $this->registrar->confirm(($this->suggestion)('2026-09-16'));

    ($this->screen)()
        ->set('selected', [$first->id, $confirmed->id])
        ->call('confirmSelected');

    expect($first->fresh()->status)->toBe(AdjustmentStatus::Pending);
});

test('selected adjustments are rejected in batch with a note', function () {
    $first = ($this->suggestion)('2026-09-15');
    $second = ($this->suggestion)('2026-09-16');

    ($this->screen)()
        ->set('selected', [$first->id, $second->id])
        ->call('openRejectSelectedModal')
        ->assertSet('rejectingIds', [$first->id, $second->id])
        ->call('reject')
        ->assertHasErrors('rejectNotes')
        ->set('rejectNotes', 'Treinamento externo.')
        ->call('reject')
        ->assertHasNoErrors();

    expect($first->fresh()->status)->toBe(AdjustmentStatus::Rejected)
        ->and($second->fresh()->status)->toBe(AdjustmentStatus::Rejected);
});

test('a change on a calculated competence invalidates it', function () {
    $pending = ($this->suggestion)('2026-09-15');
    $this->period->forceFill(['status' => BenefitPeriodStatus::Calculated, 'business_days' => 21])->save();

    ($this->screen)()
        ->assertSee('descarta a prévia')
        ->call('confirm', $pending->id)
        ->assertDontSee('descarta a prévia');

    expect($this->period->fresh()->status)->toBe(BenefitPeriodStatus::Open)
        ->and($pending->fresh()->status)->toBe(AdjustmentStatus::Confirmed);
});

test('a closed competence shows no actions and refuses them', function () {
    $pending = ($this->suggestion)('2026-09-15');
    $confirmed = ($this->manual)(AdjustmentReason::Vacation, '2026-09-21');
    $this->period->forceFill(['status' => BenefitPeriodStatus::Closed])->save();

    ($this->screen)()
        ->assertSee('Competência fechada')
        ->assertDontSee('Novo ajuste')
        ->assertDontSee('confirm('.$pending->id.')')
        ->assertDontSee('openReplaceModal('.$confirmed->id.')')
        ->call('confirm', $pending->id)
        ->call('openCreateModal')
        ->set('formEmployeeId', $this->employee->id)
        ->set('formReason', 'vacation')
        ->set('formStartsOn', '2026-09-22')
        ->call('save')
        ->assertHasErrors('form');

    expect($pending->fresh()->status)->toBe(AdjustmentStatus::Pending)
        ->and(BenefitAdjustment::count())->toBe(2);
});
