<?php

use App\Enums\BenefitPeriodStatus;
use App\Enums\Permission;
use App\Enums\TimesheetSnapshotSource;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Point;
use App\Models\PointJustificationReason;
use App\Models\TimesheetSnapshot;
use App\Models\User;
use App\Services\BenefitPeriodWorkflow;
use App\Services\PointJustificationService;
use App\Services\TimesheetSnapshotHistory;
use Carbon\CarbonImmutable;
use Database\Seeders\PointJustificationReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * F6.2d: seleção do snapshot de referência e aviso de divergência, somente leitura.
 * Competência 10/2026: janela de 01/09/2026 a 30/09/2026.
 */
beforeEach(function () {
    $this->seed(PointJustificationReasonSeeder::class);
    Holiday::factory()->create(['date' => '2026-09-07', 'description' => 'Independência']);
    Holiday::flushCachedHolidays();

    $this->user = User::factory()->create(['name' => 'Analista RH']);
    $this->user->grantPermission(Permission::Hr);
    $this->user->grantPermission(Permission::HrClosedPeriod);
    $this->user->refresh();
    $this->actingAs($this->user);

    $this->workflow = app(BenefitPeriodWorkflow::class);
    $this->service = app(PointJustificationService::class);
    $this->history = app(TimesheetSnapshotHistory::class);

    $this->punches = function (Employee $employee, string $date, array $times = ['07:30', '12:00', '13:00', '17:30']): void {
        foreach ($times as $time) {
            Point::factory()->forEmployee($employee)->on($date)->create(['time' => $time.':00']);
        }
    };

    $this->ana = Employee::factory()->create(['name' => 'Ana Souza']);
    $this->bruno = Employee::factory()->create(['name' => 'Bruno Lima']);
    ($this->punches)($this->ana, '2026-09-15');

    $this->justify = fn (Employee $employee, string $date, string $start, string $end) => $this->service->register($employee, $date, [
        'request_uuid' => (string) Str::uuid(),
        'reason_id' => PointJustificationReason::query()->where('code', 'justified_delay')->sole()->id,
        'treatment' => 'full_allowance',
        'start_time' => $start,
        'end_time' => $end,
        'ends_next_day' => false,
    ], $this->user)['justification'];

    $this->period = BenefitPeriod::factory()->forCompetence('2026-10')->create();
    $this->close = function (): void {
        $this->workflow->calculate($this->period);
        $this->workflow->close($this->period);
    };

    $this->version = fn (Employee $employee, string $date, int $sequence) => TimesheetSnapshot::query()
        ->where('employee_id', $employee->id)->where('date', $date)->where('sequence', $sequence)->sole();
    $this->rows = fn () => DB::table('timesheet_snapshots')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
});

describe('reference selection', function () {
    test('an open competence has no reference and no notice, even with snapshots of an earlier closing', function () {
        expect($this->history->reference($this->ana, '2026-09-15'))->toMatchArray(['state' => 'open', 'snapshot' => null]);

        ($this->close)();
        $this->workflow->reopen($this->period, 'Correção');
        ($this->punches)($this->ana, '2026-09-15', ['18:30']);

        expect($this->history->reference($this->ana, '2026-09-15'))->toMatchArray(['state' => 'open', 'snapshot' => null])
            ->and($this->history->notice($this->ana, '2026-09-15'))->toBeNull();
    });

    test('right after the closing the reference is the closure baseline and nothing diverges', function () {
        ($this->close)();

        $reference = $this->history->reference($this->ana, '2026-09-15');

        expect($reference['state'])->toBe('reference')
            ->and($reference['snapshot']->is(($this->version)($this->ana, '2026-09-15', 1)))->toBeTrue()
            ->and($reference['snapshot']->source)->toBe(TimesheetSnapshotSource::ClosureBaseline)
            ->and($reference['stale'])->toBeFalse()
            ->and($this->history->notice($this->ana, '2026-09-15'))->toBeNull();
    });

    test('after an authorized change the reference is the after-change snapshot, never the before-change one', function () {
        ($this->close)();
        ($this->justify)($this->ana, '2026-09-15', '07:30', '07:40');

        $reference = $this->history->reference($this->ana, '2026-09-15');
        $versions = $this->history->versions($this->ana, '2026-09-15');

        expect($versions->map(fn ($snapshot) => [$snapshot->sequence, $snapshot->source])->all())->toBe([
            [1, TimesheetSnapshotSource::ClosureBaseline],
            [2, TimesheetSnapshotSource::BeforeChange],
            [3, TimesheetSnapshotSource::AfterChange],
        ])
            ->and($reference['snapshot']->sequence)->toBe(3)
            ->and($reference['snapshot']->source)->toBe(TimesheetSnapshotSource::AfterChange)
            ->and($this->history->notice($this->ana, '2026-09-15'))->toBeNull();
    });

    test('after reopening and closing again the reference is the new closing, not the earlier versions', function () {
        ($this->close)();
        ($this->justify)($this->ana, '2026-09-15', '07:30', '07:40');
        $this->workflow->reopen($this->period, 'Correção');
        ($this->punches)($this->ana, '2026-09-15', ['18:30']);
        ($this->close)();

        $reference = $this->history->reference($this->ana, '2026-09-15');

        expect($reference['snapshot']->sequence)->toBe(4)
            ->and($reference['snapshot']->source)->toBe(TimesheetSnapshotSource::ClosureBaseline)
            ->and($reference['snapshot']->reason)->toBe('Fechamento nº 2 da competência 10/2026.')
            ->and($reference['stale'])->toBeFalse()
            ->and($this->history->notice($this->ana, '2026-09-15'))->toBeNull();
    });

    test('a competence closed before the deployment has no reference until the late baseline, which is identified as such', function () {
        BenefitPeriod::query()->whereKey($this->period->id)->update(['status' => BenefitPeriodStatus::Closed->value, 'closed_at' => now()]);

        expect($this->history->reference($this->ana, '2026-09-15'))->toMatchArray(['state' => 'missing', 'snapshot' => null])
            ->and($this->history->notice($this->ana, '2026-09-15'))->toBeNull();

        ($this->justify)($this->ana, '2026-09-15', '07:30', '07:40');
        $late = ($this->version)($this->ana, '2026-09-15', 1);
        $described = TimesheetSnapshotHistory::describe($late);

        expect($late->source)->toBe(TimesheetSnapshotSource::LateBaseline)
            ->and($this->history->reference($this->ana, '2026-09-15')['snapshot']->sequence)->toBe(3)
            ->and($described['source_label'])->toBe('Ponto de partida tardio (não é o fechamento original)')
            ->and($described['origin_note'])->toContain('não é o resultado do fechamento original')
            ->and($described['origin_note'])->not->toContain('Gravado na transação do fechamento')
            ->and($described['created_by_name'])->toBe('Analista RH');
    });

    test('a baseline from before the last closing is flagged as stale', function () {
        ($this->close)();
        $this->workflow->reopen($this->period, 'Correção');
        $this->bruno->update(['recision_date' => '2026-07-31 00:00:00']);
        ($this->close)();

        $bruno = $this->history->reference($this->bruno, '2026-09-15');
        $ana = $this->history->reference($this->ana, '2026-09-15');

        expect($bruno['snapshot']->sequence)->toBe(1)
            ->and($bruno['stale'])->toBeTrue()
            ->and($ana['snapshot']->sequence)->toBe(2)
            ->and($ana['stale'])->toBeFalse();
    });
});

describe('divergence notice', function () {
    test('a change outside the justifications is reported against the reference with the changed fields', function () {
        ($this->close)();
        $before = ($this->rows)();

        ($this->punches)($this->ana, '2026-09-15', ['18:30']);
        $notice = $this->history->notice($this->ana, '2026-09-15');

        expect($notice['level'])->toBe('warning')
            ->and($notice['title'])->toBe('Espelho atual diverge do registro histórico')
            ->and($notice['message'])->toContain('Fechamento da competência (original), versão 1, registrado em')
            ->and($notice['message'])->toContain('por Analista RH')
            ->and($notice['message'])->toContain('não têm versões intermediárias preservadas')
            ->and($notice['fields'])->toBe(['Atraso', 'Ocorrência', 'Quantidade de marcações', 'Conferência', 'Abono'])
            ->and($notice['reference']['id'])->toBe(($this->version)($this->ana, '2026-09-15', 1)->id)
            ->and(($this->rows)())->toBe($before);
    });

    test('a change only in the inputs is informational', function () {
        ($this->close)();
        config(['timesheet.entry_tolerance_minutes' => 25]);

        $notice = $this->history->notice($this->ana, '2026-09-05');

        expect($notice['level'])->toBe('info')
            ->and($notice['message'])->toContain('o resultado apresentado é o mesmo')
            ->and($this->history->printNotices($this->ana, CarbonImmutable::parse('2026-09-05'), CarbonImmutable::parse('2026-09-05')))->toBe([]);
    });

    test('a tampered reference is reported as an integrity failure, not as divergence, without falling back to an older version', function () {
        ($this->close)();
        ($this->justify)($this->ana, '2026-09-15', '07:30', '07:40');
        $after = ($this->version)($this->ana, '2026-09-15', 3);

        DB::table('timesheet_snapshots')->where('id', $after->id)->update(['result' => json_encode(['entrada' => '06:00'])]);

        $reference = $this->history->reference($this->ana, '2026-09-15');
        $notice = $this->history->notice($this->ana, '2026-09-15');

        expect($reference['state'])->toBe('integrity')
            ->and($reference['snapshot']->id)->toBe($after->id)
            ->and($notice['level'])->toBe('danger')
            ->and($notice['title'])->toBe('Registro histórico com falha de integridade')
            ->and($notice['message'])->toContain('result_hash')
            ->and($notice['fields'])->toBe([]);
    });

    test('the print notices list only the days whose result differs', function () {
        ($this->close)();
        ($this->punches)($this->ana, '2026-09-15', ['18:30']);

        $notices = $this->history->printNotices($this->ana, CarbonImmutable::parse('2026-08-26'), CarbonImmutable::parse('2026-09-25'));

        expect($notices)->toHaveCount(1)
            ->and($notices[0]['date'])->toBe('15/09/2026')
            ->and($notices[0]['level'])->toBe('warning')
            ->and($notices[0]['message'])->toContain('fechamento da competência (original) (versão 1');
    });

    test('consulting references and notices writes nothing', function () {
        ($this->close)();
        ($this->justify)($this->ana, '2026-09-15', '07:30', '07:40');
        ($this->punches)($this->ana, '2026-09-16', ['18:30']);
        $before = ($this->rows)();

        $this->history->versions($this->ana, '2026-09-15');
        $this->history->notice($this->ana, '2026-09-15');
        $this->history->notice($this->ana, '2026-09-16');
        $this->history->printNotices($this->ana, CarbonImmutable::parse('2026-08-26'), CarbonImmutable::parse('2026-10-25'));

        expect(($this->rows)())->toBe($before);
    });
});
