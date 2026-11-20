<?php

use App\Enums\JustificationHistoryAction;
use App\Enums\JustificationTreatment;
use App\Enums\PunchKind;
use App\Models\Employee;
use App\Models\Point;
use App\Models\PointJustification;
use App\Models\PointJustificationHistory;
use App\Models\PointJustificationReason;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('the justification tables have the expected columns', function (string $table, array $columns) {
    expect(Schema::hasColumns($table, $columns))->toBeTrue();
})->with([
    'reasons' => ['point_justification_reasons', ['id', 'code', 'name', 'category', 'description', 'time_mode', 'requires_document', 'default_treatment', 'allowed_treatments', 'creates_manual_point', 'active', 'sort_order', 'created_at', 'updated_at']],
    'justifications' => ['point_justifications', ['id', 'employee_id', 'reason_id', 'occurrence_date', 'start_time', 'end_time', 'ends_next_day', 'duration_minutes', 'punch_kind', 'treatment', 'allowed_minutes', 'observation', 'document_path', 'document_original_name', 'document_mime_type', 'document_size', 'document_sha256', 'manual_point_id', 'request_uuid', 'version', 'created_by', 'updated_by', 'canceled_at', 'canceled_by', 'cancellation_reason', 'created_at', 'updated_at']],
    'histories' => ['point_justification_histories', ['id', 'point_justification_id', 'action', 'old_values', 'new_values', 'description', 'manual_point_id', 'affected_competence', 'closed_period', 'performed_by', 'performed_by_name', 'created_at']],
    'users' => ['users', ['permissions']],
]);

test('a justification stores its fields with the casts and relations', function () {
    $employee = Employee::factory()->create();
    $point = Point::factory()->forEmployee($employee)->on('2026-09-15')->manual()->create(['time' => '07:30:00']);
    $reason = PointJustificationReason::factory()->forgottenPunch()->create();

    $justification = PointJustification::factory()->for($employee)->create([
        'reason_id' => $reason->id,
        'occurrence_date' => '2026-09-15',
        'start_time' => '07:30:00',
        'punch_kind' => PunchKind::Entry,
        'treatment' => JustificationTreatment::NoAllowance,
        'manual_point_id' => $point->id,
    ])->fresh();

    expect($justification->occurrence_date->toDateString())->toBe('2026-09-15')
        ->and(DB::table('point_justifications')->value('occurrence_date'))->toBe('2026-09-15')
        ->and($justification->punch_kind)->toBe(PunchKind::Entry)
        ->and($justification->treatment)->toBe(JustificationTreatment::NoAllowance)
        ->and($justification->version)->toBe(1)
        ->and($justification->isCanceled())->toBeFalse()
        ->and($justification->employee->is($employee))->toBeTrue()
        ->and($justification->reason->is($reason))->toBeTrue()
        ->and($justification->manualPoint->is($point))->toBeTrue()
        ->and($point->justification->is($justification))->toBeTrue()
        ->and($employee->pointJustifications)->toHaveCount(1);
});

test('the active scope leaves canceled justifications out', function () {
    $active = PointJustification::factory()->create();
    PointJustification::factory()->canceled()->create();

    expect(PointJustification::query()->active()->pluck('id')->all())->toBe([$active->id]);
});

test('the same request cannot be stored twice', function () {
    $justification = PointJustification::factory()->create();

    expect(fn () => PointJustification::factory()->create(['request_uuid' => $justification->request_uuid]))
        ->toThrow(QueryException::class);

    expect(PointJustification::query()->count())->toBe(1);
});

test('a manual punch belongs to at most one justification', function () {
    $point = Point::factory()->manual()->create();
    PointJustification::factory()->create(['manual_point_id' => $point->id]);

    expect(fn () => PointJustification::factory()->create(['manual_point_id' => $point->id]))
        ->toThrow(QueryException::class);
});

test('removing the linked punch at database level keeps the justification and clears the link', function () {
    $point = Point::factory()->manual()->create();
    $justification = PointJustification::factory()->create(['manual_point_id' => $point->id]);

    DB::table('points')->where('id', $point->id)->delete();

    expect($justification->fresh()->manual_point_id)->toBeNull();
});

test('the Eloquent model refuses to delete a punch linked to an active justification', function () {
    $point = Point::factory()->manual()->create();
    $justification = PointJustification::factory()->create(['manual_point_id' => $point->id]);

    expect(fn () => $point->delete())->toThrow(DomainException::class, Point::LINKED_TO_JUSTIFICATION_MESSAGE)
        ->and(Point::query()->whereKey($point->id)->exists())->toBeTrue()
        ->and($justification->fresh()->manual_point_id)->toBe($point->id);
});

test('a punch linked only to a canceled justification can be deleted', function () {
    $point = Point::factory()->manual()->create();
    PointJustification::factory()->canceled()->create(['manual_point_id' => $point->id]);

    $point->delete();

    expect(Point::query()->whereKey($point->id)->exists())->toBeFalse();
});

test('a justification cannot be deleted', function () {
    $justification = PointJustification::factory()->create();

    expect(fn () => $justification->delete())->toThrow(LogicException::class);

    $this->assertModelExists($justification);
});

test('an employee or reason with justifications cannot be removed from the database', function () {
    $justification = PointJustification::factory()->create();

    expect(fn () => DB::table('employees')->where('id', $justification->employee_id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('point_justification_reasons')->where('id', $justification->reason_id)->delete())->toThrow(QueryException::class);
});

test('the history is append-only', function () {
    $history = PointJustificationHistory::factory()->create();

    expect(fn () => $history->update(['description' => 'alterado']))->toThrow(LogicException::class)
        ->and(fn () => $history->delete())->toThrow(LogicException::class);

    expect($history->fresh()->description)->toBeNull();
});

test('the history keeps the performer name when the user is removed', function () {
    $user = User::factory()->create(['name' => 'Analista RH']);
    $history = PointJustificationHistory::factory()->create([
        'performed_by' => $user->id,
        'action' => JustificationHistoryAction::Canceled,
        'old_values' => ['canceled_at' => null],
        'new_values' => ['canceled_at' => '2026-09-16 10:00:00'],
        'affected_competence' => '2026-10-01',
        'closed_period' => true,
    ]);

    DB::table('users')->where('id', $user->id)->delete();
    $history = $history->fresh();

    expect($history->performed_by)->toBeNull()
        ->and($history->performed_by_name)->toBe('Analista RH')
        ->and($history->action)->toBe(JustificationHistoryAction::Canceled)
        ->and($history->old_values)->toBe(['canceled_at' => null])
        ->and($history->affected_competence->toDateString())->toBe('2026-10-01')
        ->and($history->closed_period)->toBeTrue()
        ->and($history->justification->histories)->toHaveCount(1);
});
