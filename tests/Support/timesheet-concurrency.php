<?php

/*
 * Processo filho de TimesheetSnapshots/ConcurrencyTest (F6.2d): executa uma operação real (fechamento ou
 * registro, edição e cancelamento de justificativa) numa conexão própria ao MySQL de testes, para concorrer com
 * outro processo. Recusa executar fora do ambiente `testing` e dos bancos `testing` e
 * `testing_test_N` (modo paralelo), recebido do processo pai em `DB_DATABASE`; a conferência
 * da configuração vem antes de qualquer consulta.
 *
 * Uso: php tests/Support/timesheet-concurrency.php <close|justify|update|cancel> '<json>'
 * `pause_on` (snapshot|justification|query): no primeiro `created` desse modelo (ou na primeira
 * consulta cujo SQL contém `pause_query`), grava `ready_file` e dorme `pause_seconds`, com a
 * transação aberta e os bloqueios mantidos.
 * Saída: uma linha JSON {ok, error, message, started, finished}.
 */

use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\PointJustification;
use App\Models\PointJustificationReason;
use App\Models\TimesheetSnapshot;
use App\Models\User;
use App\Services\BenefitPeriodWorkflow;
use App\Services\PointJustificationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = (string) config('database.default');
$database = (string) config("database.connections.{$connection}.database");

if (! app()->environment('testing') || ! in_array(config("database.connections.{$connection}.driver"), TestCase::TEST_DRIVERS, true) || ! TestCase::isTestDatabase($database)
    || DB::selectOne('select database() as name')->name !== $database) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'refused', 'message' => 'Worker recusado fora do banco testing ou testing_test_N.'])."\n");
    exit(2);
}

[$scenario, $arguments] = [$argv[1] ?? '', json_decode($argv[2] ?? '{}', true)];

$paused = false;
$pause = function () use (&$paused, $arguments): void {
    if ($paused) {
        return;
    }

    $paused = true;
    file_put_contents($arguments['ready_file'], '1');
    sleep((int) $arguments['pause_seconds']);
};

match ($arguments['pause_on'] ?? null) {
    'snapshot' => TimesheetSnapshot::created($pause),
    'justification' => PointJustification::created($pause),
    'query' => DB::listen(function (QueryExecuted $query) use ($pause, $arguments): void {
        if (str_contains($query->sql, $arguments['pause_query'])) {
            $pause();
        }
    }),
    default => null,
};

$started = microtime(true);

try {
    $user = User::query()->findOrFail($arguments['user_id']);
    auth()->setUser($user);

    if ($scenario === 'close') {
        app(BenefitPeriodWorkflow::class)->close(BenefitPeriod::query()->findOrFail($arguments['period_id']));
    } elseif ($scenario === 'justify') {
        app(PointJustificationService::class)->register(Employee::query()->findOrFail($arguments['employee_id']), $arguments['date'], [
            'request_uuid' => (string) Str::uuid(),
            'reason_id' => PointJustificationReason::query()->where('code', 'justified_delay')->sole()->id,
            'treatment' => 'full_allowance',
            'start_time' => $arguments['start'],
            'end_time' => $arguments['end'],
            'ends_next_day' => false,
        ], $user);
    } elseif ($scenario === 'update') {
        $justification = PointJustification::query()->findOrFail($arguments['justification_id']);
        app(PointJustificationService::class)->update($justification, [
            'reason_id' => $justification->reason_id,
            'treatment' => 'full_allowance',
            'start_time' => $arguments['start'],
            'end_time' => $arguments['end'],
            ...(isset($arguments['occurrence_date']) ? ['occurrence_date' => $arguments['occurrence_date']] : []),
        ], (int) $justification->version, $user);
    } elseif ($scenario === 'cancel') {
        $justification = PointJustification::query()->findOrFail($arguments['justification_id']);
        app(PointJustificationService::class)->cancel($justification, 'Cancelamento concorrente', (int) $justification->version, $user);
    } else {
        throw new InvalidArgumentException('Cenário desconhecido: '.$scenario);
    }

    $output = ['ok' => true, 'error' => null, 'message' => null];
} catch (Throwable $exception) {
    $output = ['ok' => false, 'error' => $exception::class, 'message' => $exception->getMessage()];
}

fwrite(STDOUT, json_encode([...$output, 'started' => $started, 'finished' => microtime(true)])."\n");
