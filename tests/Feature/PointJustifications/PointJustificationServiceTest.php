<?php

use App\Enums\JustificationHistoryAction;
use App\Enums\Permission;
use App\Enums\PunchKind;
use App\Models\AfdHistoricalNsr;
use App\Models\AfdPointDecision;
use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriod;
use App\Models\BenefitSuggestionRun;
use App\Models\Employee;
use App\Models\Point;
use App\Models\PointJustification;
use App\Models\PointJustificationHistory;
use App\Models\PointJustificationReason;
use App\Models\User;
use App\Services\PointJustificationService;
use App\Services\PointJustificationValidator;
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

    $this->reason = fn (string $code) => PointJustificationReason::query()->where('code', $code)->sole();

    $this->register = fn (array $input, string $date = '2026-09-15', ?User $user = null, ?Employee $employee = null) => app(PointJustificationService::class)
        ->register($employee ?? $this->employee, $date, ['request_uuid' => (string) Str::uuid(), ...$input], $user ?? $this->hr);

    $this->errors = function (array $input, string $date = '2026-09-15', ?User $user = null): array {
        try {
            ($this->register)($input, $date, $user);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    };

    $this->interval = fn (string $start, string $end, array $extra = []) => ['reason_id' => ($this->reason)('justified_delay')->id, 'treatment' => 'full_allowance', 'start_time' => $start, 'end_time' => $end, ...$extra];
    $this->fullDay = fn (array $extra = []) => ['reason_id' => ($this->reason)('medical_certificate')->id, 'treatment' => 'full_allowance', 'full_day' => true, ...$extra];
    $this->punch = fn (string $code, string $time, string $kind, array $extra = []) => ['reason_id' => ($this->reason)($code)->id, 'treatment' => 'no_allowance', 'start_time' => $time, 'punch_kind' => $kind, ...$extra];

    $this->pointsSnapshot = fn () => DB::table('points')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
});

describe('registration and atomicity', function () {
    test('registers a justification without a manual punch', function () {
        $result = ($this->register)(($this->interval)('07:30', '08:15', ['observation' => 'Trânsito']));
        $justification = $result['justification'];

        expect($result['created'])->toBeTrue()
            ->and($justification->exists)->toBeTrue()
            ->and($justification->manual_point_id)->toBeNull()
            ->and($justification->duration_minutes)->toBe(45)
            ->and($justification->created_by)->toBe($this->hr->id)
            ->and($justification->version)->toBe(1)
            ->and(Point::query()->count())->toBe(0)
            ->and(PointJustificationHistory::query()->pluck('action')->all())->toBe([JustificationHistoryAction::Created]);
    });

    test('registers a forgotten punch with a linked manual punch and its history', function () {
        Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => '07:30:00']);

        $justification = ($this->register)(($this->punch)('forgotten_punch', '12:00', 'break_start'))['justification'];
        $point = $justification->manualPoint;

        expect($point)->not->toBeNull()
            ->and($point->type)->toBe('manual')
            ->and((string) $point->pis)->toBe((string) $this->employee->pis)
            ->and(DB::table('points')->where('id', $point->id)->value('time'))->toBe('12:00:00')
            ->and($point->justification->is($justification))->toBeTrue()
            ->and($justification->punch_kind)->toBe(PunchKind::BreakStart);

        $histories = PointJustificationHistory::query()->orderBy('id')->get();

        expect($histories->pluck('action')->all())->toBe([JustificationHistoryAction::Created, JustificationHistoryAction::ManualPointCreated])
            ->and($histories->pluck('manual_point_id')->all())->toBe([$point->id, $point->id])
            ->and($histories[1]->new_values['manual_point'])->toMatchArray(['id' => $point->id, 'type' => 'manual', 'time' => '12:00:00', 'nsr' => null, 'afd_import_id' => null])
            ->and($histories[1]->description)->toBe('Marcação manual das 12:00 criada como 2ª do dia (saída).');
    });

    test('the creation history records the persisted values', function () {
        $justification = ($this->register)(($this->interval)('07:30', '08:15', ['treatment' => 'partial_allowance', 'allowed_minutes' => 30]))['justification'];
        $history = PointJustificationHistory::query()->sole();

        expect($history->point_justification_id)->toBe($justification->id)
            ->and($history->performed_by)->toBe($this->hr->id)
            ->and($history->performed_by_name)->toBe('Analista RH')
            ->and($history->created_at)->not->toBeNull()
            ->and($history->affected_competence->toDateString())->toBe('2026-10-01')
            ->and($history->closed_period)->toBeFalse()
            ->and($history->old_values)->toBeNull()
            ->and($history->new_values['justification'])->toMatchArray([
                'id' => $justification->id,
                'employee_id' => $this->employee->id,
                'occurrence_date' => '2026-09-15',
                'start_time' => '07:30:00',
                'end_time' => '08:15:00',
                'duration_minutes' => 45,
                'treatment' => 'partial_allowance',
                'allowed_minutes' => 30,
                'request_uuid' => $justification->request_uuid,
            ])
            ->and($history->new_values['reason'])->toMatchArray(['code' => 'justified_delay', 'name' => 'Atraso justificado'])
            ->and($history->new_values['employee'])->toMatchArray(['id' => $this->employee->id, 'name' => 'Ana Souza'])
            ->and($history->new_values['manual_point'])->toBeNull();
    });

    test('everything is rolled back when a step fails', function (string $model) {
        $model::creating(fn () => throw new RuntimeException('falha simulada em '.$model));

        expect(fn () => ($this->register)(($this->punch)('forgotten_punch', '07:30', 'entry')))->toThrow(RuntimeException::class);

        expect(Point::query()->count())->toBe(0)
            ->and(PointJustification::query()->count())->toBe(0)
            ->and(PointJustificationHistory::query()->count())->toBe(0);
    })->with([
        'manual punch' => [Point::class],
        'justification' => [PointJustification::class],
        'history' => [PointJustificationHistory::class],
    ]);

    test('the second history failing also rolls back the first one', function () {
        PointJustificationHistory::creating(function (PointJustificationHistory $history) {
            if ($history->action === JustificationHistoryAction::ManualPointCreated) {
                throw new RuntimeException('falha no segundo histórico');
            }
        });

        expect(fn () => ($this->register)(($this->punch)('forgotten_punch', '07:30', 'entry')))->toThrow(RuntimeException::class);

        expect(Point::query()->count())->toBe(0)
            ->and(PointJustification::query()->count())->toBe(0)
            ->and(PointJustificationHistory::query()->count())->toBe(0);
    });
});

describe('reasons that create a manual punch', function () {
    test('creates the manual punch for the approved reasons', function (string $code) {
        $justification = ($this->register)(($this->punch)($code, '07:30', 'entry'))['justification'];

        expect($justification->manual_point_id)->not->toBeNull()
            ->and(Point::query()->where('type', 'manual')->count())->toBe(1);
    })->with(['forgotten_punch', 'clock_failure', 'external_work_without_punch']);

    test('other single punch reasons do not create a punch', function (string $code) {
        $justification = ($this->register)(($this->punch)($code, '07:30', 'entry'))['justification'];

        expect($justification->manual_point_id)->toBeNull()
            ->and(Point::query()->count())->toBe(0);
    })->with(['duplicate_punch', 'incorrect_punch', 'excess_punch']);

    test('interval and full day reasons do not create a punch', function () {
        ($this->register)(($this->interval)('07:30', '08:00'));
        ($this->register)(($this->fullDay)(), '2026-09-16');

        expect(Point::query()->count())->toBe(0);
    });
});

describe('chronological sequence', function () {
    test('the informed kind must match the position of the new punch', function (array $existing, string $time, string $kind, bool $accepted) {
        foreach ($existing as $index => $existingTime) {
            Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => $existingTime, 'type' => $index % 2 === 0 ? 'importado' : 'manual']);
        }

        $errors = ($this->errors)(($this->punch)('forgotten_punch', $time, $kind));

        if ($accepted) {
            expect($errors)->toBe([])->and(Point::query()->count())->toBe(count($existing) + 1);

            return;
        }

        expect($errors)->toHaveKey('start_time')
            ->and($errors['start_time'][0])->toContain('incompatível com o tipo informado')
            ->and(Point::query()->count())->toBe(count($existing))
            ->and(PointJustification::query()->count())->toBe(0);
    })->with([
        'first as entry' => [[], '07:30', 'entry', true],
        'first as exit' => [[], '07:30', 'exit', false],
        'second as break start' => [['07:30:00'], '12:00', 'break_start', true],
        'second as entry' => [['07:30:00'], '12:00', 'entry', false],
        'third as break end' => [['07:30:00', '12:00:00'], '13:00', 'break_end', true],
        'fourth as exit' => [['07:30:00', '12:00:00', '13:00:00'], '17:30', 'exit', true],
        'missing third between imported and manual' => [['07:30:00', '12:00:00', '17:30:00'], '13:00', 'break_end', true],
        'missing third declared as exit' => [['07:30:00', '12:00:00', '17:30:00'], '13:00', 'exit', false],
        'fifth as entry' => [['07:30:00', '12:00:00', '13:00:00', '17:30:00'], '18:00', 'entry', true],
        'sixth as exit' => [['07:30:00', '12:00:00', '13:00:00', '17:30:00', '18:00:00'], '22:00', 'exit', true],
    ]);

    test('a night shift punch after midnight is classified in its calendar date', function () {
        Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => '22:00:00']);

        expect(($this->errors)(($this->punch)('forgotten_punch', '02:00', 'exit'), '2026-09-16')['start_time'][0])->toContain('seria a 1ª (entrada)')
            ->and(($this->errors)(($this->punch)('forgotten_punch', '02:00', 'entry'), '2026-09-16'))->toBe([]);
    });

    test('an existing punch without time is an inconsistency that refuses the registration', function () {
        Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => null]);

        expect(($this->errors)(($this->punch)('forgotten_punch', '07:30', 'entry'))['start_time'][0])->toContain('não é possível determinar a sequência')
            ->and(PointJustification::query()->count())->toBe(0);
    });
});

describe('duplicates and overlaps', function () {
    test('a punch at the minute of an existing punch is refused', function (string $type) {
        Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => '07:30:20', 'type' => $type]);
        $before = ($this->pointsSnapshot)();

        expect(($this->errors)(($this->punch)('forgotten_punch', '07:30', 'exit'))['start_time'][0])->toContain('empate')
            ->and(($this->pointsSnapshot)())->toBe($before)
            ->and(PointJustification::query()->count())->toBe(0);
    })->with(['importado', 'manual']);

    test('overlapping intervals are refused', function (array $first, array $second) {
        ($this->register)(($this->interval)(...$first));

        expect(($this->errors)(($this->interval)(...$second)))->toHaveKey('period');
    })->with([
        'partial overlap' => [['08:00', '10:00'], ['09:00', '11:00']],
        'contained' => [['08:00', '12:00'], ['09:00', '10:00']],
        'containing' => [['09:00', '10:00'], ['08:00', '12:00']],
        'identical' => [['08:00', '10:00'], ['08:00', '10:00']],
    ]);

    test('adjacent intervals are accepted', function () {
        ($this->register)(($this->interval)('08:00', '10:00'));
        ($this->register)(($this->interval)('10:00', '11:00'));
        ($this->register)(($this->interval)('07:00', '08:00'));

        expect(PointJustification::query()->count())->toBe(3);
    });

    test('a full day conflicts with any other justification on the date', function (string $other) {
        $input = match ($other) {
            'interval' => ($this->interval)('08:00', '09:00'),
            'punch' => ($this->punch)('duplicate_punch', '23:59', 'exit'),
            'full day' => ($this->fullDay)(),
        };

        ($this->register)($input);

        expect(($this->errors)(($this->fullDay)()))->toHaveKey('period');
    })->with(['interval', 'punch', 'full day']);

    test('a justification conflicts with an existing full day', function () {
        ($this->register)(($this->fullDay)());

        expect(($this->errors)(($this->interval)('00:00', '00:30')))->toHaveKey('period')
            ->and(($this->errors)(($this->punch)('forgotten_punch', '07:30', 'entry')))->toHaveKey('period')
            ->and(Point::query()->count())->toBe(0);
    });

    test('a single punch inside an occupied interval is refused, outside is accepted', function () {
        ($this->register)(($this->interval)('08:00', '10:00'));

        expect(($this->errors)(($this->punch)('duplicate_punch', '08:00', 'entry')))->toHaveKey('period')
            ->and(($this->errors)(($this->punch)('duplicate_punch', '09:59', 'entry')))->toHaveKey('period')
            ->and(($this->errors)(($this->punch)('duplicate_punch', '10:00', 'entry')))->toBe([])
            ->and(($this->errors)(($this->punch)('duplicate_punch', '07:59', 'entry')))->toBe([]);
    });

    test('two single punches at the same minute are refused', function () {
        ($this->register)(($this->punch)('duplicate_punch', '08:00', 'entry'));

        expect(($this->errors)(($this->punch)('incorrect_punch', '08:00', 'entry')))->toHaveKey('period')
            ->and(($this->errors)(($this->punch)('incorrect_punch', '08:01', 'entry')))->toBe([]);
    });

    test('a canceled justification does not block a new one', function () {
        PointJustification::factory()->for($this->employee)->canceled()->between('2026-09-15', '08:00', '10:00')->create();
        PointJustification::factory()->for($this->employee)->canceled()->create(['occurrence_date' => '2026-09-15']);

        expect(($this->errors)(($this->interval)('08:00', '10:00')))->toBe([]);
    });

    test('justifications of other employees do not conflict', function () {
        PointJustification::factory()->create(['occurrence_date' => '2026-09-15']);

        expect(($this->errors)(($this->interval)('08:00', '10:00')))->toBe([]);
    });

    test('overlaps across midnight are detected on consecutive dates', function () {
        ($this->register)(($this->interval)('22:00', '02:00', ['ends_next_day' => true]), '2026-09-15');

        expect(($this->errors)(($this->interval)('01:00', '03:00'), '2026-09-16'))->toHaveKey('period')
            ->and(($this->errors)(($this->fullDay)(), '2026-09-16'))->toHaveKey('period')
            ->and(($this->errors)(($this->interval)('21:00', '22:30'), '2026-09-15'))->toHaveKey('period')
            ->and(($this->errors)(($this->interval)('02:00', '03:00'), '2026-09-16'))->toBe([])
            ->and(($this->errors)(($this->interval)('20:00', '22:00'), '2026-09-15'))->toBe([]);
    });

    test('a later registration crossing midnight conflicts with the next day', function () {
        ($this->register)(($this->interval)('01:00', '03:00'), '2026-09-16');

        expect(($this->errors)(($this->interval)('23:00', '01:30', ['ends_next_day' => true]), '2026-09-15'))->toHaveKey('period')
            ->and(($this->errors)(($this->interval)('23:00', '01:00', ['ends_next_day' => true]), '2026-09-15'))->toBe([]);
    });

    test('the overlap message identifies the conflicting justification', function () {
        ($this->register)(($this->interval)('08:00', '10:00'));

        expect(($this->errors)(($this->interval)('09:00', '11:00'))['period'][0])
            ->toBe('Já existe uma justificativa ativa incompatível para o período: Atraso justificado, 08:00–10:00 em 15/09/2026.');
    });
});

describe('idempotency', function () {
    test('the same request with the same content returns the existing record', function () {
        $input = ($this->punch)('forgotten_punch', '07:30', 'entry', ['request_uuid' => (string) Str::uuid(), 'observation' => 'Esqueceu']);

        $first = ($this->register)($input);
        $second = ($this->register)([...$input, 'observation' => '  Esqueceu  ', 'end_time' => '09:00']);

        expect($first['created'])->toBeTrue()
            ->and($second['created'])->toBeFalse()
            ->and($second['justification']->is($first['justification']))->toBeTrue()
            ->and(PointJustification::query()->count())->toBe(1)
            ->and(Point::query()->count())->toBe(1)
            ->and(PointJustificationHistory::query()->where('action', JustificationHistoryAction::Created)->count())->toBe(1)
            ->and(PointJustificationHistory::query()->count())->toBe(2);
    });

    test('the same request with different content is refused', function (array $change) {
        $input = ($this->interval)('08:00', '10:00', ['request_uuid' => (string) Str::uuid()]);
        ($this->register)($input);

        expect(($this->errors)([...$input, ...$change]))->toBe(['request_uuid' => [PointJustificationService::REQUEST_CONFLICT_MESSAGE]])
            ->and(PointJustification::query()->count())->toBe(1)
            ->and(PointJustificationHistory::query()->count())->toBe(1);
    })->with([
        'other time' => [['end_time' => '10:30']],
        'other treatment' => [['treatment' => 'no_allowance']],
        'other observation' => [['observation' => 'Outra']],
        'invalid content' => [['start_time' => '99:99']],
    ]);

    test('the same request for another employee or date is refused without revealing the record', function () {
        $input = ($this->interval)('08:00', '10:00', ['request_uuid' => (string) Str::uuid(), 'observation' => 'Sigilosa']);
        ($this->register)($input);
        $other = Employee::factory()->create();

        try {
            ($this->register)($input, '2026-09-15', null, $other);
            $this->fail('Deveria recusar.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toBe(['request_uuid' => [PointJustificationService::REQUEST_CONFLICT_MESSAGE]]);
        }

        expect(($this->errors)($input, '2026-09-16'))->toBe(['request_uuid' => [PointJustificationService::REQUEST_CONFLICT_MESSAGE]])
            ->and(PointJustification::query()->count())->toBe(1);
    });
});

describe('security', function () {
    test('a user without hr cannot register, even calling the service directly', function () {
        $common = User::factory()->create();

        expect(fn () => ($this->register)(($this->punch)('forgotten_punch', '07:30', 'entry'), '2026-09-15', $common))->toThrow(AuthorizationException::class);

        expect(PointJustification::query()->count())->toBe(0)->and(Point::query()->count())->toBe(0);
    });

    test('a user without hr learns nothing about an existing request identifier', function () {
        $input = ($this->interval)('08:00', '10:00', ['request_uuid' => (string) Str::uuid()]);
        ($this->register)($input);

        expect(fn () => ($this->register)($input, '2026-09-15', User::factory()->create()))->toThrow(AuthorizationException::class);
    });

    test('hr registers in an open or calculated period without the closed period flag', function (string $state) {
        BenefitPeriod::factory()->forCompetence('2026-10')->{$state}()->create();

        ($this->register)(($this->fullDay)());

        expect(PointJustificationHistory::query()->sole()->closed_period)->toBeFalse();
    })->with(['calculated']);

    test('a closed period requires the closed period permission', function () {
        BenefitPeriod::factory()->forCompetence('2026-10')->closed()->create();

        expect(($this->errors)(($this->punch)('forgotten_punch', '07:30', 'entry')))->toHaveKey('occurrence_date')
            ->and(PointJustification::query()->count())->toBe(0)
            ->and(Point::query()->count())->toBe(0);
    });

    test('with both permissions the closed period is registered and flagged in the history', function () {
        BenefitPeriod::factory()->forCompetence('2026-10')->closed()->create();
        $this->hr->grantPermission(Permission::HrClosedPeriod);

        ($this->register)(($this->punch)('forgotten_punch', '07:30', 'entry'), '2026-09-15', $this->hr->fresh());

        $histories = PointJustificationHistory::query()->get();

        expect($histories)->toHaveCount(2)
            ->and($histories->every(fn ($history) => $history->closed_period && $history->affected_competence->toDateString() === '2026-10-01'))->toBeTrue()
            ->and($histories->first()->description)->toBe('Registro em competência fechada (10/2026).');
    });

    test('the closed period is checked again right before writing', function () {
        BenefitPeriod::factory()->forCompetence('2026-10')->create();
        app()->instance(PointJustificationValidator::class, new class extends PointJustificationValidator
        {
            public function validate(Employee $employee, string $date, array $input, User $user, bool $checkRequestUuid = true, ?int $keptReasonId = null): array
            {
                $result = parent::validate($employee, $date, $input, $user, $checkRequestUuid, $keptReasonId);
                BenefitPeriod::query()->update(['status' => 'closed']);

                return $result;
            }
        });

        expect(($this->errors)(($this->punch)('forgotten_punch', '07:30', 'entry')))->toHaveKey('occurrence_date')
            ->and(PointJustification::query()->count())->toBe(0)
            ->and(Point::query()->count())->toBe(0);
    });
});

describe('integrity', function () {
    test('imported and protected punches stay exactly the same', function () {
        $imported = Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => '07:30:00', 'rep_serial' => '00004004330000001', 'nsr' => 10]);
        $retained = Point::factory()->forEmployee($this->employee)->on('2026-09-15')->manual()->create(['time' => '12:00:00']);
        AfdHistoricalNsr::factory()->manualRetained($retained->id)->create();
        $annotated = Point::factory()->forEmployee($this->employee)->on('2026-09-15')->create(['time' => '13:00:00', 'rep_serial' => '00004004330000001', 'nsr' => 11]);
        AfdPointDecision::factory()->create(['point_id' => $annotated->id]);
        $before = ($this->pointsSnapshot)();

        ($this->register)(($this->punch)('forgotten_punch', '17:30', 'exit'));
        ($this->errors)(($this->punch)('clock_failure', '07:30', 'entry'));
        ($this->register)(($this->interval)('08:00', '09:00'));

        $after = collect(($this->pointsSnapshot)())->keyBy('id');

        foreach ($before as $row) {
            expect($after[$row['id']])->toBe($row);
        }

        expect($after)->toHaveCount(count($before) + 1);
    });

    test('no benefit flow is touched', function () {
        $period = BenefitPeriod::factory()->forCompetence('2026-10')->create();
        $before = $period->fresh()->getAttributes();

        ($this->register)(($this->punch)('forgotten_punch', '07:30', 'entry'));
        ($this->register)(($this->fullDay)(), '2026-09-16');

        expect(BenefitAdjustment::query()->count())->toBe(0)
            ->and(BenefitSuggestionRun::query()->count())->toBe(0)
            ->and(BenefitPeriod::query()->count())->toBe(1)
            ->and($period->fresh()->getAttributes())->toBe($before);
    });

    test('the hour bank and inactive reasons are still refused', function () {
        ($this->reason)('justified_delay')->update(['active' => false]);

        expect(($this->errors)(($this->interval)('08:00', '09:00')))->toHaveKey('reason_id')
            ->and(($this->errors)(($this->fullDay)(['treatment' => 'hour_bank'])))->toHaveKey('treatment');
    });
});
