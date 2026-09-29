<?php

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the default state creates an active employee', function () {
    $employee = Employee::factory()->create();

    expect($employee->exists)->toBeTrue()
        ->and($employee->name)->not->toBeEmpty()
        ->and($employee->position)->not->toBeEmpty()
        ->and($employee->fresh()->recision_date)->toBeNull();
});

test('the generated pis has eleven digits without a leading zero', function () {
    $employee = Employee::factory()->create();

    expect($employee->pis)->toMatch('/^[1-9]\d{10}$/');
});

test('factory employees are treated as active by the existing active filter', function () {
    Employee::factory()->count(2)->create();
    Employee::factory()->terminated()->create();

    $activeCount = Employee::query()
        ->where(fn ($query) => $query->whereNull('recision_date')->orWhere('recision_date', ''))
        ->count();

    expect($activeCount)->toBe(2);
});

test('the terminated state sets a recision date', function () {
    $employee = Employee::factory()->terminated()->create();

    expect($employee->fresh()->recision_date)->not->toBeNull();
});

test('the terminated state accepts an explicit recision date', function () {
    $employee = Employee::factory()->terminated('2026-08-31')->create();

    expect(substr($employee->fresh()->recision_date, 0, 10))->toBe('2026-08-31');
});

test('many generated employees do not share a pis', function () {
    $employees = Employee::factory()->count(50)->create();

    expect($employees->pluck('pis')->unique())->toHaveCount(50);
});
