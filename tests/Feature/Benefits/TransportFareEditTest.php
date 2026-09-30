<?php

use App\Livewire\Benefits\TransportFareEdit;
use App\Models\TransportFare;
use App\Models\TransportFarePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->fare = TransportFare::factory()->create(['name' => 'CPTM', 'operator' => 'CPTM']);
});

test('the edit screen loads the fare and its price history', function () {
    TransportFarePrice::factory()->for($this->fare)->amount('5.00')->validFrom('2026-01-01')->create();
    TransportFarePrice::factory()->for($this->fare)->amount('5.40')->validFrom('2026-09-01')->create();

    $this->get(route('benefits.fares.edit', $this->fare))
        ->assertOk()
        ->assertSee('Editar Tarifa CPTM')
        ->assertSee('R$ 5,00')
        ->assertSee('R$ 5,40')
        ->assertSee('01/09/2026');

    Livewire::test(TransportFareEdit::class, ['transportFare' => $this->fare])
        ->assertSet('name', 'CPTM')
        ->assertSet('operator', 'CPTM')
        ->assertSet('isActive', true);
});

test('an unknown fare returns not found', function () {
    $this->get(route('benefits.fares.edit', 999))->assertNotFound();
});

test('name, operator and status can be changed', function () {
    Livewire::test(TransportFareEdit::class, ['transportFare' => $this->fare])
        ->set('name', 'CPTM Linha 10')
        ->set('operator', '')
        ->set('isActive', false)
        ->call('updateFare')
        ->assertHasNoErrors();

    expect($this->fare->fresh()->only('name', 'operator', 'is_active'))
        ->toBe(['name' => 'CPTM Linha 10', 'operator' => null, 'is_active' => false]);
});

test('the fare keeps its own name when saved', function () {
    Livewire::test(TransportFareEdit::class, ['transportFare' => $this->fare])
        ->call('updateFare')
        ->assertHasNoErrors();
});

test('the name cannot duplicate another fare', function () {
    TransportFare::factory()->create(['name' => 'SP Trans']);

    Livewire::test(TransportFareEdit::class, ['transportFare' => $this->fare])
        ->set('name', 'SP Trans')
        ->call('updateFare')
        ->assertHasErrors(['name' => 'unique']);

    expect($this->fare->fresh()->name)->toBe('CPTM');
});

test('a new price is created as a new row starting on the chosen date', function () {
    $previous = TransportFarePrice::factory()->for($this->fare)->amount('5.40')->validFrom('2026-09-01')->create();

    Livewire::test(TransportFareEdit::class, ['transportFare' => $this->fare])
        ->call('openPriceModal')
        ->set('newPriceAmount', '5,8')
        ->set('newPriceValidFrom', '2026-10-15T00:00:00-03:00')
        ->call('createPrice')
        ->assertHasNoErrors()
        ->assertSet('showPriceModal', false);

    $newPrice = $this->fare->prices()->latest('valid_from')->first();

    expect($this->fare->prices()->count())->toBe(2)
        ->and($newPrice->amount)->toBe('5.80')
        ->and($newPrice->created_by)->toBe($this->user->id)
        ->and(DB::table('transport_fare_prices')->where('id', $newPrice->id)->value('valid_from'))->toBe('2026-10-15')
        ->and($previous->fresh()->amount)->toBe('5.40')
        ->and($previous->fresh()->valid_from->toDateString())->toBe('2026-09-01');
});

test('the price amount is validated', function (string $amount) {
    Livewire::test(TransportFareEdit::class, ['transportFare' => $this->fare])
        ->set('newPriceAmount', $amount)
        ->set('newPriceValidFrom', '2026-10-01')
        ->call('createPrice')
        ->assertHasErrors('newPriceAmount');

    expect($this->fare->prices()->count())->toBe(0);
})->with(['', '5.405', 'cinco', '-5.40']);

test('the price start date is required and valid', function (?string $validFrom) {
    Livewire::test(TransportFareEdit::class, ['transportFare' => $this->fare])
        ->set('newPriceAmount', '5.40')
        ->set('newPriceValidFrom', $validFrom)
        ->call('createPrice')
        ->assertHasErrors('newPriceValidFrom');

    expect($this->fare->prices()->count())->toBe(0);
})->with([null, '', 'amanhã']);

test('a second price on the same start date is rejected', function () {
    TransportFarePrice::factory()->for($this->fare)->amount('5.40')->validFrom('2026-10-01')->create();

    Livewire::test(TransportFareEdit::class, ['transportFare' => $this->fare])
        ->set('newPriceAmount', '5.80')
        ->set('newPriceValidFrom', '2026-10-01')
        ->call('createPrice')
        ->assertHasErrors('newPriceValidFrom');

    expect($this->fare->prices()->count())->toBe(1)
        ->and($this->fare->prices()->sole()->amount)->toBe('5.40');
});

test('another fare may have a price on the same date', function () {
    TransportFarePrice::factory()->amount('4.55')->validFrom('2026-10-01')->create();

    Livewire::test(TransportFareEdit::class, ['transportFare' => $this->fare])
        ->set('newPriceAmount', '5.40')
        ->set('newPriceValidFrom', '2026-10-01')
        ->call('createPrice')
        ->assertHasNoErrors();

    expect($this->fare->prices()->count())->toBe(1);
});
