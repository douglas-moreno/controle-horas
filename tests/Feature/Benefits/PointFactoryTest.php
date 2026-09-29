<?php

use App\Models\Employee;
use App\Models\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the default state creates a valid imported punch', function () {
    $point = Point::factory()->create();

    expect($point->exists)->toBeTrue()
        ->and((string) $point->pis)->toMatch('/^[1-9]\d{10}$/')
        ->and($point->date)->not->toBeNull()
        ->and($point->getRawOriginal('time'))->toMatch('/^\d{2}:\d{2}:\d{2}$/')
        ->and($point->type)->toBe('importado');
});

test('the manual state marks the punch as manual', function () {
    $point = Point::factory()->manual()->create();

    expect($point->fresh()->type)->toBe('manual');
});

test('the on state sets the given date', function () {
    $point = Point::factory()->on('2026-09-15')->create();

    expect($point->fresh()->date->toDateString())->toBe('2026-09-15');
});

test('the for employee state links the punch through the employee pis', function () {
    $employee = Employee::factory()->create();

    $point = Point::factory()->forEmployee($employee)->create();

    expect((string) $point->fresh()->pis)->toBe($employee->pis)
        ->and($point->fresh()->employee->is($employee))->toBeTrue()
        ->and($employee->points()->count())->toBe(1);
});

test('many punches can be created for the same employee', function () {
    $employee = Employee::factory()->create();
    Employee::factory()->create();

    Point::factory()->forEmployee($employee)->on('2026-09-15')->count(4)->create();
    Point::factory()->forEmployee($employee)->manual()->on('2026-09-16')->create();

    expect($employee->points()->count())->toBe(5)
        ->and($employee->points()->where('type', 'manual')->count())->toBe(1);
});
