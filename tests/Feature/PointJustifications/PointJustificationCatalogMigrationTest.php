<?php

use App\Enums\JustificationTimeMode;
use App\Enums\JustificationTreatment;
use App\Models\PointJustificationReason;
use Database\Seeders\PointJustificationReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_11_20_001542_enable_manual_point_for_clock_failure_and_external_work_reasons.php');
});

test('enables the manual punch only on the three approved reasons and keeps the HR settings', function () {
    $this->seed(PointJustificationReasonSeeder::class);
    PointJustificationReason::query()->whereIn('code', ['clock_failure', 'external_work_without_punch'])->update(['creates_manual_point' => false]);
    PointJustificationReason::query()->where('code', 'clock_failure')->update([
        'name' => 'Falha no REP',
        'description' => 'Configurado pelo RH',
        'time_mode' => JustificationTimeMode::Interval->value,
        'requires_document' => true,
        'default_treatment' => JustificationTreatment::FullAllowance->value,
        'allowed_treatments' => json_encode(['full_allowance']),
        'active' => false,
        'sort_order' => 999,
    ]);
    $before = PointJustificationReason::query()->orderBy('id')->get()->keyBy('code')->map->getAttributes();

    $this->migration->up();
    $this->migration->up();

    $after = PointJustificationReason::query()->orderBy('id')->get()->keyBy('code')->map->getAttributes();

    expect(PointJustificationReason::query()->where('creates_manual_point', true)->orderBy('id')->pluck('code')->all())
        ->toBe(['forgotten_punch', 'clock_failure', 'external_work_without_punch']);

    foreach ($before as $code => $attributes) {
        $changedColumns = array_keys(array_diff_assoc(
            collect($after[$code])->except('updated_at')->map(fn ($value) => (string) $value)->all(),
            collect($attributes)->except('updated_at')->map(fn ($value) => (string) $value)->all(),
        ));

        expect($changedColumns)->toBe(in_array($code, ['clock_failure', 'external_work_without_punch'], true) ? ['creates_manual_point'] : []);
    }
});

test('reasons already enabled and other reasons are left untouched', function () {
    $this->seed(PointJustificationReasonSeeder::class);
    $before = PointJustificationReason::query()->orderBy('id')->get()->map->getAttributes()->all();

    $this->migration->up();

    expect(PointJustificationReason::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
});

test('an empty catalog stays empty', function () {
    $this->migration->up();

    expect(PointJustificationReason::query()->count())->toBe(0);
});

test('rolling back restores the F1 configuration', function () {
    $this->seed(PointJustificationReasonSeeder::class);

    $this->migration->down();

    expect(PointJustificationReason::query()->where('creates_manual_point', true)->pluck('code')->all())->toBe(['forgotten_punch']);
});
