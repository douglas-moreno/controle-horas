<?php

use App\Enums\BenefitType;
use App\Models\BenefitCalculation;
use App\Models\BenefitRate;
use App\Models\User;
use App\Services\BenefitRateRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->registrar = new BenefitRateRegistrar;
});

test('vr and vd rates are registered with a two decimal amount on the first day of the month', function (BenefitType $benefitType, string $amount, string $storedAmount) {
    $user = User::factory()->create();

    $rate = $this->registrar->register($benefitType, $amount, CarbonImmutable::parse('2026-09-01'), $user->id);

    expect($rate->fresh()->benefit_type)->toBe($benefitType)
        ->and($rate->fresh()->amount)->toBe($storedAmount)
        ->and($rate->fresh()->created_by)->toBe($user->id)
        ->and(DB::table('benefit_rates')->where('id', $rate->id)->value('valid_from'))->toBe('2026-09-01');
})->with([
    'vr' => [BenefitType::Vr, '27.50', '27.50'],
    'vd' => [BenefitType::Vd, '7.5', '7.50'],
    'whole amount' => [BenefitType::Vr, '30', '30.00'],
]);

test('vt is rejected', function () {
    $this->registrar->register(BenefitType::Vt, '10.00', CarbonImmutable::parse('2026-09-01'));
})->throws(InvalidArgumentException::class, 'Somente VR e VD');

test('a validity that does not start on the first day is rejected', function () {
    $this->registrar->register(BenefitType::Vr, '27.50', CarbonImmutable::parse('2026-09-15'));
})->throws(InvalidArgumentException::class, 'dia 01');

test('invalid amounts are rejected', function (string $amount) {
    $this->registrar->register(BenefitType::Vr, $amount, CarbonImmutable::parse('2026-09-01'));
})->throws(InvalidArgumentException::class)->with(['27.505', 'abc', '-1.00', '']);

test('the database rejects a second rate for the same type and month', function () {
    $this->registrar->register(BenefitType::Vr, '27.50', CarbonImmutable::parse('2026-09-01'));

    expect(fn () => $this->registrar->register(BenefitType::Vr, '30.00', CarbonImmutable::parse('2026-09-01')))
        ->toThrow(QueryException::class);
});

test('a new validity keeps the previous one untouched', function () {
    $september = $this->registrar->register(BenefitType::Vr, '27.50', CarbonImmutable::parse('2026-09-01'));
    $this->registrar->register(BenefitType::Vr, '30.00', CarbonImmutable::parse('2026-11-01'));

    expect($september->fresh()->amount)->toBe('27.50')
        ->and($september->fresh()->valid_from->toDateString())->toBe('2026-09-01')
        ->and(BenefitRate::count())->toBe(2);
});

test('a rate can be deleted only while no calculation used it', function () {
    $unused = BenefitRate::factory()->create();
    $used = BenefitRate::factory()->create();
    BenefitCalculation::factory()->create(['benefit_rate_id' => $used->id]);

    expect($this->registrar->canDelete($unused))->toBeTrue()
        ->and($this->registrar->canDelete($used))->toBeFalse();
});
