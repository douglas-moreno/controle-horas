<?php

use App\Models\AfdHistoricalNsr;
use App\Models\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Proteção da batida manual mantida por decisão do RH (pendência histórica do AFD em
 * `manual_retained`): sem ela, a cobertura de NSR fica inconsistente e bloqueia as
 * importações seguintes.
 */
beforeEach(function () {
    $this->manual = Point::factory()->manual()->create(['pis' => 12345678901, 'date' => '2026-09-04', 'time' => '08:05:00']);
});

test('a kept manual point cannot be deleted', function () {
    AfdHistoricalNsr::factory()->manualRetained($this->manual->id)->create();

    expect(fn () => $this->manual->delete())->toThrow(DomainException::class, Point::RETAINED_BY_HR_MESSAGE);
    $this->assertModelExists($this->manual);
});

test('employee, date, time and type of a kept manual point cannot be changed', function (array $change) {
    AfdHistoricalNsr::factory()->manualRetained($this->manual->id)->create();

    expect(fn () => $this->manual->update($change))->toThrow(DomainException::class, Point::RETAINED_BY_HR_MESSAGE)
        ->and($this->manual->fresh()->only(['pis', 'type']))->toBe(['pis' => 12345678901, 'type' => 'manual']);
})->with([
    'pis' => [['pis' => 19999999999]],
    'date' => [['date' => '2026-09-05']],
    'time' => [['time' => '09:00:00']],
    'type' => [['type' => 'importado']],
]);

test('touching the timestamps of a kept manual point is still allowed', function () {
    AfdHistoricalNsr::factory()->manualRetained($this->manual->id)->create();

    expect($this->manual->touch())->toBeTrue();
});

test('a manual point is free when no decision keeps it', function (Closure $entry) {
    $entry($this->manual);

    expect($this->manual->isRetainedByHrDecision())->toBeFalse()
        ->and($this->manual->delete())->toBeTrue();
})->with([
    'no pending' => [fn (Point $manual) => null],
    'pending related by the audit' => [fn (Point $manual) => AfdHistoricalNsr::factory()->create(['related_manual_point_id' => $manual->id])],
    'discarded pending' => [fn (Point $manual) => AfdHistoricalNsr::factory()->discarded()->create(['related_manual_point_id' => $manual->id])],
]);

test('imported points are not affected by the protection', function () {
    $imported = Point::factory()->create(['pis' => 12345678901, 'date' => '2026-09-04', 'time' => '08:06:00']);
    AfdHistoricalNsr::factory()->manualRetained($imported->id)->create();

    expect($imported->isRetainedByHrDecision())->toBeFalse()
        ->and($imported->delete())->toBeTrue();
});
