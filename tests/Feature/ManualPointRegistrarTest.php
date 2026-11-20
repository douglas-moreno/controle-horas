<?php

use App\Models\Employee;
use App\Models\Point;
use App\Services\ManualPointRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->employee = Employee::factory()->create();
    $this->registrar = app(ManualPointRegistrar::class);
});

test('registers a manual punch for the employee, date and minute', function () {
    $point = $this->registrar->register($this->employee, '2026-09-15', '17:30');

    expect($point->exists)->toBeTrue()
        ->and($point->type)->toBe('manual')
        ->and((string) $point->pis)->toBe((string) $this->employee->pis)
        ->and(DB::table('points')->where('id', $point->id)->value('time'))->toBe('17:30:00')
        ->and(DB::table('points')->where('id', $point->id)->value('date'))->toBe('2026-09-15');
});

test('seconds are discarded as in the screen', function () {
    $point = $this->registrar->register($this->employee, '2026-09-15', '17:30:45');

    expect(DB::table('points')->where('id', $point->id)->value('time'))->toBe('17:30:00');
});

test('refuses a punch at the same minute of an existing punch of any type', function (string $type, string $existing) {
    Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['type' => $type, 'time' => $existing]);

    expect(fn () => $this->registrar->register($this->employee, '2026-09-15', '08:00'))
        ->toThrow(DomainException::class, 'Já existe uma batida deste funcionário nesta data às 08:00.');

    expect(Point::query()->count())->toBe(1);
})->with([
    'imported' => ['importado', '08:00:00'],
    'imported with seconds' => ['importado', '08:00:59'],
    'manual' => ['manual', '08:00:00'],
]);

test('accepts the same minute on another date, for another employee or in another minute', function () {
    Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => '08:00:00']);
    $other = Employee::factory()->create();

    $this->registrar->register($this->employee, '2026-09-16', '08:00');
    $this->registrar->register($other, '2026-09-15', '08:00');
    $this->registrar->register($this->employee, '2026-09-15', '08:01');

    expect(Point::query()->count())->toBe(4);
});

test('refuses invalid dates and times without writing', function (string $date, string $time, string $message) {
    expect(fn () => $this->registrar->register($this->employee, $date, $time))->toThrow(DomainException::class, $message);

    expect(Point::query()->count())->toBe(0);
})->with([
    'impossible date' => ['2026-02-30', '08:00', 'Data da batida inválida.'],
    'date format' => ['15/09/2026', '08:00', 'Data da batida inválida.'],
    'hour out of range' => ['2026-09-15', '24:00', 'Horário da batida inválido.'],
    'time format' => ['2026-09-15', '8h', 'Horário da batida inválido.'],
]);

test('refuses an employee that no longer exists or has no PIS', function () {
    $removed = Employee::factory()->create();
    DB::table('employees')->where('id', $removed->id)->delete();

    expect(fn () => $this->registrar->register($removed, '2026-09-15', '08:00'))->toThrow(DomainException::class, 'Funcionário não encontrado.');

    $withoutPis = Employee::factory()->create(['pis' => '']);

    expect(fn () => $this->registrar->register($withoutPis, '2026-09-15', '08:00'))->toThrow(DomainException::class, 'O funcionário não possui PIS cadastrado.');

    expect(Point::query()->count())->toBe(0);
});

test('writes inside a transaction and rolls back when the insert fails', function () {
    $levels = [];
    Point::creating(function () use (&$levels) {
        $levels[] = DB::transactionLevel();

        throw new RuntimeException('falha simulada');
    });

    $baseline = DB::transactionLevel();

    expect(fn () => $this->registrar->register($this->employee, '2026-09-15', '08:00'))->toThrow(RuntimeException::class);

    expect($levels)->toBe([$baseline + 1])
        ->and(DB::transactionLevel())->toBe($baseline)
        ->and(Point::query()->count())->toBe(0);
});

test('existing imported punches are never changed', function () {
    $imported = Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => '07:30:00', 'rep_serial' => '00004004330000001', 'nsr' => 10]);
    $before = DB::table('points')->where('id', $imported->id)->first();

    $this->registrar->register($this->employee, '2026-09-15', '12:00');
    expect(fn () => $this->registrar->register($this->employee, '2026-09-15', '07:30'))->toThrow(DomainException::class);

    expect(DB::table('points')->where('id', $imported->id)->first())->toEqual($before);
});
