<?php

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\AdjustmentStatus;
use App\Enums\BenefitPeriodStatus;
use App\Enums\BenefitType;
use App\Enums\JustificationTreatment;
use App\Enums\Permission;
use App\Livewire\Benefits\BenefitPeriodAdjustments;
use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriod;
use App\Models\BenefitSuggestionRun;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\Holiday;
use App\Models\Point;
use App\Models\PointJustification;
use App\Models\PointJustificationReason;
use App\Models\User;
use App\Services\BenefitAdjustmentRegistrar;
use App\Services\BusinessCalendar;
use App\Services\PointJustificationService;
use App\Services\TimesheetAdjustmentSuggester;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * F5.3. Competência 10/2026 → janela 01/09 a 30/09/2026 (feriado em 07/09). O colega sem
 * benefício bate ponto em todos os dias úteis e em 01/10 (horizonte 30/09), então nenhum
 * dia útil fica sem batidas importadas.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->grantPermission(Permission::Hr);
    $this->user->refresh();
    $this->actingAs($this->user);
    Holiday::factory()->create(['date' => '2026-09-07', 'description' => 'Independência']);

    $this->suggester = app(TimesheetAdjustmentSuggester::class);
    $this->registrar = app(BenefitAdjustmentRegistrar::class);
    $this->period = BenefitPeriod::factory()->forCompetence('2026-10')->create();
    $this->reason = PointJustificationReason::factory()->create(['name' => 'Atestado médico (F5.3)']);

    $this->participant = function (array $attributes = []): Employee {
        $employee = Employee::factory()->create($attributes);
        EmployeeBenefit::factory()->for($employee)->ofType(BenefitType::Vr)->between('2026-01-01')->create();

        return $employee;
    };

    $this->punch = fn (Employee $employee, string $date) => Point::factory()
        ->forEmployee($employee)->on($date)->state(['type' => 'importado'])->create();

    $this->presentOnBusinessDays = function (Employee $employee, array $except = []): void {
        app(BusinessCalendar::class)
            ->businessDays(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'))
            ->map(fn (CarbonImmutable $day) => $day->toDateString())
            ->reject(fn (string $date) => in_array($date, $except, true))
            ->each(fn (string $date) => ($this->punch)($employee, $date));
    };

    $this->colleague = Employee::factory()->create(['name' => 'Colega sem benefício']);
    ($this->presentOnBusinessDays)($this->colleague);
    ($this->punch)($this->colleague, '2026-10-01');

    $this->employee = ($this->participant)(['name' => 'Ana Souza']);

    $this->fullDay = fn (Employee $employee, string $date, JustificationTreatment $treatment = JustificationTreatment::FullAllowance, array $attributes = []) => PointJustification::factory()->create([
        'employee_id' => $employee->id,
        'reason_id' => $this->reason->id,
        'occurrence_date' => $date,
        'treatment' => $treatment,
        'allowed_minutes' => $treatment === JustificationTreatment::PartialAllowance ? 240 : null,
        'created_by' => $this->user->id,
        ...$attributes,
    ]);

    $this->absences = fn (Employee $employee) => BenefitAdjustment::query()
        ->where('employee_id', $employee->id)
        ->where('reason', AdjustmentReason::UnjustifiedAbsence)
        ->orderBy('starts_on')
        ->get()
        ->map(fn (BenefitAdjustment $adjustment) => $adjustment->starts_on->toDateString())
        ->all();

    $this->justificationConflicts = fn () => collect($this->suggester->conflicts($this->period))
        ->where('type', 'absence_with_justification')
        ->values();

    $this->snapshot = fn () => [
        'adjustments' => DB::table('benefit_adjustments')->orderBy('id')->get()->toArray(),
        'impacts' => DB::table('benefit_adjustment_impacts')->orderBy('id')->get()->toArray(),
        'status_changes' => DB::table('benefit_period_status_changes')->orderBy('id')->get()->toArray(),
        'periods' => DB::table('benefit_periods')->orderBy('id')->get()->toArray(),
    ];
});

describe('suppression', function () {
    test('a fully justified day with allowance does not suggest an absence', function (JustificationTreatment $treatment) {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15', '2026-09-16']);
        ($this->fullDay)($this->employee, '2026-09-15', $treatment);

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 1, 'skipped_justified' => 1, 'skipped_covered' => 0, 'skipped_existing' => 0])
            ->and(($this->absences)($this->employee))->toBe(['2026-09-16']);
    })->with([
        'full allowance' => JustificationTreatment::FullAllowance,
        'partial allowance' => JustificationTreatment::PartialAllowance,
    ]);

    test('a full day justification without allowance still suggests the absence', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->fullDay)($this->employee, '2026-09-15', JustificationTreatment::NoAllowance);

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 1, 'skipped_justified' => 0])
            ->and(($this->absences)($this->employee))->toBe(['2026-09-15']);
    });

    test('an interval justification does not suppress the absence', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        PointJustification::factory()->between('2026-09-15', '08:00', '17:00')->create([
            'employee_id' => $this->employee->id,
            'reason_id' => $this->reason->id,
            'created_by' => $this->user->id,
        ]);

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 1, 'skipped_justified' => 0])
            ->and(($this->absences)($this->employee))->toBe(['2026-09-15']);
    });

    test('a canceled justification does not suppress the absence', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->fullDay)($this->employee, '2026-09-15', attributes: ['canceled_at' => now(), 'canceled_by' => $this->user->id, 'cancellation_reason' => 'Engano']);

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 1, 'skipped_justified' => 0])
            ->and(($this->absences)($this->employee))->toBe(['2026-09-15']);
    });

    test('a justification of another employee has no effect', function () {
        $other = ($this->participant)(['name' => 'Bruno Lima']);
        ($this->presentOnBusinessDays)($other);
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->fullDay)($other, '2026-09-15');

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 1, 'skipped_justified' => 0])
            ->and(($this->absences)($this->employee))->toBe(['2026-09-15'])
            ->and(($this->absences)($other))->toBe([]);
    });

    test('a justification of another date has no effect', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->fullDay)($this->employee, '2026-09-14');
        ($this->fullDay)($this->employee, '2026-09-16');

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 1, 'skipped_justified' => 0])
            ->and(($this->absences)($this->employee))->toBe(['2026-09-15']);
    });

    test('a justification crossing midnight does not cover the next date', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        PointJustification::factory()->between('2026-09-14', '22:00', '06:00')->create([
            'employee_id' => $this->employee->id,
            'reason_id' => $this->reason->id,
            'ends_next_day' => true,
            'duration_minutes' => 480,
            'created_by' => $this->user->id,
        ]);

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 1, 'skipped_justified' => 0])
            ->and(($this->absences)($this->employee))->toBe(['2026-09-15'])
            ->and(($this->justificationConflicts)())->toHaveCount(0);
    });

    test('justified days are isolated per employee', function () {
        $other = ($this->participant)(['name' => 'Bruno Lima']);
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->presentOnBusinessDays)($other, except: ['2026-09-15', '2026-09-16']);
        ($this->fullDay)($this->employee, '2026-09-15');
        ($this->fullDay)($other, '2026-09-16');

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 1, 'skipped_justified' => 2])
            ->and(($this->absences)($this->employee))->toBe([])
            ->and(($this->absences)($other))->toBe(['2026-09-15']);
    });

    test('work suggestions on a justified day are not affected', function () {
        ($this->presentOnBusinessDays)($this->employee);
        ($this->punch)($this->employee, '2026-09-12');
        ($this->fullDay)($this->employee, '2026-09-12');

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 1, 'skipped_justified' => 0])
            ->and(BenefitAdjustment::sole()->reason)->toBe(AdjustmentReason::SaturdayWorked);
    });
});

describe('counters', function () {
    test('days covered by existing adjustments stay in skipped_covered, even when justified', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15', '2026-09-16']);
        BenefitAdjustment::factory()->vacation('2026-09-15', '2026-09-16', 2)->create(['benefit_period_id' => $this->period->id, 'employee_id' => $this->employee->id]);
        ($this->fullDay)($this->employee, '2026-09-15');

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 0, 'skipped_covered' => 2, 'skipped_justified' => 0]);
    });

    test('each day is counted in a single counter', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17']);
        BenefitAdjustment::factory()->vacation('2026-09-14', '2026-09-14', 1)->create(['benefit_period_id' => $this->period->id, 'employee_id' => $this->employee->id]);
        ($this->fullDay)($this->employee, '2026-09-14');
        ($this->fullDay)($this->employee, '2026-09-15');
        ($this->fullDay)($this->employee, '2026-09-16', JustificationTreatment::NoAllowance);

        $summary = $this->suggester->generate($this->period);
        $run = BenefitSuggestionRun::sole();

        expect($summary)->toMatchArray(['created' => 2, 'skipped_existing' => 0, 'skipped_covered' => 1, 'skipped_justified' => 1])
            ->and($summary['created'] + $summary['skipped_existing'] + $summary['skipped_covered'] + $summary['skipped_justified'])->toBe(4)
            ->and([$run->created, $run->skipped_existing, $run->skipped_covered, $run->skipped_justified])->toBe([2, 0, 1, 1])
            ->and(($this->absences)($this->employee))->toBe(['2026-09-16', '2026-09-17']);
    });

    test('an existing suggestion is counted as existing, not as justified', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        $this->suggester->generate($this->period);
        ($this->fullDay)($this->employee, '2026-09-15');

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 0, 'skipped_existing' => 1, 'skipped_justified' => 0]);
    });

    test('the dedupe_key still protects against duplicates after the justification is canceled', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        $justification = ($this->fullDay)($this->employee, '2026-09-15');

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 0, 'skipped_justified' => 1]);

        app(PointJustificationService::class)->cancel($justification, 'Lançada por engano', 1, $this->user);

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 1, 'skipped_justified' => 0])
            ->and($this->suggester->generate($this->period))->toMatchArray(['created' => 0, 'skipped_existing' => 1, 'skipped_justified' => 0])
            ->and(BenefitAdjustment::query()->where('dedupe_key', $this->registrar->suggestionKey($this->period, $this->employee, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse('2026-09-15')))->count())->toBe(1);
    });

    test('the compensation of the previous competence tail is not suppressed, only reported', function () {
        $origin = BenefitPeriod::factory()->forCompetence('2026-09')->closed()->create();
        BenefitSuggestionRun::factory()->for($origin)->processedUntil('2026-08-24')->create();
        ($this->presentOnBusinessDays)($this->employee);
        foreach (['2026-08-25', '2026-08-26', '2026-08-28', '2026-08-31'] as $date) {
            ($this->punch)($this->employee, $date);
            ($this->punch)($this->colleague, $date);
        }
        ($this->punch)($this->colleague, '2026-08-27');
        ($this->fullDay)($this->employee, '2026-08-27');

        expect($this->suggester->generate($this->period))->toMatchArray(['compensation_created' => 1, 'skipped_justified' => 0])
            ->and(($this->justificationConflicts)()->pluck('date')->all())->toBe(['2026-08-27']);
    });
});

describe('conflicts', function () {
    test('a suggestion created before the justification produces an informative conflict', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        $this->suggester->generate($this->period);
        $absence = BenefitAdjustment::sole();

        expect(($this->justificationConflicts)())->toHaveCount(0);

        $justification = ($this->fullDay)($this->employee, '2026-09-15');
        $conflicts = ($this->justificationConflicts)();

        expect($conflicts)->toHaveCount(1)
            ->and($conflicts[0])->toMatchArray([
                'type' => 'absence_with_justification',
                'employee_id' => $this->employee->id,
                'date' => '2026-09-15',
                'adjustment_id' => $absence->id,
                'coverage' => null,
            ])
            ->and($conflicts[0]['message'])->toContain('Ana Souza')
            ->toContain('15/09/2026')
            ->toContain('motivo: Atestado médico (F5.3)')
            ->toContain('abonar integralmente')
            ->toContain('justificativa #'.$justification->id)
            ->toContain('#'.$absence->id.', pendente')
            ->and($conflicts[0]['action'])->toContain('nada é alterado automaticamente');
    });

    test('a justification without allowance or for an interval produces no conflict', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15', '2026-09-16']);
        $this->suggester->generate($this->period);
        ($this->fullDay)($this->employee, '2026-09-15', JustificationTreatment::NoAllowance);
        PointJustification::factory()->between('2026-09-16', '08:00', '17:00')->create([
            'employee_id' => $this->employee->id,
            'reason_id' => $this->reason->id,
            'created_by' => $this->user->id,
        ]);

        expect(($this->justificationConflicts)())->toHaveCount(0);
    });

    test('the conflict changes no adjustment, status or history', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        $this->suggester->generate($this->period);
        ($this->fullDay)($this->employee, '2026-09-15');
        $before = ($this->snapshot)();
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        ($this->justificationConflicts)();
        Livewire::test(BenefitPeriodAdjustments::class, ['benefitPeriod' => $this->period]);

        expect($writes)->toBe([])
            ->and(($this->snapshot)())->toEqual($before)
            ->and(BenefitAdjustment::sole()->status)->toBe(AdjustmentStatus::Pending);
    });

    test('canceling the justification removes the conflict on the next query', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        $this->suggester->generate($this->period);
        $justification = ($this->fullDay)($this->employee, '2026-09-15');

        expect(($this->justificationConflicts)())->toHaveCount(1);

        app(PointJustificationService::class)->cancel($justification, 'Lançada por engano', 1, $this->user);

        expect(($this->justificationConflicts)())->toHaveCount(0)
            ->and(BenefitAdjustment::sole()->status)->toBe(AdjustmentStatus::Pending);
    });

    test('a confirmed adjustment is kept unchanged and still reported', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        $this->suggester->generate($this->period);
        $this->registrar->confirm(BenefitAdjustment::sole(), 'Conferido');
        ($this->fullDay)($this->employee, '2026-09-15');
        $before = ($this->snapshot)();

        $conflicts = ($this->justificationConflicts)();
        $summary = $this->suggester->generate($this->period);

        expect($conflicts)->toHaveCount(1)
            ->and($conflicts[0]['message'])->toContain('confirmado')
            ->and($summary)->toMatchArray(['created' => 0, 'skipped_existing' => 1, 'skipped_justified' => 0])
            ->and(($this->snapshot)()['adjustments'])->toEqual($before['adjustments'])
            ->and(($this->snapshot)()['impacts'])->toEqual($before['impacts'])
            ->and(BenefitAdjustment::sole()->status)->toBe(AdjustmentStatus::Confirmed);
    });

    test('a rejected adjustment keeps the current behavior', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        $this->suggester->generate($this->period);
        $this->registrar->reject(BenefitAdjustment::sole(), 'Não procede');
        ($this->fullDay)($this->employee, '2026-09-15');
        $before = ($this->snapshot)()['adjustments'];

        expect(($this->justificationConflicts)())->toHaveCount(0)
            ->and($this->suggester->generate($this->period))->toMatchArray(['created' => 0, 'skipped_existing' => 1, 'skipped_justified' => 0])
            ->and(($this->snapshot)()['adjustments'])->toEqual($before)
            ->and(BenefitAdjustment::sole()->status)->toBe(AdjustmentStatus::Rejected);
    });

    test('only timesheet suggestions of unjustified absence are reported', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15', '2026-09-16']);
        BenefitAdjustment::factory()->absence('2026-09-15')->create(['benefit_period_id' => $this->period->id, 'employee_id' => $this->employee->id, 'source' => AdjustmentSource::Manual]);
        BenefitAdjustment::factory()->reason(AdjustmentReason::MedicalCertificate)->between('2026-09-16', '2026-09-16', 1)->pending()->create(['benefit_period_id' => $this->period->id, 'employee_id' => $this->employee->id, 'source' => AdjustmentSource::Timesheet]);
        ($this->fullDay)($this->employee, '2026-09-15');
        ($this->fullDay)($this->employee, '2026-09-16');

        expect(($this->justificationConflicts)())->toHaveCount(0);
    });

    test('a closed competence keeps its rules: no generation and a manual recommendation', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        $this->suggester->generate($this->period);
        ($this->fullDay)($this->employee, '2026-09-15');
        BenefitPeriod::query()->whereKey($this->period->id)->update(['status' => BenefitPeriodStatus::Closed, 'calculated_at' => now(), 'closed_at' => now()]);
        $this->period->refresh();
        $before = ($this->snapshot)();

        expect(fn () => $this->suggester->generate($this->period))->toThrow(DomainException::class, 'está fechada');

        $conflicts = ($this->justificationConflicts)();

        expect($conflicts)->toHaveCount(1)
            ->and($conflicts[0]['action'])->toContain('Competência 10/2026 fechada')
            ->and(BenefitSuggestionRun::count())->toBe(1)
            ->and(($this->snapshot)())->toEqual($before);
    });
});

describe('queries', function () {
    test('justifications are loaded with a fixed number of queries', function () {
        $count = function (callable $action): int {
            $queries = [];
            DB::listen(function (QueryExecuted $query) use (&$queries) {
                if (str_contains($query->sql, 'point_justification')) {
                    $queries[] = $query->sql;
                }
            });
            $action();
            DB::flushQueryLog();

            return count($queries);
        };

        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->fullDay)($this->employee, '2026-09-15');
        $single = $count(fn () => $this->suggester->conflicts($this->period));

        foreach (range(1, 5) as $index) {
            $employee = ($this->participant)();
            ($this->presentOnBusinessDays)($employee, except: ['2026-09-15', '2026-09-16']);
            ($this->fullDay)($employee, '2026-09-15');
            ($this->fullDay)($employee, '2026-09-16', JustificationTreatment::PartialAllowance);
        }

        $many = $count(fn () => $this->suggester->conflicts($this->period));

        expect($single)->toBe(2)
            ->and($many)->toBe($single);
    });

    test('the generation reads the justifications once', function () {
        foreach (range(1, 4) as $index) {
            $employee = ($this->participant)();
            ($this->presentOnBusinessDays)($employee, except: ['2026-09-15']);
            ($this->fullDay)($employee, '2026-09-15');
        }
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            if (str_contains($query->sql, 'from `point_justifications`')) {
                $queries[] = $query->sql;
            }
        });

        expect($this->suggester->generate($this->period))->toMatchArray(['skipped_justified' => 4])
            ->and($queries)->toHaveCount(1);
    });
});

describe('screen', function () {
    test('the summary and the last run show the justified counter', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->fullDay)($this->employee, '2026-09-15');

        Livewire::test(BenefitPeriodAdjustments::class, ['benefitPeriod' => $this->period])
            ->call('generateSuggestions')
            ->assertSet('generationSummary.skipped_justified', 1)
            ->assertSet('generationSummary.skipped_covered', 0)
            ->assertSee('Ignoradas por justificativa: 1')
            ->assertSee('0 coberta(s), 1 justificada(s)');
    });

    test('the conflict is listed with its label, reason and date', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        $this->suggester->generate($this->period);
        ($this->fullDay)($this->employee, '2026-09-15');

        Livewire::test(BenefitPeriodAdjustments::class, ['benefitPeriod' => $this->period])
            ->assertSee('Ausência com justificativa abonada')
            ->assertSee('motivo: Atestado médico (F5.3)')
            ->assertSee('dia inteiro 15/09/2026')
            ->assertSee('Ver ajuste #'.BenefitAdjustment::sole()->id);
    });
});
