<?php

use App\Livewire\HolidayCreate;
use App\Livewire\HolidayEdit;
use App\Livewire\HolidayIndex;
use App\Models\Holiday;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('holiday index screen can be rendered', function () {
    Holiday::create(['date' => '2026-07-09', 'description' => 'Revolução Constitucionalista']);

    $this->get(route('holidays.index'))
        ->assertStatus(200)
        ->assertSee('Revolução Constitucionalista');
});

test('hr can register a holiday', function () {
    Livewire::test(HolidayCreate::class)
        ->set('date', '2026-07-09')
        ->set('description', 'Revolução Constitucionalista')
        ->call('createHoliday')
        ->assertHasNoErrors()
        ->assertRedirect(route('holidays.index'));

    expect(Holiday::where('date', '2026-07-09')->exists())->toBeTrue();
});

test('holiday date is normalized before being stored', function () {
    Livewire::test(HolidayCreate::class)
        ->set('date', '2026-07-09T00:00:00-03:00')
        ->set('description', 'Revolução Constitucionalista')
        ->call('createHoliday')
        ->assertHasNoErrors();

    expect(Holiday::first()->date->format('Y-m-d'))->toBe('2026-07-09');
});

test('duplicate holiday dates are rejected', function () {
    Holiday::create(['date' => '2026-07-09', 'description' => 'Revolução Constitucionalista']);

    Livewire::test(HolidayCreate::class)
        ->set('date', '2026-07-09')
        ->set('description', 'Outro feriado')
        ->call('createHoliday')
        ->assertHasErrors(['date' => 'unique']);

    expect(Holiday::count())->toBe(1);
});

test('holiday date and description can be updated', function () {
    $holiday = Holiday::create(['date' => '2026-07-09', 'description' => 'Revolução Constitucionalista']);

    Livewire::test(HolidayEdit::class, ['holiday' => $holiday->id])
        ->set('date', '2026-07-10')
        ->set('description', 'Feriado Municipal')
        ->call('updateHoliday')
        ->assertHasNoErrors();

    expect($holiday->fresh()->date->format('Y-m-d'))->toBe('2026-07-10')
        ->and($holiday->fresh()->description)->toBe('Feriado Municipal');
});

test('a holiday keeps its own date while being updated', function () {
    $holiday = Holiday::create(['date' => '2026-07-09', 'description' => 'Revolução Constitucionalista']);

    Livewire::test(HolidayEdit::class, ['holiday' => $holiday->id])
        ->set('description', 'Revolução Constitucionalista de 1932')
        ->call('updateHoliday')
        ->assertHasNoErrors();
});

test('hr can delete a holiday', function () {
    $holiday = Holiday::create(['date' => '2026-07-09', 'description' => 'Revolução Constitucionalista']);

    Livewire::test(HolidayIndex::class)
        ->call('destroy', $holiday->id);

    expect(Holiday::count())->toBe(0);
});

test('holiday lookup cache is refreshed when holidays change', function () {
    expect(Holiday::isHoliday('2026-07-09'))->toBeFalse();

    $holiday = Holiday::create(['date' => '2026-07-09', 'description' => 'Revolução Constitucionalista']);

    expect(Holiday::isHoliday('2026-07-09'))->toBeTrue()
        ->and(Holiday::descriptionFor('2026-07-09'))->toBe('Revolução Constitucionalista');

    $holiday->delete();

    expect(Holiday::isHoliday('2026-07-09'))->toBeFalse();
});

test('holiday lookup ignores empty and invalid dates', function () {
    expect(Holiday::isHoliday(null))->toBeFalse()
        ->and(Holiday::isHoliday(''))->toBeFalse()
        ->and(Holiday::isHoliday('data-invalida'))->toBeFalse();
});
