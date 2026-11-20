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

    $this->employee = Employee::factory()->create(['name' => 'Ana Souza']);
    $this->hr = User::factory()->create(['name' => 'Analista RH']);
    $this->hr->grantPermission(Permission::Hr);
    $this->hr->refresh();
    $this->service = app(PointJustificationService::class);

    $this->reason = fn (string $code) => PointJustificationReason::query()->where('code', $code)->sole();

    $this->interval = fn (string $start, string $end, array $extra = []) => ['reason_id' => ($this->reason)('justified_delay')->id, 'treatment' => 'full_allowance', 'start_time' => $start, 'end_time' => $end, ...$extra];
    $this->fullDay = fn (array $extra = []) => ['reason_id' => ($this->reason)('medical_certificate')->id, 'treatment' => 'full_allowance', 'full_day' => true, ...$extra];
    $this->punch = fn (string $code, string $time, string $kind, array $extra = []) => ['reason_id' => ($this->reason)($code)->id, 'treatment' => 'no_allowance', 'start_time' => $time, 'punch_kind' => $kind, ...$extra];

    $this->register = fn (array $input, string $date = '2026-09-15') => $this->service
        ->register($this->employee, $date, ['request_uuid' => (string) Str::uuid(), ...$input], $this->hr)['justification'];

    $this->update = fn (PointJustification $justification, array $input, ?int $version = null, ?User $user = null) => $this->service
        ->update($justification, $input, $version ?? $justification->fresh()->version, $user ?? $this->hr);

    $this->errors = function (callable $operation): array {
        try {
            $operation();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    };

    $this->pointsSnapshot = fn () => DB::table('points')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $this->historiesSnapshot = fn () => DB::table('point_justification_histories')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
});

describe('valid edition', function () {
    test('edits a justification, increments the version and records who changed it', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));

        $result = ($this->update)($justification, ($this->interval)('07:30', '09:00', ['treatment' => 'partial_allowance', 'allowed_minutes' => 60, 'observation' => 'Consulta']));
        $updated = $result['justification'];

        expect($result['changed'])->toBeTrue()
            ->and($updated->version)->toBe(2)
            ->and($updated->end_time)->toBe('09:00:00')
            ->and($updated->duration_minutes)->toBe(90)
            ->and($updated->allowed_minutes)->toBe(60)
            ->and($updated->observation)->toBe('Consulta')
            ->and($updated->updated_by)->toBe($this->hr->id)
            ->and($updated->created_by)->toBe($this->hr->id)
            ->and($updated->request_uuid)->toBe($justification->request_uuid)
            ->and(PointJustification::query()->count())->toBe(1);
    });

    test('the history keeps the previous and the new values', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));
        $before = $justification->fresh()->getAttributes();

        ($this->update)($justification, ($this->interval)('07:00', '08:15', ['observation' => 'Ajuste']));

        $history = PointJustificationHistory::query()->where('action', JustificationHistoryAction::Updated)->sole();

        expect($history->old_values['justification'])->toMatchArray(['start_time' => '07:30:00', 'version' => 1, 'observation' => null, 'duration_minutes' => 45])
            ->and($history->old_values['justification']['id'])->toBe($before['id'])
            ->and($history->new_values['justification'])->toMatchArray(['start_time' => '07:00:00', 'version' => 2, 'observation' => 'Ajuste', 'duration_minutes' => 75])
            ->and($history->new_values['reason'])->toMatchArray(['code' => 'justified_delay'])
            ->and($history->old_values['manual_point'])->toBeNull()
            ->and($history->new_values['manual_point'])->toBeNull()
            ->and($history->description)->toBe('Alterado: horário inicial, observação.')
            ->and($history->performed_by)->toBe($this->hr->id)
            ->and($history->performed_by_name)->toBe('Analista RH')
            ->and($history->affected_competence->toDateString())->toBe('2026-10-01')
            ->and($history->closed_period)->toBeFalse()
            ->and(PointJustificationHistory::query()->where('action', JustificationHistoryAction::Created)->count())->toBe(1);
    });

    test('earlier history rows are not touched by an edition', function () {
        $justification = ($this->register)(($this->punch)('forgotten_punch', '07:30', 'entry'));
        $before = ($this->historiesSnapshot)();

        ($this->update)($justification, ($this->punch)('forgotten_punch', '07:45', 'entry'));

        expect(array_slice(($this->historiesSnapshot)(), 0, count($before)))->toBe($before);
    });

    test('an edition without changes writes nothing', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15', ['observation' => 'Trânsito']));

        $result = ($this->update)($justification, ($this->interval)('07:30', '08:15', ['observation' => ' Trânsito ']));

        expect($result['changed'])->toBeFalse()
            ->and($justification->fresh()->version)->toBe(1)
            ->and(PointJustificationHistory::query()->count())->toBe(1);
    });

    test('the date of the justification can be changed', function () {
        $justification = ($this->register)(($this->fullDay)());

        ($this->update)($justification, ($this->fullDay)(['occurrence_date' => '2026-09-16']));

        expect($justification->fresh()->occurrence_date->toDateString())->toBe('2026-09-16')
            ->and(PointJustificationHistory::query()->where('action', JustificationHistoryAction::Updated)->sole()->description)->toBe('Alterado: data.');
    });

    test('all fields are validated again', function (array $input, string $key) {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));

        if (is_string($input['reason_id'] ?? null)) {
            $input['reason_id'] = ($this->reason)($input['reason_id'])->id;
        }

        expect(($this->errors)(fn () => ($this->update)($justification, $input)))->toHaveKey($key)
            ->and($justification->fresh()->version)->toBe(1);
    })->with([
        'missing reason' => [['reason_id' => null, 'treatment' => 'full_allowance'], 'reason_id'],
        'inactive reason' => [['reason_id' => -1, 'treatment' => 'full_allowance'], 'reason_id'],
        'hour bank' => [['reason_id' => 'justified_delay', 'treatment' => 'hour_bank', 'start_time' => '07:30', 'end_time' => '08:00'], 'treatment'],
        'invalid interval' => [['reason_id' => 'justified_delay', 'treatment' => 'full_allowance', 'start_time' => '09:00', 'end_time' => '08:00'], 'end_time'],
        'partial without minutes' => [['reason_id' => 'justified_delay', 'treatment' => 'partial_allowance', 'start_time' => '07:30', 'end_time' => '08:00'], 'allowed_minutes'],
        'minutes above duration' => [['reason_id' => 'justified_delay', 'treatment' => 'partial_allowance', 'allowed_minutes' => 31, 'start_time' => '07:30', 'end_time' => '08:00'], 'allowed_minutes'],
        'invalid date' => [['reason_id' => 'justified_delay', 'treatment' => 'full_allowance', 'start_time' => '07:30', 'end_time' => '08:00', 'occurrence_date' => '2026-02-30'], 'occurrence_date'],
    ]);
});

describe('optimistic concurrency', function () {
    test('an outdated version is refused without overwriting the newer change', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));
        $other = User::factory()->create();
        $other->grantPermission(Permission::Hr);

        ($this->update)($justification, ($this->interval)('07:30', '08:30'), 1, $other->fresh());

        expect(($this->errors)(fn () => ($this->update)($justification, ($this->interval)('07:00', '08:15'), 1)))
            ->toBe(['version' => [PointJustificationService::VERSION_CONFLICT_MESSAGE]]);

        $current = $justification->fresh();

        expect($current->version)->toBe(2)
            ->and($current->start_time)->toBe('07:30:00')
            ->and($current->end_time)->toBe('08:30:00')
            ->and($current->updated_by)->toBe($other->id)
            ->and(PointJustificationHistory::query()->where('action', JustificationHistoryAction::Updated)->count())->toBe(1);
    });

    test('a version from the future is refused as well', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));

        expect(($this->errors)(fn () => ($this->update)($justification, ($this->interval)('07:00', '08:15'), 5)))->toHaveKey('version');
    });

    test('a canceled justification cannot be edited', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));
        $this->service->cancel($justification, 'Lançado em duplicidade', 1, $this->hr);

        expect(($this->errors)(fn () => ($this->update)($justification, ($this->interval)('07:00', '08:15'))))
            ->toBe(['form' => ['Esta justificativa está cancelada e não pode ser alterada.']]);
    });
});

describe('overlap', function () {
    test('an edition overlapping another active justification is refused', function () {
        ($this->register)(($this->interval)('10:00', '12:00'));
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));

        expect(($this->errors)(fn () => ($this->update)($justification, ($this->interval)('07:30', '10:30'))))->toHaveKey('period')
            ->and($justification->fresh()->version)->toBe(1);
    });

    test('the justification does not overlap with itself', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));

        expect(($this->errors)(fn () => ($this->update)($justification, ($this->interval)('07:00', '08:30'))))->toBe([]);
    });

    test('moving the date onto a full day of another justification is refused', function () {
        ($this->register)(($this->fullDay)(), '2026-09-16');
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));

        expect(($this->errors)(fn () => ($this->update)($justification, ($this->interval)('07:30', '08:15', ['occurrence_date' => '2026-09-16']))))->toHaveKey('period');
    });
});

describe('linked manual punch', function () {
    test('changing the time moves the linked manual punch and records both snapshots', function () {
        Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => '07:30:00']);
        $justification = ($this->register)(($this->punch)('forgotten_punch', '12:00', 'break_start'));
        $pointId = $justification->manual_point_id;

        ($this->update)($justification, ($this->punch)('forgotten_punch', '12:15', 'break_start'));

        $justification->refresh();
        $moved = PointJustificationHistory::query()->where('action', JustificationHistoryAction::ManualPointUpdated)->sole();
        $updated = PointJustificationHistory::query()->where('action', JustificationHistoryAction::Updated)->sole();

        expect($justification->manual_point_id)->toBe($pointId)
            ->and(DB::table('points')->where('id', $pointId)->value('time'))->toBe('12:15:00')
            ->and(Point::query()->count())->toBe(2)
            ->and($moved->old_values['manual_point'])->toMatchArray(['id' => $pointId, 'time' => '12:00:00', 'date' => '2026-09-15', 'type' => 'manual'])
            ->and($moved->new_values['manual_point'])->toMatchArray(['id' => $pointId, 'time' => '12:15:00', 'date' => '2026-09-15', 'type' => 'manual'])
            ->and($moved->new_values['sequence'])->toEqual(['position' => 2, 'kind' => 'exit', 'total' => 2])
            ->and($moved->description)->toBe('Marcação manual alterada de 15/09/2026 12:00 para 15/09/2026 12:15 (2ª do dia, saída).')
            ->and($moved->manual_point_id)->toBe($pointId)
            ->and($updated->old_values['manual_point']['time'])->toBe('12:00:00')
            ->and($updated->new_values['manual_point']['time'])->toBe('12:15:00');
    });

    test('changing the date moves the punch to the new date', function () {
        $justification = ($this->register)(($this->punch)('forgotten_punch', '07:30', 'entry'));

        ($this->update)($justification, ($this->punch)('forgotten_punch', '07:30', 'entry', ['occurrence_date' => '2026-09-16']));

        expect(DB::table('points')->where('id', $justification->manual_point_id)->value('date'))->toBe('2026-09-16')
            ->and(Point::query()->count())->toBe(1);
    });

    test('changing only the observation does not touch the punch', function () {
        $justification = ($this->register)(($this->punch)('forgotten_punch', '07:30', 'entry'));
        $before = ($this->pointsSnapshot)();

        ($this->update)($justification, ($this->punch)('forgotten_punch', '07:30', 'entry', ['observation' => 'Confirmado']));

        expect(($this->pointsSnapshot)())->toBe($before)
            ->and(PointJustificationHistory::query()->where('action', JustificationHistoryAction::ManualPointUpdated)->count())->toBe(0);
    });

    test('a new time at the minute of another punch is refused', function () {
        Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => '07:30:00']);
        Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => '12:00:40']);
        $justification = ($this->register)(($this->punch)('forgotten_punch', '13:00', 'break_end'));
        $before = ($this->pointsSnapshot)();

        expect(($this->errors)(fn () => ($this->update)($justification, ($this->punch)('forgotten_punch', '12:00', 'break_end'))))->toHaveKey('start_time')
            ->and(($this->pointsSnapshot)())->toBe($before)
            ->and($justification->fresh()->version)->toBe(1);
    });

    test('a new time breaking the chronological sequence is refused', function () {
        Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => '07:30:00']);
        $justification = ($this->register)(($this->punch)('forgotten_punch', '12:00', 'break_start'));
        $before = ($this->pointsSnapshot)();

        $errors = ($this->errors)(fn () => ($this->update)($justification, ($this->punch)('forgotten_punch', '07:00', 'break_start')));

        expect($errors['start_time'][0])->toContain('seria a 1ª (entrada), incompatível com o tipo informado (início do intervalo)')
            ->and(($this->pointsSnapshot)())->toBe($before);
    });

    test('changing the kind is checked against the sequence even without moving the punch', function () {
        $justification = ($this->register)(($this->punch)('forgotten_punch', '07:30', 'entry'));

        expect(($this->errors)(fn () => ($this->update)($justification, ($this->punch)('forgotten_punch', '07:30', 'exit'))))->toHaveKey('start_time');
    });

    test('the linked punch does not count as a tie with itself', function () {
        $justification = ($this->register)(($this->punch)('forgotten_punch', '07:30', 'entry'));

        expect(($this->errors)(fn () => ($this->update)($justification, ($this->punch)('clock_failure', '07:30', 'entry'))))->toBe([]);
    });

    test('a protected linked punch cannot be moved', function (string $protection) {
        $justification = ($this->register)(($this->punch)('forgotten_punch', '07:30', 'entry'));

        match ($protection) {
            'retained' => AfdHistoricalNsr::factory()->manualRetained($justification->manual_point_id)->create(),
            'annotated' => AfdPointDecision::factory()->create(['point_id' => $justification->manual_point_id]),
        };
        $before = ($this->pointsSnapshot)();

        $errors = ($this->errors)(fn () => ($this->update)($justification, ($this->punch)('forgotten_punch', '07:45', 'entry')));

        expect($errors)->toHaveKey('form')
            ->and($errors['form'][0])->toContain($protection === 'retained' ? Point::RETAINED_BY_HR_MESSAGE : Point::ANNOTATED_BY_HR_MESSAGE)
            ->and(($this->pointsSnapshot)())->toBe($before)
            ->and($justification->fresh()->version)->toBe(1);
    })->with(['retained', 'annotated']);

    test('switching to a reason that does not create a punch is refused', function () {
        $justification = ($this->register)(($this->punch)('forgotten_punch', '07:30', 'entry'));

        expect(($this->errors)(fn () => ($this->update)($justification, ($this->punch)('duplicate_punch', '07:30', 'entry')))['reason_id'][0])
            ->toContain('cancele a justificativa')
            ->and(Point::query()->count())->toBe(1);
    });

    test('switching to a reason that creates a punch creates it', function () {
        $justification = ($this->register)(($this->punch)('duplicate_punch', '07:30', 'entry'));

        ($this->update)($justification, ($this->punch)('forgotten_punch', '07:30', 'entry'));

        $justification->refresh();

        expect($justification->manual_point_id)->not->toBeNull()
            ->and($justification->manualPoint->type)->toBe('manual')
            ->and(PointJustificationHistory::query()->where('action', JustificationHistoryAction::ManualPointCreated)->sole()->description)
            ->toBe('Marcação manual das 07:30 criada na edição como 1ª do dia (entrada).');
    });
});

describe('security and closed period', function () {
    test('a user without hr cannot edit', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));

        expect(fn () => ($this->update)($justification, ($this->interval)('07:00', '08:15'), 1, User::factory()->create()))
            ->toThrow(AuthorizationException::class);

        expect($justification->fresh()->version)->toBe(1);
    });

    test('a closed period requires the additional permission', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));
        BenefitPeriod::factory()->forCompetence('2026-10')->closed()->create();

        expect(($this->errors)(fn () => ($this->update)($justification, ($this->interval)('07:00', '08:15'))))->toHaveKey('occurrence_date')
            ->and($justification->fresh()->version)->toBe(1);
    });

    test('moving a justification out of a closed period also requires the permission', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));
        BenefitPeriod::factory()->forCompetence('2026-10')->closed()->create();

        expect(($this->errors)(fn () => ($this->update)($justification, ($this->interval)('07:30', '08:15', ['occurrence_date' => '2026-10-15']))))->toHaveKey('occurrence_date')
            ->and($justification->fresh()->occurrence_date->toDateString())->toBe('2026-09-15');
    });

    test('with both permissions the edition in a closed period is recorded and flagged', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15'));
        $period = BenefitPeriod::factory()->forCompetence('2026-10')->closed()->create();
        $periodBefore = $period->fresh()->getAttributes();
        $this->hr->grantPermission(Permission::HrClosedPeriod);

        ($this->update)($justification, ($this->interval)('07:00', '08:15'), null, $this->hr->fresh());

        $history = PointJustificationHistory::query()->where('action', JustificationHistoryAction::Updated)->sole();

        expect($history->closed_period)->toBeTrue()
            ->and($history->affected_competence->toDateString())->toBe('2026-10-01')
            ->and($history->description)->toBe('Alterado: horário inicial. Competência fechada (10/2026).')
            ->and($period->fresh()->getAttributes())->toBe($periodBefore)
            ->and(BenefitAdjustment::query()->count())->toBe(0);
    });
});

describe('atomicity and integrity', function () {
    test('everything is rolled back when a step of the edition fails', function (string $failing) {
        Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => '07:30:00']);
        $justification = ($this->register)(($this->punch)('forgotten_punch', '12:00', 'break_start'));
        $points = ($this->pointsSnapshot)();
        $justifications = DB::table('point_justifications')->get()->map(fn ($row) => (array) $row)->all();
        $histories = ($this->historiesSnapshot)();

        match ($failing) {
            'justification' => PointJustification::updating(fn () => throw new RuntimeException('falha simulada')),
            'history' => PointJustificationHistory::creating(fn () => throw new RuntimeException('falha simulada')),
            'second history' => PointJustificationHistory::creating(function (PointJustificationHistory $history) {
                if ($history->action === JustificationHistoryAction::ManualPointUpdated) {
                    throw new RuntimeException('falha simulada');
                }
            }),
        };

        expect(fn () => ($this->update)($justification, ($this->punch)('forgotten_punch', '12:30', 'break_start')))->toThrow(RuntimeException::class);

        expect(($this->pointsSnapshot)())->toBe($points)
            ->and(DB::table('point_justifications')->get()->map(fn ($row) => (array) $row)->all())->toBe($justifications)
            ->and(($this->historiesSnapshot)())->toBe($histories);
    })->with(['justification', 'history', 'second history']);

    test('imported punches stay exactly the same', function () {
        Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => '07:30:00', 'rep_serial' => '00004004330000001', 'nsr' => 10]);
        Point::factory()->forEmployee($this->employee)->on('2026-09-16')->create(['time' => '07:30:00', 'rep_serial' => '00004004330000001', 'nsr' => 11]);
        $justification = ($this->register)(($this->punch)('forgotten_punch', '12:00', 'break_start'));
        $imported = DB::table('points')->where('type', 'importado')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        ($this->update)($justification, ($this->punch)('forgotten_punch', '12:10', 'break_start'));
        ($this->update)($justification, ($this->punch)('forgotten_punch', '12:10', 'break_start', ['occurrence_date' => '2026-09-16']));
        ($this->errors)(fn () => ($this->update)($justification, ($this->punch)('forgotten_punch', '07:30', 'entry', ['occurrence_date' => '2026-09-16'])));

        expect(DB::table('points')->where('type', 'importado')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($imported);
    });
});
