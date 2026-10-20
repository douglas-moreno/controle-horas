<?php

use App\Livewire\EmployeePointsEdit;
use App\Models\Employee;
use App\Models\Point;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->employee = Employee::factory()->create(['name' => 'Ana Souza']);
    $this->manualPoint = Point::factory()->forEmployee($this->employee)->on('2026-09-15')->manual()->create(['time' => '12:00:00']);
    $this->importedPoint = Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => '08:00:00']);

    $this->screen = fn () => Livewire::test(EmployeePointsEdit::class, ['employee' => $this->employee->id, 'date' => '2026-09-15']);
});

test('a manual punch of the employee and date is removed', function () {
    ($this->screen)()->call('removePoint', $this->manualPoint->id);

    $this->assertModelMissing($this->manualPoint);
    $this->assertModelExists($this->importedPoint);
});

test('an imported punch cannot be removed by a direct call', function () {
    ($this->screen)()->call('removePoint', $this->importedPoint->id);

    $this->assertModelExists($this->importedPoint);
    expect($this->importedPoint->fresh()->type)->toBe('importado');
});

test('a punch outside the employee and date of the screen cannot be removed', function () {
    $otherEmployee = Employee::factory()->create();
    $otherEmployeePoint = Point::factory()->forEmployee($otherEmployee)->on('2026-09-15')->manual()->create();
    $otherDatePoint = Point::factory()->forEmployee($this->employee)->on('2026-09-16')->manual()->create();

    ($this->screen)()
        ->call('removePoint', $otherEmployeePoint->id)
        ->call('removePoint', $otherDatePoint->id)
        ->call('removePoint', 999999);

    $this->assertModelExists($otherEmployeePoint);
    $this->assertModelExists($otherDatePoint);
    expect(Point::count())->toBe(4);
});

test('a list of ids cannot be used to remove punches', function () {
    expect(fn () => ($this->screen)()->call('removePoint', [$this->manualPoint->id, $this->importedPoint->id]))->toThrow(Exception::class);

    $this->assertModelExists($this->manualPoint);
    $this->assertModelExists($this->importedPoint);
});

test('the employee and the date of the screen cannot be changed by the client', function (string $property, mixed $value) {
    expect(fn () => ($this->screen)()->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'date' => ['date', '2026-09-16'],
    'employee' => ['employee', 1],
]);

test('the remove button is shown only for manual punches', function () {
    ($this->screen)()
        ->assertSeeHtml('removePoint('.$this->manualPoint->id.')')
        ->assertDontSeeHtml('removePoint('.$this->importedPoint->id.')');
});

test('a manual punch can still be added', function () {
    ($this->screen)()
        ->set('newPointTime', '17:30')
        ->call('saveNewPoint')
        ->assertHasNoErrors();

    expect(Point::query()->where('pis', $this->employee->pis)->whereDate('date', '2026-09-15')->where('type', 'manual')->where('time', '17:30:00')->exists())->toBeTrue();
});
