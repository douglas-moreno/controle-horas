<?php

use App\Enums\JustificationHistoryAction;
use App\Enums\Permission;
use App\Models\AfdHistoricalNsr;
use App\Models\AfdPointDecision;
use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\Point;
use App\Models\PointJustification;
use App\Models\PointJustificationHistory;
use App\Models\PointJustificationReason;
use App\Models\User;
use App\Services\PointJustificationService;
use Database\Seeders\PointJustificationReasonSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PointJustificationReasonSeeder::class);

    $this->employee = Employee::factory()->create();
    $this->hr = User::factory()->create(['name' => 'Analista RH']);
    $this->hr->grantPermission(Permission::Hr);
    $this->hr->refresh();
    $this->service = app(PointJustificationService::class);

    $this->reason = fn (string $code) => PointJustificationReason::query()->where('code', $code)->sole();
    $this->interval = fn (string $start, string $end) => ['reason_id' => ($this->reason)('justified_delay')->id, 'treatment' => 'full_allowance', 'start_time' => $start, 'end_time' => $end];
    $this->punch = fn (string $time, string $kind) => ['reason_id' => ($this->reason)('forgotten_punch')->id, 'treatment' => 'no_allowance', 'start_time' => $time, 'punch_kind' => $kind];

    $this->register = fn (array $input) => $this->service
        ->register($this->employee, '2026-09-15', ['request_uuid' => (string) Str::uuid(), ...$input], $this->hr)['justification'];

    $this->cancel = fn (PointJustification $justification, ?string $reason = 'Lançado por engano', ?int $version = null, ?User $user = null) => $this->service
        ->cancel($justification, $reason, $version ?? $justification->fresh()->version, $user ?? $this->hr);

    $this->errors = function (callable $operation): array {
        try {
            $operation();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    };

    $this->table = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
});

test('cancels a justification without a linked punch, keeping the record', function () {
    $justification = ($this->register)(($this->interval)('07:30', '08:15'));

    $canceled = ($this->cancel)($justification, '  Lançado por engano  ');

    expect($canceled->isCanceled())->toBeTrue()
        ->and($canceled->canceled_by)->toBe($this->hr->id)
        ->and($canceled->cancellation_reason)->toBe('Lançado por engano')
        ->and($canceled->version)->toBe(2)
        ->and(PointJustification::query()->count())->toBe(1)
        ->and(PointJustification::query()->active()->count())->toBe(0);
});

test('cancels a justification with a linked punch, removing only that punch', function () {
    $imported = Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => '07:30:00', 'rep_serial' => '00004004330000001', 'nsr' => 10]);
    $justification = ($this->register)(($this->punch)('12:00', 'break_start'));
    $pointId = $justification->manual_point_id;
    $importedBefore = DB::table('points')->where('id', $imported->id)->first();

    $canceled = ($this->cancel)($justification);

    expect(Point::query()->find($pointId))->toBeNull()
        ->and(DB::table('points')->where('id', $imported->id)->first())->toEqual($importedBefore)
        ->and(Point::query()->count())->toBe(1)
        ->and($canceled->manual_point_id)->toBeNull();
});

test('the cancellation history is complete and keeps the punch snapshot', function () {
    $justification = ($this->register)(($this->punch)('07:30', 'entry'));
    $pointId = $justification->manual_point_id;
    $pointRow = (array) DB::table('points')->find($pointId);
    $earlier = ($this->table)('point_justification_histories');

    ($this->cancel)($justification, 'Batida encontrada no relógio');

    $histories = PointJustificationHistory::query()->orderBy('id')->get();
    $canceled = $histories->firstWhere('action', JustificationHistoryAction::Canceled);
    $removed = $histories->firstWhere('action', JustificationHistoryAction::ManualPointRemoved);

    expect($histories->pluck('action')->all())->toBe([
        JustificationHistoryAction::Created,
        JustificationHistoryAction::ManualPointCreated,
        JustificationHistoryAction::Canceled,
        JustificationHistoryAction::ManualPointRemoved,
    ])
        ->and(array_slice(($this->table)('point_justification_histories'), 0, 2))->toBe($earlier)
        ->and($canceled->old_values['justification'])->toMatchArray(['canceled_at' => null, 'version' => 1, 'manual_point_id' => $pointId])
        ->and($canceled->old_values['manual_point'])->toEqual($pointRow)
        ->and($canceled->new_values['justification'])->toMatchArray(['canceled_by' => $this->hr->id, 'cancellation_reason' => 'Batida encontrada no relógio', 'version' => 2])
        ->and($canceled->new_values['justification']['canceled_at'])->not->toBeNull()
        ->and($canceled->description)->toBe('Cancelada: Batida encontrada no relógio')
        ->and($canceled->manual_point_id)->toBe($pointId)
        ->and($canceled->performed_by_name)->toBe('Analista RH')
        ->and($canceled->affected_competence->toDateString())->toBe('2026-10-01')
        ->and($canceled->closed_period)->toBeFalse()
        ->and($removed->old_values['manual_point'])->toEqual($pointRow)
        ->and($removed->new_values)->toBeNull()
        ->and($removed->description)->toBe('Marcação manual de 15/09/2026 07:30 removida no cancelamento.');
});

test('the cancellation reason is required and limited', function (?string $reason, string $message) {
    $justification = ($this->register)(($this->interval)('07:30', '08:15'));

    expect(($this->errors)(fn () => ($this->cancel)($justification, $reason)))->toBe(['cancellation_reason' => [$message]])
        ->and($justification->fresh()->isCanceled())->toBeFalse();
})->with([
    'missing' => [null, 'Informe o motivo do cancelamento.'],
    'blank' => ['   ', 'Informe o motivo do cancelamento.'],
    'too long' => [str_repeat('a', 1001), 'O motivo do cancelamento deve ter no máximo 1000 caracteres.'],
]);

test('a cancellation cannot be repeated', function () {
    $justification = ($this->register)(($this->punch)('07:30', 'entry'));
    ($this->cancel)($justification);
    $histories = ($this->table)('point_justification_histories');

    expect(($this->errors)(fn () => ($this->cancel)($justification)))->toBe(['form' => ['Esta justificativa já foi cancelada.']])
        ->and(($this->errors)(fn () => ($this->cancel)($justification, 'Outro', 1)))->toBe(['form' => ['Esta justificativa já foi cancelada.']])
        ->and(($this->table)('point_justification_histories'))->toBe($histories);
});

test('an outdated version is refused', function () {
    $justification = ($this->register)(($this->interval)('07:30', '08:15'));
    $this->service->update($justification, ($this->interval)('07:30', '08:30'), 1, $this->hr);

    expect(($this->errors)(fn () => ($this->cancel)($justification, 'Engano', 1)))->toBe(['version' => [PointJustificationService::VERSION_CONFLICT_MESSAGE]])
        ->and($justification->fresh()->isCanceled())->toBeFalse();
});

test('a punch protected by an HR decision blocks the cancellation', function (string $protection, string $message) {
    $justification = ($this->register)(($this->punch)('07:30', 'entry'));

    match ($protection) {
        'retained' => AfdHistoricalNsr::factory()->manualRetained($justification->manual_point_id)->create(),
        'annotated' => AfdPointDecision::factory()->create(['point_id' => $justification->manual_point_id]),
    };
    $points = ($this->table)('points');
    $histories = ($this->table)('point_justification_histories');
    $protections = [($this->table)('afd_historical_nsrs'), ($this->table)('afd_point_decisions')];

    $errors = ($this->errors)(fn () => ($this->cancel)($justification));

    expect($errors['form'][0])->toBe('Cancelamento bloqueado: a marcação manual vinculada está protegida. '.$message)
        ->and(($this->table)('points'))->toBe($points)
        ->and(($this->table)('point_justification_histories'))->toBe($histories)
        ->and([($this->table)('afd_historical_nsrs'), ($this->table)('afd_point_decisions')])->toBe($protections)
        ->and($justification->fresh()->isCanceled())->toBeFalse()
        ->and($justification->fresh()->version)->toBe(1);
})->with([
    'retained by decision' => ['retained', Point::RETAINED_BY_HR_MESSAGE],
    'annotated' => ['annotated', Point::ANNOTATED_BY_HR_MESSAGE],
]);

test('a linked punch already removed elsewhere does not block the cancellation', function () {
    $justification = ($this->register)(($this->punch)('07:30', 'entry'));
    DB::table('points')->where('id', $justification->manual_point_id)->delete();

    $canceled = ($this->cancel)($justification->fresh());

    expect($canceled->isCanceled())->toBeTrue()
        ->and(PointJustificationHistory::query()->where('action', JustificationHistoryAction::ManualPointRemoved)->count())->toBe(0);
});

test('a user without hr cannot cancel', function () {
    $justification = ($this->register)(($this->punch)('07:30', 'entry'));

    expect(fn () => ($this->cancel)($justification, 'Engano', 1, User::factory()->create()))->toThrow(AuthorizationException::class);

    expect($justification->fresh()->isCanceled())->toBeFalse()
        ->and(Point::query()->count())->toBe(1);
});

test('a closed period requires the additional permission to cancel', function () {
    $justification = ($this->register)(($this->punch)('07:30', 'entry'));
    BenefitPeriod::factory()->forCompetence('2026-10')->closed()->create();

    expect(($this->errors)(fn () => ($this->cancel)($justification)))->toHaveKey('occurrence_date')
        ->and($justification->fresh()->isCanceled())->toBeFalse()
        ->and(Point::query()->count())->toBe(1);
});

test('with both permissions the cancellation in a closed period is flagged and the period is untouched', function () {
    $justification = ($this->register)(($this->punch)('07:30', 'entry'));
    $period = BenefitPeriod::factory()->forCompetence('2026-10')->closed()->create();
    $periodBefore = $period->fresh()->getAttributes();
    $this->hr->grantPermission(Permission::HrClosedPeriod);

    ($this->cancel)($justification, 'Engano', null, $this->hr->fresh());

    $histories = PointJustificationHistory::query()->whereIn('action', [JustificationHistoryAction::Canceled, JustificationHistoryAction::ManualPointRemoved])->get();

    expect($histories)->toHaveCount(2)
        ->and($histories->every(fn ($history) => $history->closed_period && $history->affected_competence->toDateString() === '2026-10-01'))->toBeTrue()
        ->and($histories->firstWhere('action', JustificationHistoryAction::Canceled)->description)->toBe('Cancelada: Engano Competência fechada (10/2026).')
        ->and($period->fresh()->getAttributes())->toBe($periodBefore)
        ->and(BenefitAdjustment::query()->count())->toBe(0);
});

test('everything is rolled back when a step of the cancellation fails', function (string $failing) {
    $justification = ($this->register)(($this->punch)('07:30', 'entry'));
    $snapshot = fn () => [($this->table)('points'), ($this->table)('point_justifications'), ($this->table)('point_justification_histories')];
    $before = $snapshot();

    match ($failing) {
        'justification' => PointJustification::updating(fn () => throw new RuntimeException('falha simulada')),
        'history' => PointJustificationHistory::creating(fn () => throw new RuntimeException('falha simulada')),
        'punch history' => PointJustificationHistory::creating(function (PointJustificationHistory $history) {
            if ($history->action === JustificationHistoryAction::ManualPointRemoved) {
                throw new RuntimeException('falha simulada');
            }
        }),
    };

    expect(fn () => ($this->cancel)($justification))->toThrow(RuntimeException::class);

    expect($snapshot())->toBe($before);
})->with(['justification', 'history', 'punch history']);

test('the justification row is never deleted', function () {
    $justification = ($this->register)(($this->interval)('07:30', '08:15'));
    ($this->cancel)($justification);

    expect(fn () => $justification->fresh()->delete())->toThrow(LogicException::class)
        ->and(PointJustification::query()->count())->toBe(1);
});
