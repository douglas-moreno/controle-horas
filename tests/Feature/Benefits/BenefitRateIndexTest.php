<?php

use App\Enums\BenefitType;
use App\Livewire\Benefits\BenefitRateIndex;
use App\Models\BenefitCalculation;
use App\Models\BenefitRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('the rates screen renders with the history', function () {
    BenefitRate::factory()->vr('27.50')->validFrom('2026-09-01')->create();
    BenefitRate::factory()->vd('7.50')->validFrom('2026-09-01')->create();

    $this->get(route('benefits.rates.index'))
        ->assertOk()
        ->assertSee('Valores VR / VD')
        ->assertSee('R$ 27,50')
        ->assertSee('R$ 7,50')
        ->assertSee('09/2026');
});

test('guests are redirected to the login', function () {
    auth()->logout();

    $this->get(route('benefits.rates.index'))->assertRedirect(route('login'));
});

test('a vr validity is registered from the chosen month', function () {
    Livewire::test(BenefitRateIndex::class)
        ->call('openCreateModal')
        ->set('benefitType', 'vr')
        ->set('amount', '27,50')
        ->set('validFromMonth', '2026-09')
        ->call('createRate')
        ->assertHasNoErrors()
        ->assertSet('showCreateModal', false);

    $rate = BenefitRate::sole();

    expect($rate->benefit_type)->toBe(BenefitType::Vr)
        ->and($rate->amount)->toBe('27.50')
        ->and($rate->created_by)->toBe($this->user->id)
        ->and(DB::table('benefit_rates')->value('valid_from'))->toBe('2026-09-01');
});

test('a vd validity is registered', function () {
    Livewire::test(BenefitRateIndex::class)
        ->set('benefitType', 'vd')
        ->set('amount', '7.5')
        ->set('validFromMonth', '2026-10')
        ->call('createRate')
        ->assertHasNoErrors();

    expect(BenefitRate::sole()->only('benefit_type', 'amount'))->toBe(['benefit_type' => BenefitType::Vd, 'amount' => '7.50']);
});

test('only vr and vd are accepted', function (string $benefitType) {
    Livewire::test(BenefitRateIndex::class)
        ->set('benefitType', $benefitType)
        ->set('amount', '10.00')
        ->set('validFromMonth', '2026-10')
        ->call('createRate')
        ->assertHasErrors(['benefitType' => 'in']);

    expect(BenefitRate::count())->toBe(0);
})->with(['vt', 'other']);

test('the amount is validated', function (string $amount) {
    Livewire::test(BenefitRateIndex::class)
        ->set('amount', $amount)
        ->set('validFromMonth', '2026-10')
        ->call('createRate')
        ->assertHasErrors('amount');

    expect(BenefitRate::count())->toBe(0);
})->with(['', '27.505', 'abc', '-5.00', '1.234,56']);

test('the start must be a month and never an arbitrary day', function (string $validFromMonth) {
    Livewire::test(BenefitRateIndex::class)
        ->set('amount', '27.50')
        ->set('validFromMonth', $validFromMonth)
        ->call('createRate')
        ->assertHasErrors('validFromMonth');

    expect(BenefitRate::count())->toBe(0);
})->with(['', '2026-09-15', '15/09/2026', '2026-13']);

test('a second validity for the same type and month is rejected', function () {
    BenefitRate::factory()->vr('27.50')->validFrom('2026-10-01')->create();

    Livewire::test(BenefitRateIndex::class)
        ->set('benefitType', 'vr')
        ->set('amount', '30.00')
        ->set('validFromMonth', '2026-10')
        ->call('createRate')
        ->assertHasErrors('validFromMonth');

    expect(BenefitRate::count())->toBe(1)
        ->and(BenefitRate::sole()->amount)->toBe('27.50');
});

test('vd may start in the same month as vr', function () {
    BenefitRate::factory()->vr('27.50')->validFrom('2026-10-01')->create();

    Livewire::test(BenefitRateIndex::class)
        ->set('benefitType', 'vd')
        ->set('amount', '7.50')
        ->set('validFromMonth', '2026-10')
        ->call('createRate')
        ->assertHasNoErrors();

    expect(BenefitRate::count())->toBe(2);
});

test('an unused validity can be deleted', function () {
    $rate = BenefitRate::factory()->create();

    Livewire::test(BenefitRateIndex::class)->call('destroy', $rate->id);

    $this->assertModelMissing($rate);
});

test('a validity used in a calculation cannot be deleted', function () {
    $rate = BenefitRate::factory()->create();
    BenefitCalculation::factory()->create(['benefit_rate_id' => $rate->id]);

    Livewire::test(BenefitRateIndex::class)->call('destroy', $rate->id);

    $this->assertModelExists($rate);
});
