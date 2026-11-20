<?php

/**
 * Processo filho dos testes de concorrência das justificativas (PointJustificationConcurrencyTest).
 *
 * Uso: php tests/Support/register-justification.php '<json>'
 * JSON: employee_id, user_id, date, input, start_at (microtime de largada), delay_ms (atraso
 * artificial dentro da transação, logo antes de gravar a justificativa) e, opcionais,
 * operation (register | update | cancel), justification_id, version e reason (cancelamento).
 *
 * Recusa rodar fora do banco isolado `testing`. Imprime uma linha JSON com o resultado:
 * created | replayed | updated | unchanged | canceled | validation (com errors) | error (com message).
 */

use App\Models\Employee;
use App\Models\PointJustification;
use App\Models\User;
use App\Services\PointJustificationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'mysql' || DB::selectOne('select database() as name')->name !== 'testing') {
    fwrite(STDERR, "Banco diferente de testing: abortado.\n");
    exit(2);
}

$args = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

PointJustification::creating(fn () => usleep(((int) $args['delay_ms']) * 1000));
PointJustification::updating(fn () => usleep(((int) $args['delay_ms']) * 1000));

$employee = Employee::query()->findOrFail($args['employee_id']);
$user = User::query()->findOrFail($args['user_id']);

while (microtime(true) < (float) $args['start_at']) {
    usleep(1000);
}

$service = app(PointJustificationService::class);

try {
    switch ($args['operation'] ?? 'register') {
        case 'update':
            $result = $service->update(PointJustification::query()->findOrFail($args['justification_id']), $args['input'], (int) $args['version'], $user);
            echo json_encode(['status' => $result['changed'] ? 'updated' : 'unchanged', 'id' => $result['justification']->id]);
            break;
        case 'cancel':
            $canceled = $service->cancel(PointJustification::query()->findOrFail($args['justification_id']), (string) $args['reason'], (int) $args['version'], $user);
            echo json_encode(['status' => 'canceled', 'id' => $canceled->id]);
            break;
        default:
            $result = $service->register($employee, $args['date'], $args['input'], $user);
            echo json_encode(['status' => $result['created'] ? 'created' : 'replayed', 'id' => $result['justification']->id]);
    }
} catch (ValidationException $exception) {
    echo json_encode(['status' => 'validation', 'errors' => $exception->errors()]);
} catch (Throwable $exception) {
    echo json_encode(['status' => 'error', 'message' => get_class($exception).': '.$exception->getMessage()]);
}
