<?php

use App\Enums\BenefitType;
use App\Models\BenefitRate;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\Holiday;
use App\Models\TransportFare;
use App\Models\TransportFarePrice;
use Database\Seeders\BenefitCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('seeds the 20 fares and the VR/VD rates valid from 2026-09-01', function () {
    $this->seed(BenefitCatalogSeeder::class);

    $prices = TransportFarePrice::query()->with('transportFare')->get();

    expect(TransportFare::query()->count())->toBe(20)
        ->and($prices)->toHaveCount(20)
        ->and($prices->mapWithKeys(fn (TransportFarePrice $price) => [$price->transportFare->name => $price->amount])->all())
        ->toEqual(BenefitCatalogSeeder::FARES)
        ->and($prices->every(fn (TransportFarePrice $price) => $price->valid_from->toDateString() === '2026-09-01'))->toBeTrue()
        ->and(BenefitRate::query()->where('benefit_type', BenefitType::Vr)->sole()->amount)->toBe('27.50')
        ->and(BenefitRate::query()->where('benefit_type', BenefitType::Vd)->sole()->amount)->toBe('7.50');
});

test('gives distinct names to the fares repeated in the spreadsheet', function () {
    expect(BenefitCatalogSeeder::FARES)
        ->toHaveKeys(['CMT BOM 1', 'CMT BOM 2', 'INTEGRAÇÃO 1,10', 'INTEGRAÇÃO 1,40'])
        ->not->toHaveKeys(['CMT BOM', 'INTEGRAÇÃO']);
});

test('is idempotent and keeps existing records untouched', function () {
    $fare = TransportFare::factory()->create(['name' => 'CPTM', 'operator' => 'Operadora']);
    TransportFarePrice::factory()->for($fare)->amount('5.00')->validFrom('2026-09-01')->create();
    BenefitRate::factory()->vr('30.00')->validFrom('2026-09-01')->create();

    $this->seed(BenefitCatalogSeeder::class);
    $this->seed(BenefitCatalogSeeder::class);

    expect(TransportFare::query()->count())->toBe(20)
        ->and(TransportFarePrice::query()->count())->toBe(20)
        ->and(BenefitRate::query()->count())->toBe(2)
        ->and($fare->fresh()->operator)->toBe('Operadora')
        ->and($fare->prices()->sole()->amount)->toBe('5.00')
        ->and(BenefitRate::query()->where('benefit_type', BenefitType::Vr)->sole()->amount)->toBe('30.00');
});

test('creates only catalog data', function () {
    $this->seed(BenefitCatalogSeeder::class);

    expect(Employee::query()->count())->toBe(0)
        ->and(EmployeeBenefit::query()->count())->toBe(0)
        ->and(Holiday::query()->count())->toBe(0);
});
