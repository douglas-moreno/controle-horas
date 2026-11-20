<?php

use App\Enums\JustificationTimeMode;
use App\Enums\JustificationTreatment;
use App\Enums\Permission;
use App\Enums\PunchKind;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\PointJustification;
use App\Models\PointJustificationReason;
use App\Models\User;
use App\Services\PointJustificationValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->employee = Employee::factory()->create();
    $this->user = User::factory()->create();
    $this->user->grantPermission(Permission::Hr);
    $this->validator = app(PointJustificationValidator::class);

    $this->reason = fn (JustificationTimeMode $mode, array $attributes = []) => PointJustificationReason::factory()->create(['time_mode' => $mode, ...$attributes]);

    $this->errors = function (array $input, string $date = '2026-09-15', ?User $user = null): array {
        try {
            $this->validator->validate($this->employee, $date, ['request_uuid' => (string) Str::uuid(), ...$input], $user ?? $this->user);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    };

    $this->valid = fn (array $input, string $date = '2026-09-15') => $this->validator->validate($this->employee, $date, ['request_uuid' => (string) Str::uuid(), ...$input], $this->user);
});

test('a full day uses the scheduled workday as duration and drops the times', function (string $date, ?int $minutes) {
    $reason = ($this->reason)(JustificationTimeMode::FullDay);

    $result = ($this->valid)(['reason_id' => $reason->id, 'treatment' => 'full_allowance', 'start_time' => '08:00', 'end_time' => '09:00', 'punch_kind' => 'entry'], $date);

    expect($result['reason']->is($reason))->toBeTrue()
        ->and($result['attributes'])->toMatchArray([
            'employee_id' => $this->employee->id,
            'occurrence_date' => $date,
            'start_time' => null,
            'end_time' => null,
            'ends_next_day' => false,
            'duration_minutes' => $minutes,
            'punch_kind' => null,
            'treatment' => JustificationTreatment::FullAllowance,
            'allowed_minutes' => null,
            'observation' => null,
        ]);
})->with([
    'monday to thursday' => ['2026-09-15', 540],
    'friday' => ['2026-09-18', 480],
    'saturday without schedule' => ['2026-09-19', null],
]);

test('an interval requires both times and computes the duration', function () {
    $reason = ($this->reason)(JustificationTimeMode::Interval);

    expect(($this->errors)(['reason_id' => $reason->id, 'treatment' => 'full_allowance']))->toHaveKeys(['start_time', 'end_time']);

    $result = ($this->valid)(['reason_id' => $reason->id, 'treatment' => 'full_allowance', 'start_time' => '07:30', 'end_time' => '09:15', 'observation' => '  Trânsito  ']);

    expect($result['attributes'])->toMatchArray(['start_time' => '07:30:00', 'end_time' => '09:15:00', 'ends_next_day' => false, 'duration_minutes' => 105, 'observation' => 'Trânsito']);
});

test('the end must follow the start unless the occurrence crosses midnight', function (array $times, ?string $error, ?int $duration) {
    $reason = ($this->reason)(JustificationTimeMode::Interval);
    $input = ['reason_id' => $reason->id, 'treatment' => 'full_allowance', ...$times];

    if ($error !== null) {
        expect(($this->errors)($input))->toHaveKey($error);

        return;
    }

    expect(($this->valid)($input)['attributes']['duration_minutes'])->toBe($duration);
})->with([
    'end before start' => [['start_time' => '10:00', 'end_time' => '09:00'], 'end_time', null],
    'end equal to start' => [['start_time' => '10:00', 'end_time' => '10:00'], 'end_time', null],
    'next day' => [['start_time' => '22:00', 'end_time' => '02:00', 'ends_next_day' => true], null, 240],
    'next day 24 hours' => [['start_time' => '22:00', 'end_time' => '22:00', 'ends_next_day' => true], null, 1440],
    'next day with end after start' => [['start_time' => '08:00', 'end_time' => '09:00', 'ends_next_day' => true], 'end_time', null],
    'invalid format' => [['start_time' => '7:3', 'end_time' => '25:00'], 'start_time', null],
]);

test('full day or interval follows the full day choice', function () {
    $reason = ($this->reason)(JustificationTimeMode::FullDayOrInterval);

    expect(($this->valid)(['reason_id' => $reason->id, 'treatment' => 'full_allowance', 'full_day' => true])['attributes']['duration_minutes'])->toBe(540)
        ->and(($this->errors)(['reason_id' => $reason->id, 'treatment' => 'full_allowance', 'full_day' => false]))->toHaveKeys(['start_time', 'end_time'])
        ->and(($this->valid)(['reason_id' => $reason->id, 'treatment' => 'full_allowance', 'full_day' => false, 'start_time' => '13:00', 'end_time' => '15:00'])['attributes']['duration_minutes'])->toBe(120);
});

test('a single punch requires the time and the punch kind and has no duration', function () {
    $reason = ($this->reason)(JustificationTimeMode::SinglePunch, ['default_treatment' => JustificationTreatment::NoAllowance]);

    expect(($this->errors)(['reason_id' => $reason->id, 'treatment' => 'no_allowance']))->toHaveKeys(['start_time', 'punch_kind'])
        ->and(($this->errors)(['reason_id' => $reason->id, 'treatment' => 'no_allowance', 'start_time' => '07:30', 'punch_kind' => 'lunch']))->toHaveKey('punch_kind');

    $result = ($this->valid)(['reason_id' => $reason->id, 'treatment' => 'no_allowance', 'start_time' => '07:30', 'end_time' => '08:00', 'punch_kind' => 'entry']);

    expect($result['attributes'])->toMatchArray(['start_time' => '07:30:00', 'end_time' => null, 'duration_minutes' => null, 'punch_kind' => PunchKind::Entry]);
});

test('the reason must exist and be active', function () {
    $inactive = PointJustificationReason::factory()->inactive()->create();

    expect(($this->errors)(['treatment' => 'full_allowance']))->toHaveKey('reason_id')
        ->and(($this->errors)(['reason_id' => $inactive->id, 'treatment' => 'full_allowance', 'full_day' => true]))->toBe(['reason_id' => ['Motivo inexistente ou inativo.']])
        ->and(($this->errors)(['reason_id' => 999999, 'treatment' => 'full_allowance']))->toHaveKey('reason_id');
});

test('unknown, unsupported or not allowed treatments are rejected', function () {
    $restricted = ($this->reason)(JustificationTimeMode::FullDay, ['allowed_treatments' => ['full_allowance', 'hour_bank']]);

    expect(($this->errors)(['reason_id' => $restricted->id, 'treatment' => 'desconto']))->toBe(['treatment' => ['Tratamento inválido.']])
        ->and(($this->errors)(['reason_id' => $restricted->id, 'treatment' => 'hour_bank']))->toBe(['treatment' => ['Tratamento indisponível nesta versão.']])
        ->and(($this->errors)(['reason_id' => $restricted->id, 'treatment' => 'no_allowance']))->toBe(['treatment' => ['Tratamento não permitido para o motivo.']])
        ->and(($this->errors)(['reason_id' => $restricted->id, 'treatment' => 'full_allowance']))->toBe([]);
});

test('partial allowance requires minutes within the duration', function (mixed $minutes, bool $valid) {
    $reason = ($this->reason)(JustificationTimeMode::Interval);
    $input = ['reason_id' => $reason->id, 'treatment' => 'partial_allowance', 'start_time' => '08:00', 'end_time' => '09:00', 'allowed_minutes' => $minutes];

    if ($valid) {
        expect(($this->valid)($input)['attributes']['allowed_minutes'])->toBe((int) $minutes);

        return;
    }

    expect(($this->errors)($input))->toHaveKey('allowed_minutes');
})->with([
    'missing' => [null, false],
    'zero' => [0, false],
    'negative' => [-5, false],
    'fraction' => ['10.5', false],
    'above the duration' => [61, false],
    'equal to the duration' => [60, true],
    'inside the duration' => ['30', true],
]);

test('partial allowance on a day without duration is limited to 24 hours', function () {
    $reason = ($this->reason)(JustificationTimeMode::FullDay);

    expect(($this->errors)(['reason_id' => $reason->id, 'treatment' => 'partial_allowance', 'allowed_minutes' => 1441], '2026-09-19'))->toHaveKey('allowed_minutes')
        ->and(($this->errors)(['reason_id' => $reason->id, 'treatment' => 'partial_allowance', 'allowed_minutes' => 541], '2026-09-15'))->toHaveKey('allowed_minutes');
});

test('minutes are discarded for treatments other than partial allowance', function () {
    $reason = ($this->reason)(JustificationTimeMode::FullDay);

    expect(($this->valid)(['reason_id' => $reason->id, 'treatment' => 'full_allowance', 'allowed_minutes' => 30])['attributes']['allowed_minutes'])->toBeNull();
});

test('the observation is limited to 1000 characters', function () {
    $reason = ($this->reason)(JustificationTimeMode::FullDay);

    expect(($this->errors)(['reason_id' => $reason->id, 'treatment' => 'full_allowance', 'observation' => str_repeat('a', 1001)]))->toHaveKey('observation')
        ->and(($this->errors)(['reason_id' => $reason->id, 'treatment' => 'full_allowance', 'observation' => str_repeat('a', 1000)]))->toBe([]);
});

test('the occurrence date must be a valid date', function () {
    $reason = ($this->reason)(JustificationTimeMode::FullDay);

    expect(($this->errors)(['reason_id' => $reason->id, 'treatment' => 'full_allowance'], '2026-02-30'))->toHaveKey('occurrence_date');
});

test('the request identifier is required, must be a uuid and cannot be reused', function () {
    $reason = ($this->reason)(JustificationTimeMode::FullDay);
    $used = PointJustification::factory()->create();

    expect(($this->errors)(['reason_id' => $reason->id, 'treatment' => 'full_allowance', 'request_uuid' => null]))->toHaveKey('request_uuid')
        ->and(($this->errors)(['reason_id' => $reason->id, 'treatment' => 'full_allowance', 'request_uuid' => 'abc']))->toHaveKey('request_uuid')
        ->and(($this->errors)(['reason_id' => $reason->id, 'treatment' => 'full_allowance', 'request_uuid' => $used->request_uuid]))->toBe(['request_uuid' => ['Esta operação já foi registrada. Abra o formulário novamente para um novo lançamento.']]);
});

test('a date in a closed benefit period requires the closed period permission', function () {
    $reason = ($this->reason)(JustificationTimeMode::FullDay);
    BenefitPeriod::factory()->forCompetence('2026-10')->closed()->create();
    $input = ['reason_id' => $reason->id, 'treatment' => 'full_allowance'];

    expect(($this->errors)($input, '2026-09-15'))->toHaveKey('occurrence_date')
        ->and(($this->errors)($input, '2026-10-15'))->toBe([]);

    $this->user->grantPermission(Permission::HrClosedPeriod);

    expect(($this->valid)($input, '2026-09-15')['closed_period']->competence->format('Y-m'))->toBe('2026-10');
});

test('open or calculated benefit periods do not require the closed period permission', function (string $state) {
    $reason = ($this->reason)(JustificationTimeMode::FullDay);
    BenefitPeriod::factory()->forCompetence('2026-10')->{$state}()->create();

    expect(($this->valid)(['reason_id' => $reason->id, 'treatment' => 'full_allowance'], '2026-09-15')['closed_period'])->toBeNull();
})->with(['calculated']);

test('validation never writes', function () {
    $reason = ($this->reason)(JustificationTimeMode::FullDay);

    ($this->valid)(['reason_id' => $reason->id, 'treatment' => 'full_allowance']);

    expect(PointJustification::query()->count())->toBe(0);
});
