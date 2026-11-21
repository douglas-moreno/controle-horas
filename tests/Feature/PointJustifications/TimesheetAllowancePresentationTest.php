<?php

use App\Enums\JustificationTimeMode;
use App\Enums\Permission;
use App\Livewire\EmployeeHorasExtras;
use App\Models\Employee;
use App\Models\Point;
use App\Models\PointJustification;
use App\Models\PointJustificationReason;
use App\Models\User;
use App\Services\AlternativeComparison;
use App\Services\OvertimeCalculator;
use App\Services\PointJustificationService;
use App\Services\TimesheetMirror;
use Carbon\CarbonImmutable;
use Database\Seeders\PointJustificationReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * F5.2: abono na regra alternativa (D8), na tela de horas extras e na impressão (D9).
 * Datas: 2026-07-07 terça, 2026-07-08 quarta (jornada 07:30–12:00 e 13:00–17:30,
 * tolerância de entrada de 5 minutos).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['timesheet.entry_tolerance_minutes' => 5]);
    $this->actingAs(User::factory()->create());

    $this->employee = Employee::factory()->create(['name' => 'Ana Abonada', 'recision_date' => null]);
    $this->delayReason = PointJustificationReason::factory()->create(['name' => 'Consulta médica', 'time_mode' => JustificationTimeMode::Interval]);

    $this->punches = function (string $date, array $times, ?Employee $employee = null): void {
        foreach ($times as $time) {
            Point::factory()->forEmployee($employee ?? $this->employee)->on($date)->create(['time' => $time.':00']);
        }
    };

    $this->interval = fn (string $date, string $start, string $end, string $treatment = 'full_allowance', ?int $minutes = null, array $extra = []) => PointJustification::factory()
        ->for($extra['employee'] ?? $this->employee)
        ->between($date, $start, $end)
        ->create(['reason_id' => $this->delayReason->id, 'treatment' => $treatment, 'allowed_minutes' => $minutes, ...collect($extra)->except('employee')->all()]);

    $this->screen = fn (string $start = '2026-07-08', string $end = '2026-07-08') => Livewire::withQueryParams(['startDate' => $start, 'endDate' => $end])
        ->test(EmployeeHorasExtras::class, ['employee' => $this->employee->id]);

    $this->print = fn (string $start = '2026-07-08', string $end = '2026-07-08') => $this->get(route('reports.timesheet.print', ['start' => $start, 'end' => $end, 'employees' => [$this->employee->id]]));

    $this->comparison = function (string $start = '2026-07-06', string $end = '2026-07-12'): array {
        config(['timesheet.alternative_rule.enabled' => true]);

        return app(AlternativeComparison::class)->compare(CarbonImmutable::parse($start), CarbonImmutable::parse($end), 'test');
    };
});

describe('alternative rule (D8)', function () {
    test('full allowance neutralizes the alternative delay and leaves no artificial divergence', function () {
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30']);
        ($this->interval)('2026-07-08', '07:30', '08:30');

        $comparison = ($this->comparison)();
        $row = collect($comparison['days'])->firstWhere('date', '2026-07-08');

        expect($row['official_delay_minutes'])->toBe(0)
            ->and($row['alternative_delay_minutes'])->toBe(0)
            ->and($row['divergent'])->toBeFalse()
            ->and($comparison['totals']['divergent_days'])->toBe(0);
    });

    test('partial allowance uses the same rule over the alternative delay', function () {
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30']);
        ($this->interval)('2026-07-08', '07:30', '08:30', 'partial_allowance', 20);

        $day = collect(app(TimesheetMirror::class)->forEmployee($this->employee, CarbonImmutable::parse('2026-07-08'), CarbonImmutable::parse('2026-07-08'))['days'])->sole();

        config(['timesheet.alternative_rule.enabled' => true]);
        $alternative = collect(app(TimesheetMirror::class)->forEmployee($this->employee, CarbonImmutable::parse('2026-07-08'), CarbonImmutable::parse('2026-07-08'))['days'])->sole()['alternative'];

        expect($day['delay_before_allowance_minutes'])->toBe(55)
            ->and($day['allowed_delay_minutes'])->toBe(20)
            ->and($day['delay_minutes'])->toBe(35)
            ->and($alternative['delay_before_allowance_minutes'])->toBe(60)
            ->and($alternative['allowed_delay_minutes'])->toBe(20)
            ->and($alternative['delay_minutes'])->toBe(40);
    });

    test('a justified full day absence is not a divergence', function () {
        ($this->punches)('2026-07-07', ['07:30', '12:00', '13:00', '17:30']);
        PointJustification::factory()->for($this->employee)->create([
            'reason_id' => PointJustificationReason::factory()->create(['name' => 'Licença', 'time_mode' => JustificationTimeMode::FullDay])->id,
            'occurrence_date' => '2026-07-08',
        ]);

        $row = collect(($this->comparison)()['days'])->firstWhere('date', '2026-07-08');

        expect($row['official_needs_review'])->toBeFalse()
            ->and($row['alternative_needs_review'])->toBeFalse()
            ->and($row['divergent'])->toBeFalse();
    });

    test('without justifications the comparison is the same as before', function () {
        ($this->punches)('2026-07-08', ['08:27', '14:16', '16:04', '17:28']);
        ($this->punches)('2026-07-09', ['07:26', '17:30', '17:30']);
        ($this->punches)('2026-07-10', ['07:40', '12:00', '13:00', '16:00']);

        $comparison = ($this->comparison)();
        $mirror = app(TimesheetMirror::class)->build($this->employee->points()->orderBy('date')->orderBy('time')->get(), CarbonImmutable::parse('2026-07-06'), CarbonImmutable::parse('2026-07-12'));

        expect(collect($comparison['days'])->pluck('official_delay_minutes')->all())->toBe(collect($mirror['days'])->pluck('delay_minutes')->all())
            ->and(collect($comparison['days'])->pluck('alternative_delay_minutes')->all())->toBe(collect($mirror['days'])->pluck('alternative.delay_minutes')->all())
            ->and(collect($mirror['days'])->every(fn (array $day) => $day['alternative']['allowed_delay_minutes'] === ($day['alternative']['delay_minutes'] === null ? null : 0)))->toBeTrue();
    });

    test('canceled justifications and other employees are ignored by the comparison', function () {
        $other = Employee::factory()->create(['recision_date' => null]);
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30']);
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30'], $other);
        ($this->interval)('2026-07-08', '07:30', '08:30', 'full_allowance', null, ['canceled_at' => now(), 'canceled_by' => User::factory(), 'cancellation_reason' => 'Engano']);
        ($this->interval)('2026-07-08', '07:30', '08:30', 'full_allowance', null, ['employee' => $other]);

        $rows = collect(($this->comparison)()['days'])->where('date', '2026-07-08')->values();
        $mine = $rows->firstWhere('official_delay_minutes', 55);
        $theirs = $rows->firstWhere('official_delay_minutes', 0);

        expect($mine['alternative_delay_minutes'])->toBe(60)
            ->and($theirs['alternative_delay_minutes'])->toBe(0);
    });

    test('the AFD decision preview keeps using the plain build, without justifications', function () {
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30']);
        ($this->interval)('2026-07-08', '07:30', '08:30');
        PointJustification::factory()->for($this->employee)->create(['reason_id' => $this->delayReason->id, 'occurrence_date' => '2026-07-09']);
        $day = CarbonImmutable::parse('2026-07-08');

        $plain = app(TimesheetMirror::class)->build($this->employee->points()->get(), $day, $day->addDay())['days'];

        expect($plain[0]['delay_minutes'])->toBe(55)
            ->and($plain[0]['allowed_delay_minutes'])->toBe(0)
            ->and($plain[0]['justifications'])->toBe([])
            ->and($plain[1]['needs_review'])->toBeTrue()
            ->and($plain[1]['justified'])->toBeFalse();
    });
});

describe('overtime screen and print (D9)', function () {
    test('the screen shows the gross, allowed and net delay with reason and period', function () {
        ($this->punches)('2026-07-08', ['07:50', '12:00', '13:00', '17:30']);
        ($this->interval)('2026-07-08', '07:30', '08:00');

        ($this->screen)()
            ->assertSet('totalDelayBeforeAllowanceMinutes', 15)
            ->assertSet('totalAllowedDelayMinutes', 15)
            ->assertSet('totalDelayMinutes', 0)
            ->assertSeeInOrder(['Atraso líquido', '00:00', 'Atraso bruto: 00:15', 'Abonado: 00:15'])
            ->assertSeeInOrder(['Atraso bruto: 00:15', 'Abonado: 00:15', 'Atraso líquido: 00:00'])
            ->assertSee('Abonado — Consulta médica, 07:30–08:00: 00:15');
    });

    test('the print shows the same net delay, allowance, reason and period as the screen', function () {
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:00']);
        ($this->interval)('2026-07-08', '07:30', '08:00');
        ($this->interval)('2026-07-08', '17:00', '17:30', 'partial_allowance', 10);

        $screen = ($this->screen)();
        $day = collect($screen->get('days'))->sole();

        expect($day['delay_before_allowance_minutes'])->toBe(85)
            ->and($day['allowed_delay_minutes'])->toBe(40)
            ->and($day['delay_minutes'])->toBe(45)
            ->and($day['justification_notes'])->toBe([
                'Abonado — Consulta médica, 07:30–08:00: 00:30',
                'Abonado — Consulta médica, 17:00–17:30: 00:10',
            ]);

        $screen->assertSee($day['justification_notes'][0])->assertSee($day['justification_notes'][1])->assertSee('Atraso líquido: 00:45');

        ($this->print)()
            ->assertOk()
            ->assertViewHas('timesheets', fn ($timesheets) => Arr::except($timesheets->sole()['days'][0], ['alternative', 'day_type', 'status']) == Arr::except($day, ['day_type', 'status'])
                && $timesheets->sole()['totals']['delay_minutes'] === $screen->get('totalDelayMinutes')
                && $timesheets->sole()['totals']['allowed_delay_minutes'] === $screen->get('totalAllowedDelayMinutes'))
            ->assertSeeInOrder(['00:45', 'bruto 01:25', 'abon. 00:40'], false)
            ->assertSee('Abonado — Consulta médica, 07:30–08:00: 00:30 · Abonado — Consulta médica, 17:00–17:30: 00:10')
            ->assertSee('Atraso bruto 01:25 · abonado 00:40 · líquido 00:45')
            ->assertSee('atraso bruto menos os minutos abonados');
    });

    test('a justification without allowance is shown without changing the delay', function () {
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30']);
        ($this->interval)('2026-07-08', '07:30', '08:30', 'no_allowance');

        ($this->screen)()
            ->assertSet('totalDelayMinutes', 55)
            ->assertSet('totalAllowedDelayMinutes', 0)
            ->assertDontSee('Atraso bruto')
            ->assertSee('Não abonado — Consulta médica, 07:30–08:30');

        ($this->print)()->assertSee('Não abonado — Consulta médica, 07:30–08:30')->assertDontSee('abon. ');
    });

    test('a canceled justification does not appear on the screen nor in the print', function () {
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30']);
        ($this->interval)('2026-07-08', '07:30', '08:30', 'full_allowance', null, ['canceled_at' => now(), 'canceled_by' => User::factory(), 'cancellation_reason' => 'Engano']);

        ($this->screen)()->assertSet('totalDelayMinutes', 55)->assertDontSee('Consulta médica');
        ($this->print)()->assertDontSee('Consulta médica')->assertDontSee('abon. ');
    });

    test('changing the justification is reflected on the screen and in the print', function () {
        $this->seed(PointJustificationReasonSeeder::class);
        $hr = User::factory()->create();
        $hr->grantPermission(Permission::Hr);
        $hr->refresh();
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '17:30']);
        $reasonId = PointJustificationReason::query()->where('code', 'justified_delay')->value('id');
        $service = app(PointJustificationService::class);
        $justification = $service->register($this->employee, '2026-07-08', ['request_uuid' => (string) Str::uuid(), 'reason_id' => $reasonId, 'treatment' => 'full_allowance', 'start_time' => '07:30', 'end_time' => '08:30'], $hr)['justification'];

        ($this->screen)()->assertSet('totalDelayMinutes', 0)->assertSee('Abonado — Atraso justificado, 07:30–08:30: 00:55');

        $service->update($justification, ['reason_id' => $reasonId, 'treatment' => 'partial_allowance', 'allowed_minutes' => 15, 'start_time' => '07:30', 'end_time' => '08:30'], 1, $hr);

        ($this->screen)()->assertSet('totalDelayMinutes', 40)->assertSee('Abonado — Atraso justificado, 07:30–08:30: 00:15');
        ($this->print)()->assertSee('Abonado — Atraso justificado, 07:30–08:30: 00:15')->assertSee('líquido 00:40');

        $service->cancel($justification, 'Engano', 2, $hr);

        ($this->screen)()->assertSet('totalDelayMinutes', 55)->assertDontSee('Atraso justificado, 07:30');
        ($this->print)()->assertDontSee('Atraso justificado, 07:30');
    });

    test('a justification crossing midnight is shown on the next day with its period', function () {
        ($this->punches)('2026-07-08', ['09:00', '12:00', '13:00', '17:30']);
        ($this->interval)('2026-07-07', '22:00', '08:00', 'full_allowance', null, ['end_time' => '08:00:00', 'ends_next_day' => true, 'duration_minutes' => 600]);

        ($this->screen)()->assertSet('totalAllowedDelayMinutes', 30)->assertSee('Abonado — Consulta médica, 22:00–08:00 (dia seguinte): 00:30');
        ($this->print)()->assertSee('Abonado — Consulta médica, 22:00–08:00 (dia seguinte): 00:30');
    });

    test('a justified full day absence shows the reason as the occurrence on both', function () {
        PointJustification::factory()->for($this->employee)->create([
            'reason_id' => PointJustificationReason::factory()->create(['name' => 'Licença nojo', 'time_mode' => JustificationTimeMode::FullDay])->id,
            'occurrence_date' => '2026-07-08',
        ]);

        ($this->screen)()->assertSet('reviewDays', 0)->assertSee('Justificado — Licença nojo')->assertSee('Justificativa — Licença nojo, dia inteiro');
        ($this->print)()->assertSee('Justificado — Licença nojo')->assertDontSee('para conferência');
    });

    test('the allowance never changes the overtime', function () {
        ($this->punches)('2026-07-08', ['08:30', '12:00', '13:00', '19:00']);
        ($this->interval)('2026-07-08', '07:30', '08:30');

        $day = collect(($this->screen)()->get('days'))->sole();
        $expected = app(OvertimeCalculator::class)->minutes(CarbonImmutable::parse('2026-07-08'), ['entrada' => '08:30', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'saida' => '19:00']);

        expect($day['overtime_minutes'])->toBe($expected)
            ->and($day['overtime_minutes'])->toBe(30)
            ->and($day['allowed_delay_minutes'])->toBe(55)
            ->and($day['delay_minutes'])->toBe(0);
    });
});
