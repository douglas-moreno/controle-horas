<?php

use App\Models\TransportFare;
use App\Models\TransportFarePrice;
use App\Services\TransportFarePriceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->resolver = new TransportFarePriceResolver;
    $this->fare = TransportFare::factory()->create(['name' => 'CPTM']);

    TransportFarePrice::factory()->for($this->fare)->amount('5.00')->validFrom('2026-01-01')->create();
    TransportFarePrice::factory()->for($this->fare)->amount('5.40')->validFrom('2026-09-01')->create();
    TransportFarePrice::factory()->for($this->fare)->amount('5.80')->validFrom('2026-11-01')->create();
});

test('the price in force is the latest one starting on or before the date', function (string $date, ?string $expectedAmount) {
    expect($this->resolver->forDate($this->fare, CarbonImmutable::parse($date))?->amount)->toBe($expectedAmount);
})->with([
    'before the first price' => ['2025-12-31', null],
    'first price' => ['2026-01-01', '5.00'],
    'end of august' => ['2026-08-31', '5.00'],
    'new price in september' => ['2026-09-01', '5.40'],
    'end of september' => ['2026-09-30', '5.40'],
    'end of october' => ['2026-10-31', '5.40'],
    'new price in november' => ['2026-11-01', '5.80'],
]);

test('a new price does not overwrite the previous one', function () {
    expect(TransportFarePrice::where('transport_fare_id', $this->fare->id)->orderBy('valid_from')->pluck('amount')->all())
        ->toBe(['5.00', '5.40', '5.80']);
});

test('a mid month change applies to the date it starts', function () {
    TransportFarePrice::factory()->for($this->fare)->amount('6.00')->validFrom('2026-11-15')->create();

    expect($this->resolver->forDate($this->fare, CarbonImmutable::parse('2026-11-14'))->amount)->toBe('5.80')
        ->and($this->resolver->forDate($this->fare, CarbonImmutable::parse('2026-11-15'))->amount)->toBe('6.00')
        ->and($this->resolver->forDate($this->fare, CarbonImmutable::parse('2026-12-01'))->amount)->toBe('6.00');
});

test('prices of other fares are ignored', function () {
    $otherFare = TransportFare::factory()->create();
    TransportFarePrice::factory()->for($otherFare)->amount('9.99')->validFrom('2026-10-01')->create();

    expect($this->resolver->forDate($this->fare, CarbonImmutable::parse('2026-10-15'))->amount)->toBe('5.40');
});

test('many fares are resolved at once', function () {
    $fareWithoutPrice = TransportFare::factory()->create();
    $futureFare = TransportFare::factory()->create();
    TransportFarePrice::factory()->for($futureFare)->amount('4.55')->validFrom('2027-01-01')->create();

    $prices = $this->resolver->forDateMany(
        TransportFare::query()->orderBy('id')->get(),
        CarbonImmutable::parse('2026-10-01'),
    );

    expect($prices->keys()->all())->toBe([$this->fare->id, $fareWithoutPrice->id, $futureFare->id])
        ->and($prices->get($this->fare->id)->amount)->toBe('5.40')
        ->and($prices->get($fareWithoutPrice->id))->toBeNull()
        ->and($prices->get($futureFare->id))->toBeNull();
});
