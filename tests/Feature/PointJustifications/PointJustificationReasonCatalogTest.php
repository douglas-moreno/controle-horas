<?php

use App\Enums\JustificationCategory;
use App\Enums\JustificationTimeMode;
use App\Enums\JustificationTreatment;
use App\Models\PointJustificationReason;
use Database\Seeders\PointJustificationReasonSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('seeds the 25 reasons of the initial catalog grouped by category', function () {
    $this->seed(PointJustificationReasonSeeder::class);

    $reasons = PointJustificationReason::query()->orderBy('sort_order')->get();

    expect($reasons)->toHaveCount(25)
        ->and($reasons->pluck('code')->all())->toBe(array_keys(PointJustificationReasonSeeder::REASONS))
        ->and($reasons->countBy(fn ($reason) => $reason->category->value)->all())->toBe([
            JustificationCategory::Absence->value => 9,
            JustificationCategory::DelayAndLeave->value => 5,
            JustificationCategory::Punch->value => 6,
            JustificationCategory::WorkdayAndOvertime->value => 5,
        ])
        ->and($reasons->every(fn ($reason) => $reason->active && ! $reason->requires_document && $reason->allowed_treatments === null))->toBeTrue();
});

test('the forgotten punch, the clock failure and the external work create a manual punch', function () {
    $this->seed(PointJustificationReasonSeeder::class);

    $forgotten = PointJustificationReason::query()->where('code', 'forgotten_punch')->sole();

    expect($forgotten->name)->toBe('Esquecimento de marcação')
        ->and($forgotten->time_mode)->toBe(JustificationTimeMode::SinglePunch)
        ->and(PointJustificationReason::query()->where('creates_manual_point', true)->orderBy('sort_order')->pluck('code')->all())
        ->toBe(['forgotten_punch', 'clock_failure', 'external_work_without_punch']);
});

test('the seeder is idempotent and keeps the changes made by HR', function () {
    $this->seed(PointJustificationReasonSeeder::class);

    PointJustificationReason::query()->where('code', 'blood_donation')->update(['name' => 'Doação voluntária de sangue', 'requires_document' => true, 'active' => false]);
    PointJustificationReason::query()->where('code', 'marriage')->delete();

    $this->seed(PointJustificationReasonSeeder::class);

    $blood = PointJustificationReason::query()->where('code', 'blood_donation')->sole();

    expect(PointJustificationReason::query()->count())->toBe(25)
        ->and($blood->name)->toBe('Doação voluntária de sangue')
        ->and($blood->requires_document)->toBeTrue()
        ->and($blood->active)->toBeFalse();
});

test('the reason code is unique', function () {
    PointJustificationReason::factory()->create(['code' => 'forgotten_punch']);

    expect(fn () => PointJustificationReason::factory()->create(['code' => 'forgotten_punch']))->toThrow(QueryException::class);
});

test('the active scope lists only active reasons', function () {
    $active = PointJustificationReason::factory()->create();
    PointJustificationReason::factory()->inactive()->create();

    expect(PointJustificationReason::query()->active()->pluck('id')->all())->toBe([$active->id]);
});

test('without a configured list every supported treatment is allowed', function () {
    $reason = PointJustificationReason::factory()->create(['allowed_treatments' => null]);

    expect($reason->treatments())->toBe([
        JustificationTreatment::FullAllowance,
        JustificationTreatment::PartialAllowance,
        JustificationTreatment::NoAllowance,
    ]);
});

test('a configured list restricts the treatments and never enables the hour bank', function () {
    $reason = PointJustificationReason::factory()->create([
        'allowed_treatments' => [JustificationTreatment::FullAllowance->value, JustificationTreatment::HourBank->value, 'desconhecido'],
    ]);

    expect($reason->treatments())->toBe([JustificationTreatment::FullAllowance])
        ->and($reason->allowsTreatment(JustificationTreatment::FullAllowance))->toBeTrue()
        ->and($reason->allowsTreatment(JustificationTreatment::HourBank))->toBeFalse()
        ->and($reason->allowsTreatment(JustificationTreatment::NoAllowance))->toBeFalse();
});

test('only partial allowance requires the allowed minutes', function () {
    expect(array_values(array_filter(JustificationTreatment::cases(), fn ($treatment) => $treatment->requiresAllowedMinutes())))
        ->toBe([JustificationTreatment::PartialAllowance]);
});
