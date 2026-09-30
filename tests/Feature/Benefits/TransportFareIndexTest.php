<?php

use App\Livewire\Benefits\TransportFareIndex;
use App\Models\TransportFare;
use App\Models\TransportFarePrice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('the fares screen lists fares with the price in force today', function () {
    CarbonImmutable::setTestNow('2026-10-10');
    $fare = TransportFare::factory()->create(['name' => 'CPTM', 'operator' => 'Companhia Paulista']);
    TransportFarePrice::factory()->for($fare)->amount('5.00')->validFrom('2026-01-01')->create();
    TransportFarePrice::factory()->for($fare)->amount('5.40')->validFrom('2026-09-01')->create();
    TransportFare::factory()->inactive()->create(['name' => 'Linha Desativada']);

    $this->get(route('benefits.fares.index'))
        ->assertOk()
        ->assertSee('CPTM')
        ->assertSee('Companhia Paulista')
        ->assertSee('R$ 5,40')
        ->assertDontSee('R$ 5,00')
        ->assertSee('Linha Desativada')
        ->assertSee('Inativa')
        ->assertSee('Sem preço vigente');
});

test('the list can be filtered', function () {
    TransportFare::factory()->create(['name' => 'CPTM']);
    TransportFare::factory()->create(['name' => 'Trolebus']);

    Livewire::test(TransportFareIndex::class)
        ->set('search', 'cpt')
        ->assertSee('CPTM')
        ->assertDontSee('Trolebus');
});

test('a fare is created without a price', function () {
    Livewire::test(TransportFareIndex::class)
        ->call('openCreateModal')
        ->set('name', '  CPTM  ')
        ->set('operator', '')
        ->call('createFare')
        ->assertHasNoErrors()
        ->assertSet('showCreateModal', false);

    $fare = TransportFare::sole();

    expect($fare->name)->toBe('CPTM')
        ->and($fare->operator)->toBeNull()
        ->and($fare->is_active)->toBeTrue()
        ->and($fare->prices()->count())->toBe(0);
});

test('a fare can be created inactive with an operator', function () {
    Livewire::test(TransportFareIndex::class)
        ->set('name', 'SP Trans')
        ->set('operator', 'SPTrans')
        ->set('isActive', false)
        ->call('createFare')
        ->assertHasNoErrors();

    expect(TransportFare::sole()->only('operator', 'is_active'))->toBe(['operator' => 'SPTrans', 'is_active' => false]);
});

test('the name is required and limited', function (string $name, string $rule) {
    Livewire::test(TransportFareIndex::class)
        ->set('name', $name)
        ->call('createFare')
        ->assertHasErrors(['name' => $rule]);

    expect(TransportFare::count())->toBe(0);
})->with([
    'empty' => ['', 'required'],
    'blank' => ['   ', 'required'],
    'too long' => [str_repeat('a', 101), 'max'],
]);

test('fare names are unique', function () {
    TransportFare::factory()->create(['name' => 'CPTM']);

    Livewire::test(TransportFareIndex::class)
        ->set('name', 'CPTM')
        ->call('createFare')
        ->assertHasErrors(['name' => 'unique']);

    expect(TransportFare::count())->toBe(1);
});
