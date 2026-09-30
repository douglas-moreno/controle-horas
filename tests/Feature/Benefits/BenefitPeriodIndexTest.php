<?php

use App\Livewire\Benefits\BenefitPeriodIndex;
use App\Models\BenefitPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the periods screen lists competences from the most recent', function () {
    BenefitPeriod::factory()->forCompetence('2026-08')->closed()->create(['business_days' => 21]);
    BenefitPeriod::factory()->forCompetence('2026-10')->create();
    BenefitPeriod::factory()->forCompetence('2026-09')->calculated()->create(['business_days' => 21]);

    $this->get(route('benefits.periods.index'))
        ->assertOk()
        ->assertSeeInOrder(['10/2026', '09/2026', '08/2026'])
        ->assertSee('01/09 – 30/09/2026')
        ->assertSee('Aberta')
        ->assertSee('Calculada')
        ->assertSee('Fechada');
});

test('the sidebar shows the competences item', function () {
    $this->get(route('benefits.rates.index'))
        ->assertOk()
        ->assertSee('Competências')
        ->assertSee(route('benefits.periods.index'));
});

test('the first competence is created from the informed month', function () {
    Livewire::test(BenefitPeriodIndex::class)
        ->assertSee('Criar Primeira Competência')
        ->set('firstCompetenceMonth', '2026-10')
        ->call('createNext')
        ->assertHasNoErrors();

    expect(BenefitPeriod::sole()->competence->toDateString())->toBe('2026-10-01');
});

test('the first competence month is required', function () {
    Livewire::test(BenefitPeriodIndex::class)
        ->call('createNext')
        ->assertHasErrors('firstCompetenceMonth');

    expect(BenefitPeriod::count())->toBe(0);
});

test('the next competence is created automatically', function () {
    BenefitPeriod::factory()->forCompetence('2026-09')->closed()->create();

    Livewire::test(BenefitPeriodIndex::class)
        ->assertSee('Criar Próxima Competência (10/2026)')
        ->set('firstCompetenceMonth', '2027-05')
        ->call('createNext')
        ->assertHasNoErrors();

    expect(BenefitPeriod::orderByDesc('competence')->first()->competence->toDateString())->toBe('2026-10-01')
        ->and(BenefitPeriod::count())->toBe(2);
});

test('the delete action appears only for the deletable competence', function () {
    $september = BenefitPeriod::factory()->forCompetence('2026-09')->create();
    $october = BenefitPeriod::factory()->forCompetence('2026-10')->create();

    Livewire::test(BenefitPeriodIndex::class)
        ->assertViewHas('deletablePeriodIds', [$october->id])
        ->assertSee('destroy('.$october->id.')')
        ->assertDontSee('destroy('.$september->id.')');
});

test('deleting goes through the workflow', function () {
    $period = BenefitPeriod::factory()->forCompetence('2026-10')->create();

    Livewire::test(BenefitPeriodIndex::class)->call('destroy', $period->id);

    $this->assertModelMissing($period);
});

test('a blocked deletion keeps the competence', function () {
    $period = BenefitPeriod::factory()->forCompetence('2026-10')->closed()->create();

    Livewire::test(BenefitPeriodIndex::class)
        ->assertViewHas('deletablePeriodIds', [])
        ->call('destroy', $period->id);

    $this->assertModelExists($period);
});
