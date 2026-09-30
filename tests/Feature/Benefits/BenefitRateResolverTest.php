<?php

use App\Enums\BenefitType;
use App\Models\BenefitRate;
use App\Services\BenefitRateResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->resolver = new BenefitRateResolver;

    BenefitRate::factory()->vr('27.50')->validFrom('2026-09-01')->create();
    BenefitRate::factory()->vr('30.00')->validFrom('2026-11-01')->create();
});

test('the rate in force is the latest one starting on or before the date', function (string $date, ?string $expectedAmount) {
    $rate = $this->resolver->forDate(BenefitType::Vr, CarbonImmutable::parse($date));

    expect($rate?->amount)->toBe($expectedAmount);
})->with([
    'before the first validity' => ['2026-08-31', null],
    'first day of the validity' => ['2026-09-01', '27.50'],
    'last day of september' => ['2026-09-30', '27.50'],
    'october keeps the previous value' => ['2026-10-01', '27.50'],
    'first day of the new validity' => ['2026-11-01', '30.00'],
    'far in the future' => ['2030-01-01', '30.00'],
]);

test('vr and vd are resolved independently', function () {
    BenefitRate::factory()->vd('7.50')->validFrom('2026-10-01')->create();

    expect($this->resolver->forDate(BenefitType::Vd, CarbonImmutable::parse('2026-09-30')))->toBeNull()
        ->and($this->resolver->forDate(BenefitType::Vd, CarbonImmutable::parse('2026-10-01'))->amount)->toBe('7.50')
        ->and($this->resolver->forDate(BenefitType::Vr, CarbonImmutable::parse('2026-10-01'))->amount)->toBe('27.50');
});

test('the time of day of the reference does not matter', function () {
    expect($this->resolver->forDate(BenefitType::Vr, CarbonImmutable::parse('2026-11-01 23:59:59'))->amount)->toBe('30.00')
        ->and($this->resolver->forDate(BenefitType::Vr, CarbonImmutable::parse('2026-10-31 23:59:59'))->amount)->toBe('27.50');
});

test('the validity is defined by valid from and not by creation order', function () {
    BenefitRate::factory()->vr('25.00')->validFrom('2026-01-01')->create();

    expect($this->resolver->forDate(BenefitType::Vr, CarbonImmutable::parse('2026-10-15'))->amount)->toBe('27.50');
});

test('vt has no global rate', function () {
    $this->resolver->forDate(BenefitType::Vt, CarbonImmutable::parse('2026-10-01'));
})->throws(InvalidArgumentException::class);
