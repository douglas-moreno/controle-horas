<?php

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\AdjustmentStatus;
use App\Enums\BenefitPeriodStatus;
use App\Enums\BenefitType;
use App\Livewire\Benefits\BenefitPeriodAdjustments;
use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\Holiday;
use App\Models\Point;
use App\Models\User;
use App\Services\BenefitAdjustmentRegistrar;
use App\Services\BusinessCalendar;
use App\Services\TimesheetAdjustmentSuggester;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Competência 10/2026 → janela 01/09 a 30/09/2026 (21 dias úteis com o feriado de 07/09).
 * Sábados: 05, 12, 19, 26. Domingos: 06, 13, 20, 27.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Holiday::factory()->create(['date' => '2026-09-07', 'description' => 'Independência']);

    $this->suggester = app(TimesheetAdjustmentSuggester::class);
    $this->registrar = app(BenefitAdjustmentRegistrar::class);
    $this->period = BenefitPeriod::factory()->forCompetence('2026-10')->create();

    $this->participant = function (array $attributes = []): Employee {
        $employee = Employee::factory()->create($attributes);
        EmployeeBenefit::factory()->for($employee)->ofType(BenefitType::Vr)->between('2026-01-01')->create();

        return $employee;
    };

    $this->punch = fn (Employee $employee, string $date, string $type = 'importado') => Point::factory()
        ->forEmployee($employee)->on($date)->state(['type' => $type])->create();

    $this->presentOnBusinessDays = function (Employee $employee, array $except = []): void {
        app(BusinessCalendar::class)
            ->businessDays(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'))
            ->map(fn (CarbonImmutable $day) => $day->toDateString())
            ->reject(fn (string $date) => in_array($date, $except, true))
            ->each(fn (string $date) => ($this->punch)($employee, $date));
    };

    $this->completeImport = fn () => Point::factory()->on('2026-10-01')->state(['pis' => '19999999999'])->create();

    $this->employee = ($this->participant)(['name' => 'Ana Souza']);
});

/**
 * @return list<string> motivo e data de cada ajuste do funcionário, ordenados por data
 */
function suggestionsOf(Employee $employee): array
{
    return BenefitAdjustment::query()
        ->where('employee_id', $employee->id)
        ->orderBy('starts_on')
        ->get()
        ->map(fn (BenefitAdjustment $adjustment) => $adjustment->reason->name.' '.$adjustment->starts_on->toDateString())
        ->all();
}

/**
 * @return array<string, mixed>
 */
function conflictTypes(array $conflicts): array
{
    return collect($conflicts)->pluck('type')->all();
}

describe('presence', function () {
    test('a business day without punches suggests an unjustified absence', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->completeImport)();

        expect($this->suggester->generate($this->period))->toBe(['created' => 1, 'skipped_existing' => 0, 'skipped_covered' => 0]);

        $adjustment = BenefitAdjustment::sole();

        expect($adjustment->reason)->toBe(AdjustmentReason::UnjustifiedAbsence)
            ->and($adjustment->source)->toBe(AdjustmentSource::Timesheet)
            ->and($adjustment->status)->toBe(AdjustmentStatus::Pending)
            ->and($adjustment->starts_on->toDateString())->toBe('2026-09-15')
            ->and($adjustment->impacts()->orderBy('benefit_type')->pluck('quantity', 'benefit_type')->all())->toBe(['vd' => -1, 'vr' => -1, 'vt' => -1]);
    });

    test('every business day without punches is suggested', function () {
        ($this->completeImport)();

        expect($this->suggester->generate($this->period)['created'])->toBe(21);
    });

    test('a business day with one punch creates nothing', function () {
        ($this->presentOnBusinessDays)($this->employee);
        ($this->completeImport)();

        expect($this->suggester->generate($this->period)['created'])->toBe(0)
            ->and(BenefitAdjustment::count())->toBe(0);
    });

    test('a business day with several punches creates nothing', function () {
        ($this->presentOnBusinessDays)($this->employee);
        ($this->punch)($this->employee, '2026-09-15');
        ($this->punch)($this->employee, '2026-09-15');
        ($this->punch)($this->employee, '2026-09-15');
        ($this->completeImport)();

        expect($this->suggester->generate($this->period)['created'])->toBe(0);
    });

    test('a punch on a weekend or holiday suggests a worked day', function (string $date, AdjustmentReason $reason) {
        ($this->presentOnBusinessDays)($this->employee);
        ($this->punch)($this->employee, $date);
        ($this->completeImport)();

        $this->suggester->generate($this->period);

        $adjustment = BenefitAdjustment::sole();

        expect($adjustment->reason)->toBe($reason)
            ->and($adjustment->starts_on->toDateString())->toBe($date)
            ->and($adjustment->status)->toBe(AdjustmentStatus::Pending)
            ->and($adjustment->impacts()->orderBy('benefit_type')->pluck('quantity', 'benefit_type')->all())->toBe(['vd' => 1, 'vr' => 1, 'vt' => 1]);
    })->with([
        'saturday' => ['2026-09-12', AdjustmentReason::SaturdayWorked],
        'sunday' => ['2026-09-13', AdjustmentReason::SundayWorked],
        'holiday' => ['2026-09-07', AdjustmentReason::HolidayWorked],
    ]);

    test('a holiday on a saturday suggests only a worked holiday', function () {
        Holiday::factory()->create(['date' => '2026-09-19', 'description' => 'Feriado no sábado']);
        ($this->presentOnBusinessDays)($this->employee);
        ($this->punch)($this->employee, '2026-09-19');
        ($this->completeImport)();

        $this->suggester->generate($this->period);

        expect(suggestionsOf($this->employee))->toBe(['HolidayWorked 2026-09-19']);
    });

    test('a weekend without punches creates nothing', function () {
        ($this->presentOnBusinessDays)($this->employee);
        ($this->completeImport)();

        $this->suggester->generate($this->period);

        expect(BenefitAdjustment::query()->whereIn('reason', [AdjustmentReason::SaturdayWorked, AdjustmentReason::SundayWorked])->count())->toBe(0);
    });

    test('a manual punch counts as presence', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->punch)($this->employee, '2026-09-15', 'manual');
        ($this->punch)($this->employee, '2026-09-26', 'manual');
        ($this->completeImport)();

        $this->suggester->generate($this->period);

        expect(suggestionsOf($this->employee))->toBe(['SaturdayWorked 2026-09-26']);
    });

    test('punches outside the window are ignored', function () {
        ($this->presentOnBusinessDays)($this->employee);
        ($this->punch)($this->employee, '2026-08-29');
        ($this->punch)($this->employee, '2026-10-03');
        ($this->completeImport)();

        expect($this->suggester->generate($this->period)['created'])->toBe(0);
    });

    test('the last day of the window is analysed', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-30']);
        ($this->completeImport)();

        $this->suggester->generate($this->period);

        expect(suggestionsOf($this->employee))->toBe(['UnjustifiedAbsence 2026-09-30']);
    });
});

describe('pis', function () {
    test('an unknown pis creates nothing and is reported', function () {
        ($this->presentOnBusinessDays)($this->employee);
        Point::factory()->on('2026-09-12')->state(['pis' => '12345678901'])->create();
        ($this->completeImport)();

        expect($this->suggester->generate($this->period)['created'])->toBe(0);

        $unknown = collect($this->suggester->conflicts($this->period))->where('type', 'unknown_pis')->values();

        expect($unknown)->toHaveCount(1)
            ->and($unknown->pluck('message')->join(' '))->toContain('PIS 12345678901');
    });

    test('a duplicated pis among participants blocks the generation', function () {
        $twin = ($this->participant)(['name' => 'Bruno Lima', 'pis' => '0'.$this->employee->pis]);
        ($this->completeImport)();

        expect(fn () => $this->suggester->generate($this->period))
            ->toThrow(DomainException::class, 'mesmo PIS');

        expect(BenefitAdjustment::count())->toBe(0)
            ->and(conflictTypes($this->suggester->conflicts($this->period)))->toContain('duplicated_pis')
            ->and($twin->exists)->toBeTrue();
    });

    test('a pis stored with leading zeros matches the bigint punch', function () {
        $employee = ($this->participant)(['pis' => '01234567890']);
        ($this->presentOnBusinessDays)($this->employee);
        ($this->presentOnBusinessDays)($employee, except: ['2026-09-15']);
        ($this->completeImport)();

        expect(Point::query()->where('pis', 1234567890)->exists())->toBeTrue();

        $this->suggester->generate($this->period);

        expect(suggestionsOf($employee))->toBe(['UnjustifiedAbsence 2026-09-15'])
            ->and(conflictTypes($this->suggester->conflicts($this->period)))->not->toContain('unknown_pis');
    });

    test('pis normalization keeps only digits without leading zeros', function () {
        expect($this->suggester->normalizePis('012.345.678-90'))->toBe('1234567890')
            ->and($this->suggester->normalizePis(1234567890))->toBe('1234567890')
            ->and($this->suggester->normalizePis(''))->toBe('');
    });
});

describe('incomplete import', function () {
    test('the generation is blocked while the import does not reach the end of the window', function () {
        ($this->punch)($this->employee, '2026-09-27');

        expect($this->suggester->generationBlocker($this->period))
            ->toBe('Batidas importadas até 27/09/2026. A geração exige batidas até 30/09/2026.');
        expect(fn () => $this->suggester->generate($this->period))
            ->toThrow(DomainException::class, 'Batidas importadas até 27/09/2026');
        expect(BenefitAdjustment::count())->toBe(0);
    });

    test('the generation is blocked when nothing was imported', function () {
        expect($this->suggester->generationBlocker($this->period))->toContain('nenhuma data');
    });

    test('a manual punch after the last import does not release the generation', function () {
        ($this->punch)($this->employee, '2026-09-27');
        ($this->punch)($this->employee, '2026-10-02', 'manual');

        expect($this->suggester->generationBlocker($this->period))->not->toBeNull()
            ->and(fn () => $this->suggester->generate($this->period))->toThrow(DomainException::class);
    });

    test('the last imported date ignores manual punches', function () {
        ($this->punch)($this->employee, '2026-09-27');
        ($this->punch)($this->employee, '2026-10-05', 'manual');

        expect($this->suggester->lastImportedPointDate()->toDateString())->toBe('2026-09-27');
    });

    test('an import exactly until the end of the window releases the generation', function () {
        ($this->punch)($this->employee, '2026-09-30');

        expect($this->suggester->generationBlocker($this->period))->toBeNull();
    });

    test('a closed competence cannot generate suggestions', function () {
        ($this->completeImport)();
        $this->period->forceFill(['status' => BenefitPeriodStatus::Closed])->save();

        expect(fn () => $this->suggester->generate($this->period))->toThrow(DomainException::class, 'está fechada');
    });
});

describe('idempotency', function () {
    test('running twice creates suggestions only once', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15', '2026-09-16']);
        ($this->punch)($this->employee, '2026-09-12');
        ($this->completeImport)();

        expect($this->suggester->generate($this->period))->toBe(['created' => 3, 'skipped_existing' => 0, 'skipped_covered' => 0])
            ->and($this->suggester->generate($this->period))->toBe(['created' => 0, 'skipped_existing' => 3, 'skipped_covered' => 0])
            ->and(BenefitAdjustment::count())->toBe(3);
    });

    test('a rejected suggestion is not recreated', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->completeImport)();
        $this->suggester->generate($this->period);
        $this->registrar->reject(BenefitAdjustment::sole(), 'Treinamento externo.');

        expect($this->suggester->generate($this->period))->toBe(['created' => 0, 'skipped_existing' => 1, 'skipped_covered' => 0])
            ->and(BenefitAdjustment::sole()->status)->toBe(AdjustmentStatus::Rejected);
    });

    test('the dedupe key is deterministic', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-08']);
        ($this->completeImport)();

        $this->suggester->generate($this->period);

        expect(BenefitAdjustment::sole()->dedupe_key)->toBe('timesheet:'.$this->period->id.':'.$this->employee->id.':2026-09-08:UnjustifiedAbsence');
    });
});

describe('coverage', function () {
    test('a day covered by a non rejected absence is not suggested', function (AdjustmentSource $source) {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15', '2026-09-16']);
        ($this->completeImport)();
        $this->registrar->register($this->period, $this->employee, AdjustmentReason::MedicalCertificate, $source, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-16'));

        expect($this->suggester->generate($this->period))->toBe(['created' => 0, 'skipped_existing' => 0, 'skipped_covered' => 2]);
    })->with([
        'confirmed' => AdjustmentSource::Manual,
        'pending' => AdjustmentSource::Import,
    ]);

    test('a day covered only by a rejected absence is suggested', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->completeImport)();
        $vacation = $this->registrar->register($this->period, $this->employee, AdjustmentReason::Vacation, AdjustmentSource::Manual, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'))['adjustment'];
        $this->registrar->reject($vacation, 'Férias canceladas.');

        expect($this->suggester->generate($this->period)['created'])->toBe(1);
    });

    test('a forecast event of the previous competence covers the window', function () {
        $september = BenefitPeriod::factory()->forCompetence('2026-09')->create();
        $this->registrar->register($september, $this->employee, AdjustmentReason::Vacation, AdjustmentSource::Manual, CarbonImmutable::parse('2026-09-14'), CarbonImmutable::parse('2026-09-18'));
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18']);
        ($this->completeImport)();

        expect($this->suggester->generate($this->period))->toBe(['created' => 0, 'skipped_existing' => 0, 'skipped_covered' => 5])
            ->and(BenefitAdjustment::count())->toBe(1);
    });

    test('a worked day already registered is not suggested again', function () {
        ($this->presentOnBusinessDays)($this->employee);
        ($this->punch)($this->employee, '2026-09-12');
        ($this->completeImport)();
        $this->registrar->register($this->period, $this->employee, AdjustmentReason::SaturdayWorked, AdjustmentSource::Manual, CarbonImmutable::parse('2026-09-12'), CarbonImmutable::parse('2026-09-12'));

        expect($this->suggester->generate($this->period))->toBe(['created' => 0, 'skipped_existing' => 0, 'skipped_covered' => 1]);
    });

    test('a manual adjustment does not cover the day', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->completeImport)();
        $this->registrar->register($this->period, $this->employee, AdjustmentReason::Manual, AdjustmentSource::Manual, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), 'Pago a menor.', ['vt' => 1]);

        expect($this->suggester->generate($this->period)['created'])->toBe(1);
    });
});

describe('participants', function () {
    test('an employee who is not a participant is ignored', function () {
        $outsider = Employee::factory()->create();
        ($this->presentOnBusinessDays)($this->employee);
        ($this->punch)($outsider, '2026-09-12');
        ($this->completeImport)();

        $this->suggester->generate($this->period);

        expect(suggestionsOf($outsider))->toBe([])
            ->and(conflictTypes($this->suggester->conflicts($this->period)))->not->toContain('unknown_pis');
    });

    test('eligibility is evaluated on the first day of the competence', function () {
        $endedBefore = Employee::factory()->create();
        EmployeeBenefit::factory()->for($endedBefore)->between('2026-01-01', '2026-09-30')->create();
        $startsAfter = Employee::factory()->create();
        EmployeeBenefit::factory()->for($startsAfter)->between('2026-10-02')->create();
        $startsOnReference = Employee::factory()->create();
        EmployeeBenefit::factory()->for($startsOnReference)->between('2026-10-01')->create();
        ($this->presentOnBusinessDays)($this->employee);
        ($this->presentOnBusinessDays)($startsOnReference, except: ['2026-09-15']);
        ($this->completeImport)();

        $this->suggester->generate($this->period);

        expect(suggestionsOf($endedBefore))->toBe([])
            ->and(suggestionsOf($startsAfter))->toBe([])
            ->and(suggestionsOf($startsOnReference))->toBe(['UnjustifiedAbsence 2026-09-15']);
    });

    test('a rescinded participant is still processed and reported', function () {
        $rescinded = ($this->participant)(['name' => 'Carla Dias', 'recision_date' => '2026-09-10']);
        ($this->presentOnBusinessDays)($this->employee);
        ($this->presentOnBusinessDays)($rescinded, except: ['2026-09-21']);
        ($this->completeImport)();

        $this->suggester->generate($this->period);

        $conflict = collect($this->suggester->conflicts($this->period))->firstWhere('type', 'employee_rescinded');

        expect(suggestionsOf($rescinded))->toBe(['UnjustifiedAbsence 2026-09-21'])
            ->and($conflict['employee_id'])->toBe($rescinded->id)
            ->and($conflict['message'])->toContain('Carla Dias');
    });

    test('an empty recision date is not a rescission', function () {
        if (DB::getDriverName() === 'mysql') {
            $this->markTestSkipped('O MySQL estrito não grava string vazia em coluna datetime; o valor só existe no SQLite legado.');
        }

        ($this->participant)(['recision_date' => '']);

        expect(conflictTypes($this->suggester->conflicts($this->period)))->not->toContain('employee_rescinded');
    });
});

describe('conflicts', function () {
    test('an absence with a punch is reported and not persisted', function () {
        ($this->presentOnBusinessDays)($this->employee);
        ($this->completeImport)();
        $this->registrar->register($this->period, $this->employee, AdjustmentReason::Vacation, AdjustmentSource::Manual, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'));
        $countBefore = BenefitAdjustment::count();

        $conflicts = $this->suggester->conflicts($this->period);

        expect($conflicts)->toBe([[
            'type' => 'absence_with_punch',
            'employee_id' => $this->employee->id,
            'date' => '2026-09-15',
            'message' => 'Ana Souza teve uma ausência registrada para o dia 15/09/2026, mas existe batida no ponto.',
        ]]);

        $this->suggester->generate($this->period);

        expect(BenefitAdjustment::count())->toBe($countBefore)
            ->and(BenefitAdjustment::sole()->status)->toBe(AdjustmentStatus::Confirmed);
    });

    test('work on an absence day creates the positive suggestion and reports a conflict', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-14', '2026-09-15']);
        ($this->punch)($this->employee, '2026-09-12');
        ($this->completeImport)();
        $this->registrar->register($this->period, $this->employee, AdjustmentReason::Vacation, AdjustmentSource::Manual, CarbonImmutable::parse('2026-09-11'), CarbonImmutable::parse('2026-09-15'));

        ($this->punch)($this->employee, '2026-09-11');
        $this->suggester->generate($this->period);

        expect(suggestionsOf($this->employee))->toBe(['Vacation 2026-09-11', 'SaturdayWorked 2026-09-12'])
            ->and(conflictTypes($this->suggester->conflicts($this->period)))->toBe(['absence_with_punch', 'work_on_absence']);
    });
});

describe('points integrity', function () {
    test('the generation never writes to points', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->punch)($this->employee, '2026-09-12');
        Point::factory()->on('2026-09-13')->state(['pis' => '12345678901'])->create();
        ($this->completeImport)();

        $this->travel(1)->hour();
        $snapshot = DB::table('points')->orderBy('id')->get()->toArray();

        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b.*\bpoints\b/is', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $modelEvents = 0;
        foreach (['creating', 'saving', 'updating', 'deleting'] as $event) {
            Point::$event(function () use (&$modelEvents) {
                $modelEvents++;
            });
        }

        $this->suggester->generate($this->period);
        $this->suggester->generate($this->period);
        $this->suggester->conflicts($this->period);

        expect($writes)->toBe([])
            ->and($modelEvents)->toBe(0)
            ->and(DB::table('points')->count())->toBe(count($snapshot))
            ->and(DB::table('points')->orderBy('id')->get()->toArray())->toEqual($snapshot);
    });
});

describe('calculated competence', function () {
    test('new suggestions invalidate a calculated competence through the registrar', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->completeImport)();
        $this->period->forceFill(['status' => BenefitPeriodStatus::Calculated, 'business_days' => 21])->save();

        $this->suggester->generate($this->period);

        expect($this->period->fresh()->status)->toBe(BenefitPeriodStatus::Open)
            ->and($this->period->fresh()->statusChanges()->latest('id')->first()->reason)->toBe('Prévia descartada por alteração de ajustes.');
    });

    test('a generation without new suggestions keeps the calculation', function () {
        ($this->presentOnBusinessDays)($this->employee);
        ($this->completeImport)();
        $this->period->forceFill(['status' => BenefitPeriodStatus::Calculated])->save();

        $this->suggester->generate($this->period);

        expect($this->period->fresh()->status)->toBe(BenefitPeriodStatus::Calculated);
    });
});

describe('queries', function () {
    test('reading does not grow with employees or days', function () {
        foreach (range(1, 5) as $index) {
            $employee = ($this->participant)();
            ($this->presentOnBusinessDays)($employee);
        }
        ($this->presentOnBusinessDays)($this->employee);
        ($this->completeImport)();

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->suggester->generate($this->period);

        $reads = collect($queries)->filter(fn (string $sql) => preg_match('/^\s*select/i', $sql))->values();

        expect(BenefitAdjustment::count())->toBe(0)
            ->and($reads->filter(fn (string $sql) => str_contains($sql, 'points'))->count())->toBe(2)
            ->and($reads->count())->toBeLessThanOrEqual(8);
    });

    test('the points of the window are read with one distinct query', function () {
        ($this->completeImport)();
        $pointQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$pointQueries) {
            if (str_contains($query->sql, 'points') && ! str_contains($query->sql, 'max(')) {
                $pointQueries[] = strtolower($query->sql);
            }
        });

        $this->suggester->conflicts($this->period);

        expect($pointQueries)->toHaveCount(1)
            ->and($pointQueries[0])->toContain('select distinct');
    });
});

describe('screen', function () {
    test('the generation button is disabled with the reason while the import is incomplete', function () {
        ($this->punch)($this->employee, '2026-09-27');

        Livewire::test(BenefitPeriodAdjustments::class, ['benefitPeriod' => $this->period])
            ->assertSee('Última batida importada: 27/09/2026')
            ->assertSee('Batidas importadas até 27/09/2026. A geração exige batidas até 30/09/2026.')
            ->assertViewHas('generationBlocker', fn (?string $blocker) => $blocker !== null)
            ->call('generateSuggestions');

        expect(BenefitAdjustment::count())->toBe(0);
    });

    test('the generation runs from the screen and shows the summary', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->completeImport)();

        Livewire::test(BenefitPeriodAdjustments::class, ['benefitPeriod' => $this->period])
            ->assertViewHas('generationBlocker', null)
            ->assertSee('Gerar sugestões do ponto')
            ->call('generateSuggestions')
            ->assertSet('generationSummary', ['created' => 1, 'skipped_existing' => 0, 'skipped_covered' => 0])
            ->assertSee('Sugestões geradas: 1')
            ->assertSee('Ignoradas por cobertura: 0');

        expect(BenefitAdjustment::sole()->status)->toBe(AdjustmentStatus::Pending);
    });

    test('the conflicts panel lists conflicts or says there are none', function () {
        Livewire::test(BenefitPeriodAdjustments::class, ['benefitPeriod' => $this->period])
            ->assertSee('Nenhum conflito encontrado.');

        Point::factory()->on('2026-09-12')->state(['pis' => '12345678901'])->create();

        Livewire::test(BenefitPeriodAdjustments::class, ['benefitPeriod' => $this->period])
            ->assertSee('PIS desconhecido')
            ->assertSee('PIS 12345678901');
    });

    test('a closed competence hides the generation', function () {
        $this->period->forceFill(['status' => BenefitPeriodStatus::Closed])->save();

        Livewire::test(BenefitPeriodAdjustments::class, ['benefitPeriod' => $this->period])
            ->assertDontSee('Gerar sugestões do ponto');
    });
});
