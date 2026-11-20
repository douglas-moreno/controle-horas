<?php

use App\Enums\Permission;
use App\Models\Employee;
use App\Models\Point;
use App\Models\PointJustification;
use App\Models\PointJustificationHistory;
use App\Models\PointJustificationReason;
use App\Models\User;
use Database\Seeders\PointJustificationReasonSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
 * Concorrência real: processos PHP separados disputam o mesmo funcionário. Os dados
 * precisam estar gravados (visíveis a outras conexões), por isso este arquivo usa
 * DatabaseTruncation. Cada filho espera o mesmo instante de largada e atrasa a gravação
 * dentro da transação: sem o bloqueio do funcionário, os dois passariam pelas
 * verificações antes de qualquer gravação.
 */
uses(DatabaseTruncation::class);

beforeEach(function () {
    expect(DB::connection()->getDatabaseName())->toBe('testing');

    $this->seed(PointJustificationReasonSeeder::class);

    $this->employee = Employee::factory()->create();
    $this->hr = User::factory()->create();
    $this->hr->grantPermission(Permission::Hr);

    $this->reason = fn (string $code) => PointJustificationReason::query()->where('code', $code)->sole()->id;

    $this->race = function (array $inputs, int $delayMs = 400): array {
        $startAt = microtime(true) + 1.5;

        $processes = array_map(function (array $input) use ($startAt, $delayMs) {
            $process = new Process(
                [PHP_BINARY, base_path('tests/Support/register-justification.php'), json_encode([
                    'employee_id' => $this->employee->id,
                    'user_id' => $this->hr->id,
                    'date' => '2026-09-15',
                    'input' => $input,
                    'start_at' => $startAt,
                    'delay_ms' => $delayMs,
                ])],
                base_path(),
                ['APP_ENV' => 'testing', 'DB_DATABASE' => 'testing'],
                timeout: 60,
            );
            $process->start();

            return $process;
        }, $inputs);

        return array_map(function (Process $process) {
            $process->wait();

            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }, $processes);
    };
});

/**
 * Limpa também depois de cada teste: os arquivos com RefreshDatabase executados em
 * seguida no mesmo processo não truncam as tabelas.
 */
afterEach(function () {
    Schema::withoutForeignKeyConstraints(fn () => collect(Schema::getTableListing(schemaQualified: false))
        ->reject(fn (string $table) => $table === 'migrations')
        ->each(fn (string $table) => DB::table($table)->truncate()));
});

test('simultaneous requests with the same identifier create one justification and one punch', function () {
    $input = [
        'request_uuid' => (string) Str::uuid(),
        'reason_id' => ($this->reason)('forgotten_punch'),
        'treatment' => 'no_allowance',
        'start_time' => '07:30',
        'punch_kind' => 'entry',
    ];

    $results = ($this->race)([$input, $input, $input]);

    expect(collect($results)->pluck('status')->sort()->values()->all())->toBe(['created', 'replayed', 'replayed'])
        ->and(collect($results)->pluck('id')->unique()->count())->toBe(1)
        ->and(PointJustification::query()->count())->toBe(1)
        ->and(Point::query()->where('type', 'manual')->count())->toBe(1)
        ->and(PointJustificationHistory::query()->count())->toBe(2);
});

test('simultaneous overlapping requests record only one of them', function () {
    $interval = fn (string $start, string $end) => [
        'request_uuid' => (string) Str::uuid(),
        'reason_id' => ($this->reason)('justified_delay'),
        'treatment' => 'full_allowance',
        'start_time' => $start,
        'end_time' => $end,
    ];

    $results = ($this->race)([$interval('08:00', '10:00'), $interval('09:00', '11:00'), $interval('09:30', '09:45')]);
    $statuses = collect($results)->pluck('status')->sort()->values()->all();

    expect($statuses)->toBe(['created', 'validation', 'validation'])
        ->and(collect($results)->where('status', 'validation')->every(fn (array $result) => array_keys($result['errors']) === ['period']))->toBeTrue()
        ->and(PointJustification::query()->count())->toBe(1);
});

test('simultaneous forgotten punches at the same minute create a single punch', function () {
    $punch = fn () => [
        'request_uuid' => (string) Str::uuid(),
        'reason_id' => ($this->reason)('forgotten_punch'),
        'treatment' => 'no_allowance',
        'start_time' => '07:30',
        'punch_kind' => 'entry',
    ];

    $results = ($this->race)([$punch(), $punch()]);

    expect(collect($results)->pluck('status')->sort()->values()->all())->toBe(['created', 'validation'])
        ->and(Point::query()->count())->toBe(1)
        ->and(PointJustification::query()->count())->toBe(1);
});
