<?php

use App\Enums\Permission;
use App\Models\Employee;
use App\Models\Point;
use App\Models\PointJustification;
use App\Models\PointJustificationHistory;
use App\Models\PointJustificationReason;
use App\Models\User;
use App\Services\PointJustificationService;
use Database\Seeders\PointJustificationReasonSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/*
 * Concorrência real: processos PHP separados disputam o mesmo funcionário. Os dados
 * precisam estar gravados (visíveis a outras conexões), por isso este arquivo usa
 * DatabaseTruncation. Cada filho espera o mesmo instante de largada e atrasa a gravação
 * dentro da transação: sem o bloqueio do funcionário, os dois passariam pelas
 * verificações antes de qualquer gravação.
 */
uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->database = DB::connection()->getDatabaseName();
    expect(TestCase::isTestDatabase($this->database))->toBeTrue("Banco de teste não autorizado: {$this->database}");

    $this->seed(PointJustificationReasonSeeder::class);

    $this->employee = Employee::factory()->create();
    $this->hr = User::factory()->create();
    $this->hr->grantPermission(Permission::Hr);

    $this->reason = fn (string $code) => PointJustificationReason::query()->where('code', $code)->sole()->id;

    $this->race = function (array $inputs, int $delayMs = 400, array $operation = []): array {
        $startAt = microtime(true) + 1.5;

        $processes = array_map(function (array $input) use ($startAt, $delayMs, $operation) {
            $process = new Process(
                [PHP_BINARY, base_path('tests/Support/register-justification.php'), json_encode([
                    'employee_id' => $this->employee->id,
                    'user_id' => $this->hr->id,
                    'date' => '2026-09-15',
                    'input' => $input,
                    'start_at' => $startAt,
                    'delay_ms' => $delayMs,
                    ...$operation,
                    ...($input['_operation'] ?? []),
                ])],
                base_path(),
                ['APP_ENV' => 'testing', 'DB_DATABASE' => $this->database],
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
    expect(TestCase::isTestDatabase(DB::connection()->getDatabaseName()))->toBeTrue();

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

test('simultaneous editions of the same version keep only one of them', function () {
    $justification = app(PointJustificationService::class)->register($this->employee, '2026-09-15', [
        'request_uuid' => (string) Str::uuid(),
        'reason_id' => ($this->reason)('forgotten_punch'),
        'treatment' => 'no_allowance',
        'start_time' => '07:30',
        'punch_kind' => 'entry',
    ], $this->hr)['justification'];

    $edit = fn (string $time) => ['reason_id' => ($this->reason)('forgotten_punch'), 'treatment' => 'no_allowance', 'start_time' => $time, 'punch_kind' => 'entry'];

    $results = ($this->race)([$edit('07:40'), $edit('07:50')], 400, ['operation' => 'update', 'justification_id' => $justification->id, 'version' => 1]);

    $justification->refresh();
    $winner = collect($results)->firstWhere('status', 'updated');

    expect(collect($results)->pluck('status')->sort()->values()->all())->toBe(['updated', 'validation'])
        ->and(collect($results)->firstWhere('status', 'validation')['errors'])->toBe(['version' => [PointJustificationService::VERSION_CONFLICT_MESSAGE]])
        ->and($winner['id'])->toBe($justification->id)
        ->and($justification->version)->toBe(2)
        ->and(Point::query()->count())->toBe(1)
        ->and(substr((string) Point::query()->sole()->getRawOriginal('time'), 0, 5))->toBe(substr($justification->start_time, 0, 5))
        ->and(PointJustificationHistory::query()->where('action', 'updated')->count())->toBe(1)
        ->and(PointJustificationHistory::query()->where('action', 'manual_point_updated')->count())->toBe(1);
});

test('simultaneous cancellations cancel once and remove the punch once', function () {
    $justification = app(PointJustificationService::class)->register($this->employee, '2026-09-15', [
        'request_uuid' => (string) Str::uuid(),
        'reason_id' => ($this->reason)('forgotten_punch'),
        'treatment' => 'no_allowance',
        'start_time' => '07:30',
        'punch_kind' => 'entry',
    ], $this->hr)['justification'];

    $results = ($this->race)([[], [], []], 400, ['operation' => 'cancel', 'justification_id' => $justification->id, 'version' => 1, 'reason' => 'Engano']);

    expect(collect($results)->pluck('status')->sort()->values()->all())->toBe(['canceled', 'validation', 'validation'])
        ->and(collect($results)->where('status', 'validation')->every(fn (array $result) => $result['errors'] === ['form' => ['Esta justificativa já foi cancelada.']]))->toBeTrue()
        ->and($justification->fresh()->isCanceled())->toBeTrue()
        ->and(Point::query()->count())->toBe(0)
        ->and(PointJustificationHistory::query()->where('action', 'canceled')->count())->toBe(1)
        ->and(PointJustificationHistory::query()->where('action', 'manual_point_removed')->count())->toBe(1);
});

test('an edition racing a cancellation never leaves both applied', function () {
    $justification = app(PointJustificationService::class)->register($this->employee, '2026-09-15', [
        'request_uuid' => (string) Str::uuid(),
        'reason_id' => ($this->reason)('justified_delay'),
        'treatment' => 'full_allowance',
        'start_time' => '07:30',
        'end_time' => '08:00',
    ], $this->hr)['justification'];

    $results = ($this->race)([
        ['reason_id' => ($this->reason)('justified_delay'), 'treatment' => 'full_allowance', 'start_time' => '07:30', 'end_time' => '09:00', '_operation' => ['operation' => 'update']],
        ['_operation' => ['operation' => 'cancel', 'reason' => 'Engano']],
    ], 400, ['justification_id' => $justification->id, 'version' => 1]);

    $statuses = collect($results)->pluck('status')->sort()->values()->all();
    $justification->refresh();

    expect($statuses)->toBeIn([['updated', 'validation'], ['canceled', 'validation']])
        ->and($justification->version)->toBe(2)
        ->and(PointJustificationHistory::query()->whereIn('action', ['updated', 'canceled'])->count())->toBe(1);
});
