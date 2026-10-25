<?php

use App\Livewire\EmployeePointsEdit;
use App\Models\AfdPointDecision;
use App\Models\Employee;
use App\Models\Point;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Proteção dos pontos referenciados por anotação ativa do RH sobre ponto importado
 * (AfdPointDecision): o ponto anotado e o original de uma duplicada não podem ser
 * excluídos nem alterados sem revogar a anotação; a tela mostra o marcador.
 */
beforeEach(function () {
    $this->employee = Employee::factory()->create(['pis' => '12345678901']);
    $this->original = Point::factory()->forEmployee($this->employee)->on('2026-10-02')->manual()->create(['time' => '16:30:00']);
    $this->duplicate = Point::factory()->forEmployee($this->employee)->on('2026-10-02')->create(['time' => '16:30:00']);
});

test('the annotated point and its original cannot be deleted', function (string $point) {
    AfdPointDecision::factory()->duplicateOf($this->original->id)->create(['point_id' => $this->duplicate->id]);

    expect(fn () => $this->{$point}->delete())->toThrow(DomainException::class, Point::ANNOTATED_BY_HR_MESSAGE);
    $this->assertModelExists($this->{$point});
})->with(['annotated' => 'duplicate', 'original' => 'original']);

test('employee, date, time, type, REP and NSR of an annotated point cannot be changed', function (array $change) {
    AfdPointDecision::factory()->create(['point_id' => $this->duplicate->id]);

    expect(fn () => $this->duplicate->forceFill($change)->save())->toThrow(DomainException::class, Point::ANNOTATED_BY_HR_MESSAGE);
})->with([
    'pis' => [['pis' => 19999999999]],
    'date' => [['date' => '2026-10-03']],
    'time' => [['time' => '16:31:00']],
    'type' => [['type' => 'manual']],
    'rep' => [['rep_serial' => '00004000000099999']],
    'nsr' => [['nsr' => 99]],
]);

test('a point is free when the annotation was revoked', function () {
    AfdPointDecision::factory()->create(['point_id' => $this->duplicate->id, 'status' => AfdPointDecision::STATUS_SUPERSEDED]);
    AfdPointDecision::factory()->create(['point_id' => $this->duplicate->id, 'decision' => AfdPointDecision::DECISION_REVOKE]);

    expect($this->duplicate->isAnnotatedByHr())->toBeFalse()
        ->and($this->duplicate->delete())->toBeTrue();
});

test('the points screen shows the marker and refuses deleting the original of a duplicate', function () {
    $this->actingAs(User::factory()->create());
    AfdPointDecision::factory()->duplicateOf($this->original->id)->create(['point_id' => $this->duplicate->id]);

    Livewire::test(EmployeePointsEdit::class, ['employee' => $this->employee->id, 'date' => '2026-10-02'])
        ->assertSee('Duplicada (RH)')
        ->call('removePoint', $this->original->id);

    $this->assertModelExists($this->original);
});

test('the annotation history cannot be edited or deleted', function () {
    $annotation = AfdPointDecision::factory()->create(['point_id' => $this->duplicate->id]);

    expect(fn () => $annotation->update(['justification' => 'outra']))->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $annotation->delete())->toThrow(LogicException::class, 'append-only')
        ->and($annotation->fresh()->update(['status' => AfdPointDecision::STATUS_SUPERSEDED]))->toBeTrue();
});
