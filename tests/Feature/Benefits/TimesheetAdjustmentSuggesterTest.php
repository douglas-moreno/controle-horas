<?php

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\AdjustmentStatus;
use App\Enums\BenefitPeriodStatus;
use App\Enums\BenefitType;
use App\Livewire\Benefits\BenefitPeriodAdjustments;
use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriod;
use App\Models\BenefitSuggestionRun;
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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Competência 10/2026 → janela 01/09 a 30/09/2026 (21 dias úteis com o feriado de 07/09).
 * Sábados: 05, 12, 19, 26. Domingos: 06, 13, 20, 27.
 *
 * `completeImport` simula o AFD importado até 01/10 (horizonte 30/09): um colega sem
 * benefício bate ponto em todos os dias úteis, então nenhum dia útil fica sem batidas.
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

    $this->colleague = Employee::factory()->create(['name' => 'Colega sem benefício']);

    $this->completeImport = function (): void {
        ($this->presentOnBusinessDays)($this->colleague);
        ($this->punch)($this->colleague, '2026-10-01');
    };

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

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 1, 'skipped_existing' => 0, 'skipped_covered' => 0]);

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

describe('coverage horizon', function () {
    test('the horizon is the last imported date minus one day', function () {
        ($this->punch)($this->employee, '2026-09-29');

        $coverage = $this->suggester->importCoverage($this->period);

        expect($coverage['last_imported']->toDateString())->toBe('2026-09-29')
            ->and($coverage['horizon']->toDateString())->toBe('2026-09-28')
            ->and($coverage['from']->toDateString())->toBe('2026-09-01')
            ->and($coverage['to']->toDateString())->toBe('2026-09-28')
            ->and($this->suggester->generationBlocker($this->period))->toBeNull();
    });

    test('the processable range stops at the end of the window', function () {
        ($this->punch)($this->employee, '2026-10-05');

        expect($this->suggester->importCoverage($this->period)['to']->toDateString())->toBe('2026-09-30');
    });

    test('manual punches do not move the horizon', function () {
        ($this->punch)($this->employee, '2026-09-27');
        ($this->punch)($this->employee, '2026-10-05', 'manual');

        expect($this->suggester->lastImportedPointDate()->toDateString())->toBe('2026-09-27')
            ->and($this->suggester->importCoverage($this->period)['horizon']->toDateString())->toBe('2026-09-26');
    });

    test('the generation is blocked when nothing was imported', function () {
        ($this->punch)($this->employee, '2026-09-15', 'manual');

        expect($this->suggester->generationBlocker($this->period))->toBe('Nenhuma batida importada. A geração exige o ponto importado a partir de 01/09/2026.')
            ->and(fn () => $this->suggester->generate($this->period))->toThrow(DomainException::class, 'Nenhuma batida importada');

        expect(BenefitAdjustment::count())->toBe(0)
            ->and(BenefitSuggestionRun::count())->toBe(0);
    });

    test('a horizon before the start of the window blocks the generation', function () {
        ($this->punch)($this->employee, '2026-09-01');

        expect($this->suggester->generationBlocker($this->period))
            ->toBe('Batidas importadas até 01/09/2026: o ponto é considerado completo somente até 31/08/2026, antes do início da janela (01/09/2026). A geração exige ao menos um dia completo na janela.')
            ->and(fn () => $this->suggester->generate($this->period))->toThrow(DomainException::class, 'antes do início da janela');

        expect(BenefitAdjustment::count())->toBe(0)
            ->and(BenefitSuggestionRun::count())->toBe(0);
    });

    test('the most recent imported day never becomes an absence', function () {
        ($this->presentOnBusinessDays)($this->colleague);
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-29', '2026-09-30']);

        $summary = $this->suggester->generate($this->period);

        expect($summary['horizon'])->toBe('2026-09-29')
            ->and($summary['processed_to'])->toBe('2026-09-29')
            ->and(suggestionsOf($this->employee))->toBe(['UnjustifiedAbsence 2026-09-29']);
    });

    test('a closed competence cannot generate suggestions', function () {
        ($this->completeImport)();
        $this->period->forceFill(['status' => BenefitPeriodStatus::Closed])->save();

        expect(fn () => $this->suggester->generate($this->period))->toThrow(DomainException::class, 'está fechada');

        expect(BenefitSuggestionRun::count())->toBe(0);
    });
});

describe('incremental generation', function () {
    test('a partial generation is completed by a later import without duplicates', function () {
        $importColleagueUntil = function (string $lastDate): void {
            app(BusinessCalendar::class)
                ->businessDays(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse($lastDate))
                ->each(fn (CarbonImmutable $day) => ($this->punch)($this->colleague, $day->toDateString()));
        };
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15', '2026-09-29', '2026-09-30']);
        $importColleagueUntil('2026-09-29');

        $first = $this->suggester->generate($this->period);

        expect($first)->toMatchArray(['created' => 1, 'skipped_existing' => 0, 'last_imported' => '2026-09-29', 'horizon' => '2026-09-28', 'processed_from' => '2026-09-01', 'processed_to' => '2026-09-28'])
            ->and(suggestionsOf($this->employee))->toBe(['UnjustifiedAbsence 2026-09-15']);

        ($this->punch)($this->colleague, '2026-09-30');
        ($this->punch)($this->colleague, '2026-10-01');

        $second = $this->suggester->generate($this->period);

        expect($second)->toMatchArray(['created' => 2, 'skipped_existing' => 1, 'horizon' => '2026-09-30', 'processed_to' => '2026-09-30'])
            ->and(suggestionsOf($this->employee))->toBe(['UnjustifiedAbsence 2026-09-15', 'UnjustifiedAbsence 2026-09-29', 'UnjustifiedAbsence 2026-09-30']);

        $third = $this->suggester->generate($this->period);

        expect($third)->toMatchArray(['created' => 0, 'skipped_existing' => 3])
            ->and(BenefitAdjustment::count())->toBe(3)
            ->and(BenefitAdjustment::query()->pluck('status')->unique()->all())->toBe([AdjustmentStatus::Pending])
            ->and(BenefitSuggestionRun::count())->toBe(3);
    });

    test('a rejected suggestion is not recreated after a new import', function () {
        ($this->presentOnBusinessDays)($this->colleague);
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        $this->suggester->generate($this->period);
        $this->registrar->reject(BenefitAdjustment::sole(), 'Treinamento externo.');

        ($this->punch)($this->colleague, '2026-10-01');

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 0, 'skipped_existing' => 1])
            ->and(BenefitAdjustment::sole()->status)->toBe(AdjustmentStatus::Rejected);
    });

    test('suggestions confirmed before a new import are preserved', function () {
        ($this->presentOnBusinessDays)($this->colleague);
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15', '2026-09-30']);
        $this->suggester->generate($this->period);
        $confirmed = $this->registrar->confirm(BenefitAdjustment::sole());
        $before = $confirmed->fresh()->getAttributes();

        ($this->punch)($this->colleague, '2026-10-01');
        $this->suggester->generate($this->period);

        expect($confirmed->fresh()->getAttributes())->toBe($before)
            ->and($confirmed->fresh()->status)->toBe(AdjustmentStatus::Confirmed)
            ->and(suggestionsOf($this->employee))->toBe(['UnjustifiedAbsence 2026-09-15', 'UnjustifiedAbsence 2026-09-30']);
    });

    test('the dedupe key rejects a concurrent duplicate at the database level', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->completeImport)();
        $this->suggester->generate($this->period);
        $original = BenefitAdjustment::sole();

        expect(fn () => BenefitAdjustment::factory()->for($this->period)->for($this->employee)->create(['dedupe_key' => $original->dedupe_key]))
            ->toThrow(UniqueConstraintViolationException::class);

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 0, 'skipped_existing' => 1])
            ->and(BenefitAdjustment::count())->toBe(1);
    });
});

describe('days without punches', function () {
    test('a business day without any imported punch creates no absence and is reported', function () {
        ($this->presentOnBusinessDays)($this->colleague, except: ['2026-09-15']);
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15', '2026-09-16']);
        ($this->punch)($this->colleague, '2026-10-01');

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 1, 'skipped_unobserved_days' => 1])
            ->and(suggestionsOf($this->employee))->toBe(['UnjustifiedAbsence 2026-09-16']);

        $conflict = collect($this->suggester->conflicts($this->period))->firstWhere('type', 'day_without_punches');

        expect($conflict)->toBe([
            'type' => 'day_without_punches',
            'employee_id' => null,
            'date' => '2026-09-15',
            'message' => 'Nenhuma batida importada em 15/09/2026 (dia útil). Ausências não são sugeridas para esta data: verifique a importação, o relógio ou o cadastro de feriados.',
            'adjustment_id' => null,
            'coverage' => 'Ponto coberto até 30/09/2026, mas nenhuma batida importada nesta data.',
            'action' => null,
        ]);
    });

    test('a manual punch does not make the day imported', function () {
        $other = ($this->participant)(['name' => 'Bruno Lima']);
        ($this->presentOnBusinessDays)($this->colleague, except: ['2026-09-15']);
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->presentOnBusinessDays)($other, except: ['2026-09-15']);
        ($this->punch)($this->employee, '2026-09-15', 'manual');
        ($this->punch)($this->colleague, '2026-10-01');

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 0, 'skipped_unobserved_days' => 1])
            ->and(suggestionsOf($other))->toBe([]);
    });

    test('a weekend punch is still credited when business days have no punches', function () {
        ($this->punch)($this->employee, '2026-09-12');
        ($this->punch)($this->colleague, '2026-10-01');

        $summary = $this->suggester->generate($this->period);

        expect($summary)->toMatchArray(['created' => 1, 'skipped_unobserved_days' => 21])
            ->and(suggestionsOf($this->employee))->toBe(['SaturdayWorked 2026-09-12']);
    });

    test('days after the horizon are not reported', function () {
        ($this->presentOnBusinessDays)($this->colleague, except: ['2026-09-28', '2026-09-29', '2026-09-30']);
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-28', '2026-09-29', '2026-09-30']);
        ($this->punch)($this->colleague, '2026-09-29');

        $dates = collect($this->suggester->conflicts($this->period))->where('type', 'day_without_punches')->pluck('date')->all();

        expect($dates)->toBe(['2026-09-28']);
    });
});

describe('runs', function () {
    test('each generation is recorded with the coverage and the counters', function () {
        ($this->presentOnBusinessDays)($this->colleague, except: ['2026-09-21']);
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15', '2026-09-21']);
        ($this->punch)($this->employee, '2026-09-12');
        ($this->punch)($this->colleague, '2026-09-30');

        $summary = $this->suggester->generate($this->period);
        $run = BenefitSuggestionRun::sole();

        expect($run->benefit_period_id)->toBe($this->period->id)
            ->and($run->user_id)->toBe($this->user->id)
            ->and($run->last_imported_on->toDateString())->toBe('2026-09-30')
            ->and($run->horizon_on->toDateString())->toBe('2026-09-29')
            ->and($run->processed_from->toDateString())->toBe('2026-09-01')
            ->and($run->processed_to->toDateString())->toBe('2026-09-29')
            ->and([$run->created, $run->skipped_existing, $run->skipped_covered, $run->skipped_unobserved_days])->toBe([2, 0, 0, 1])
            ->and($summary)->toBe([
                'created' => 2,
                'skipped_existing' => 0,
                'skipped_covered' => 0,
                'skipped_unobserved_days' => 1,
                'compensation_created' => 0,
                'compensation_skipped_existing' => 0,
                'compensation_skipped_covered' => 0,
                'compensation_skipped_unobserved_days' => 0,
                'compensation_not_compensable' => 0,
                'last_imported' => '2026-09-30',
                'horizon' => '2026-09-29',
                'processed_from' => '2026-09-01',
                'processed_to' => '2026-09-29',
                'tail_status' => 'none',
                'tail_from' => null,
                'tail_to' => null,
            ])
            ->and($this->period->suggestionRuns()->count())->toBe(1);
    });

    test('a generation without new suggestions is recorded', function () {
        ($this->presentOnBusinessDays)($this->employee);
        ($this->completeImport)();

        $this->suggester->generate($this->period);
        $this->suggester->generate($this->period);

        expect(BenefitSuggestionRun::query()->pluck('created')->all())->toBe([0, 0]);
    });

    test('a blocked generation records nothing', function () {
        $twin = ($this->participant)(['pis' => '0'.$this->employee->pis]);
        ($this->completeImport)();

        expect(fn () => $this->suggester->generate($this->period))->toThrow(DomainException::class, 'mesmo PIS')
            ->and(BenefitSuggestionRun::count())->toBe(0)
            ->and($twin->exists)->toBeTrue();
    });

    test('runs cannot be changed', function () {
        $run = BenefitSuggestionRun::factory()->create();

        expect(fn () => $run->update(['created' => 5]))->toThrow(LogicException::class)
            ->and(fn () => $run->delete())->toThrow(LogicException::class);
    });
});

describe('idempotency', function () {
    test('running twice creates suggestions only once', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15', '2026-09-16']);
        ($this->punch)($this->employee, '2026-09-12');
        ($this->completeImport)();

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 3, 'skipped_existing' => 0, 'skipped_covered' => 0])
            ->and($this->suggester->generate($this->period))->toMatchArray(['created' => 0, 'skipped_existing' => 3, 'skipped_covered' => 0])
            ->and(BenefitAdjustment::count())->toBe(3);
    });

    test('a rejected suggestion is not recreated', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->completeImport)();
        $this->suggester->generate($this->period);
        $this->registrar->reject(BenefitAdjustment::sole(), 'Treinamento externo.');

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 0, 'skipped_existing' => 1, 'skipped_covered' => 0])
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

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 0, 'skipped_existing' => 0, 'skipped_covered' => 2]);
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

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 0, 'skipped_existing' => 0, 'skipped_covered' => 5])
            ->and(BenefitAdjustment::count())->toBe(1);
    });

    test('a worked day already registered is not suggested again', function () {
        ($this->presentOnBusinessDays)($this->employee);
        ($this->punch)($this->employee, '2026-09-12');
        ($this->completeImport)();
        $this->registrar->register($this->period, $this->employee, AdjustmentReason::SaturdayWorked, AdjustmentSource::Manual, CarbonImmutable::parse('2026-09-12'), CarbonImmutable::parse('2026-09-12'));

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 0, 'skipped_existing' => 0, 'skipped_covered' => 1]);
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
        $vacation = $this->registrar->register($this->period, $this->employee, AdjustmentReason::Vacation, AdjustmentSource::Manual, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'))['adjustment'];
        $countBefore = BenefitAdjustment::count();

        $conflicts = $this->suggester->conflicts($this->period);

        expect($conflicts)->toBe([[
            'type' => 'absence_with_punch',
            'employee_id' => $this->employee->id,
            'date' => '2026-09-15',
            'message' => 'Ana Souza teve uma ausência registrada para o dia 15/09/2026, mas existe batida no ponto. Ajuste afetado: Férias em 15/09/2026 (#'.$vacation->id.', confirmado, competência 10/2026).',
            'adjustment_id' => $vacation->id,
            'coverage' => 'Ponto coberto até 30/09/2026; a data tem batidas importadas.',
            'action' => 'Revise o ajuste #'.$vacation->id.': rejeite ou substitua.',
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
    test('the generation button is disabled with the reason while the horizon is before the window', function () {
        ($this->punch)($this->employee, '2026-09-01');

        Livewire::test(BenefitPeriodAdjustments::class, ['benefitPeriod' => $this->period])
            ->assertSee('Última batida importada: 01/09/2026')
            ->assertSee('Ponto considerado completo até: 31/08/2026')
            ->assertSee('Intervalo processável: nenhum')
            ->assertSee('Aguardando importação: 01/09/2026 a 30/09/2026')
            ->assertSee('antes do início da janela (01/09/2026)')
            ->assertViewHas('generationBlocker', fn (?string $blocker) => $blocker !== null)
            ->call('generateSuggestions');

        expect(BenefitAdjustment::count())->toBe(0)
            ->and(BenefitSuggestionRun::count())->toBe(0);
    });

    test('a partial import enables the generation and shows the coverage', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-29', '2026-09-30', '2026-09-15']);
        ($this->punch)($this->employee, '2026-09-29');

        Livewire::test(BenefitPeriodAdjustments::class, ['benefitPeriod' => $this->period])
            ->assertViewHas('generationBlocker', null)
            ->assertSee('Última batida importada: 29/09/2026')
            ->assertSee('Ponto considerado completo até: 28/09/2026')
            ->assertSee('Intervalo processável: 01/09/2026 a 28/09/2026')
            ->assertSee('Aguardando importação: 29/09/2026 a 30/09/2026')
            ->assertSee('Dias úteis sem nenhuma batida: 1')
            ->assertSee('Dia útil sem batidas')
            ->call('generateSuggestions')
            ->assertSet('generationSummary.processed_to', '2026-09-28')
            ->assertSet('generationSummary.skipped_unobserved_days', 1)
            ->assertSee('Processado de 01/09/2026 a 28/09/2026')
            ->assertSee('Última execução:')
            ->assertSee('intervalo 01/09/2026 a 28/09/2026');

        expect(BenefitAdjustment::count())->toBe(0);
    });

    test('the generation runs from the screen and shows the summary', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->completeImport)();

        Livewire::test(BenefitPeriodAdjustments::class, ['benefitPeriod' => $this->period])
            ->assertViewHas('generationBlocker', null)
            ->assertSee('Gerar sugestões do ponto')
            ->call('generateSuggestions')
            ->assertSet('generationSummary.created', 1)
            ->assertSet('generationSummary.skipped_existing', 0)
            ->assertSet('generationSummary.skipped_covered', 0)
            ->assertSee('Processado de 01/09/2026 a 30/09/2026')
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

describe('corrections after a generation', function () {
    beforeEach(function () {
        $this->removePunches = fn (?Employee $employee, string $date) => Point::query()
            ->when($employee !== null, fn ($query) => $query->where('pis', $employee->pis))
            ->whereDate('date', $date)
            ->delete();

        $this->legacySuggestion = fn (string $date) => $this->registrar
            ->registerSuggestion($this->period, $this->employee, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse($date))['adjustment'];

        $this->conflictsOf = fn (BenefitAdjustment $adjustment) => collect($this->suggester->conflicts($this->period))
            ->where('adjustment_id', $adjustment->id)
            ->values();
    });

    test('a worked day whose punch was removed is reported while the day still has imported punches', function () {
        ($this->presentOnBusinessDays)($this->employee);
        ($this->punch)($this->employee, '2026-09-12');
        ($this->punch)($this->colleague, '2026-09-12');
        ($this->completeImport)();
        $this->suggester->generate($this->period);
        $saturday = BenefitAdjustment::sole();

        ($this->removePunches)($this->employee, '2026-09-12');

        $conflict = ($this->conflictsOf)($saturday)->sole();

        expect($conflict['type'])->toBe('work_without_punch')
            ->and($conflict['employee_id'])->toBe($this->employee->id)
            ->and($conflict['date'])->toBe('2026-09-12')
            ->and($conflict['message'])->toContain('Ana Souza')
            ->and($conflict['message'])->toContain('Sábado trabalhado em 12/09/2026 (#'.$saturday->id.', pendente, competência 10/2026)')
            ->and($conflict['message'])->toContain('a evidência que gerou a sugestão deixou de existir')
            ->and($conflict['coverage'])->toBe('Ponto coberto até 30/09/2026; a data tem batidas importadas.')
            ->and($conflict['action'])->toBe('Revise o ajuste #'.$saturday->id.': rejeite com nota ou altere o motivo.')
            ->and($saturday->fresh()->status)->toBe(AdjustmentStatus::Pending);
    });

    test('a worked day left without any imported punch is not treated as proof of no work', function () {
        ($this->presentOnBusinessDays)($this->employee);
        ($this->punch)($this->employee, '2026-09-12');
        ($this->completeImport)();
        $this->suggester->generate($this->period);
        $saturday = $this->registrar->confirm(BenefitAdjustment::sole());

        ($this->removePunches)(null, '2026-09-12');

        $conflict = ($this->conflictsOf)($saturday)->sole();

        expect($conflict['type'])->toBe('work_without_punch')
            ->and($conflict['message'])->toContain('não há mais nenhuma batida importada nesta data')
            ->and($conflict['message'])->toContain('Isso não prova que não houve trabalho.')
            ->and($conflict['coverage'])->toBe('Ponto coberto até 30/09/2026, mas nenhuma batida importada nesta data.')
            ->and($conflict['action'])->toBe('Revise o ajuste #'.$saturday->id.': rejeite ou substitua.');
    });

    test('an absence suggestion with a punch added later is reported', function (string $type) {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->completeImport)();
        $this->suggester->generate($this->period);
        $absence = BenefitAdjustment::sole();

        ($this->punch)($this->employee, '2026-09-15', $type);

        $conflict = ($this->conflictsOf)($absence)->sole();

        expect($conflict['type'])->toBe('absence_with_punch')
            ->and($conflict['date'])->toBe('2026-09-15')
            ->and($conflict['message'])->toContain('Ausência injustificada em 15/09/2026 (#'.$absence->id.', pendente, competência 10/2026)')
            ->and($conflict['action'])->toBe('Revise o ajuste #'.$absence->id.': rejeite com nota ou altere o motivo.')
            ->and($absence->fresh()->status)->toBe(AdjustmentStatus::Pending);
    })->with(['manual', 'importado']);

    test('a horizon that moves back reports the suggestions left outside the current coverage', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15', '2026-09-29', '2026-09-30']);
        ($this->completeImport)();
        $this->suggester->generate($this->period);
        [$early, $day29, $day30] = BenefitAdjustment::query()->orderBy('starts_on')->get()->all();

        ($this->removePunches)(null, '2026-10-01');
        ($this->removePunches)(null, '2026-09-30');

        $conflicts = collect($this->suggester->conflicts($this->period))->where('type', 'suggestion_beyond_coverage')->values();

        expect($this->suggester->importCoverage($this->period)['to']->toDateString())->toBe('2026-09-28')
            ->and($conflicts->pluck('adjustment_id')->all())->toBe([$day29->id, $day30->id])
            ->and($conflicts->first()['message'])->toContain('foi sugerido com o ponto coberto até 30/09/2026')
            ->and($conflicts->first()['message'])->toContain('horizonte 30/09/2026')
            ->and($conflicts->first()['message'])->toContain('o ponto atual cobre a janela somente até 28/09/2026')
            ->and($conflicts->first()['coverage'])->toBe('Data após o ponto coberto (ponto considerado completo até 28/09/2026).')
            ->and(($this->conflictsOf)($early))->toBeEmpty()
            ->and(BenefitAdjustment::count())->toBe(3);
    });

    test('a worked day with its punch beyond the current coverage is not reported', function () {
        ($this->presentOnBusinessDays)($this->employee);
        ($this->punch)($this->employee, '2026-09-26');
        ($this->completeImport)();
        $this->suggester->generate($this->period);
        $saturday = BenefitAdjustment::sole();

        Point::query()->whereDate('date', '>', '2026-09-26')->delete();

        expect($this->suggester->importCoverage($this->period)['to']->toDateString())->toBe('2026-09-25')
            ->and(($this->conflictsOf)($saturday))->toBeEmpty();
    });

    test('a suggestion outside the coverage without a run that processed it has an unknown previous coverage', function () {
        ($this->presentOnBusinessDays)($this->colleague);
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-29', '2026-09-30']);
        Point::query()->whereDate('date', '>', '2026-09-28')->delete();
        $this->suggester->generate($this->period);
        $legacy = ($this->legacySuggestion)('2026-09-29');

        $conflict = ($this->conflictsOf)($legacy)->sole();

        expect(BenefitSuggestionRun::sole()->processed_to->toDateString())->toBe('2026-09-27')
            ->and($conflict['type'])->toBe('suggestion_coverage_unknown')
            ->and($conflict['message'])->toContain('a cobertura anterior é desconhecida')
            ->and($conflict['message'])->toContain('o ponto atual cobre a janela somente até 27/09/2026');
    });

    test('an absence suggestion whose day lost every imported punch is reported', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->completeImport)();
        $this->suggester->generate($this->period);
        $absence = BenefitAdjustment::sole();

        ($this->removePunches)(null, '2026-09-15');

        $conflict = ($this->conflictsOf)($absence)->sole();

        expect($conflict['type'])->toBe('absence_without_imported_punches')
            ->and($conflict['message'])->toContain('foi sugerida quando a data tinha batidas importadas')
            ->and($conflict['message'])->toContain('Isso não prova que o funcionário esteve presente.')
            ->and($conflict['coverage'])->toBe('Ponto coberto até 30/09/2026, mas nenhuma batida importada nesta data.')
            ->and(conflictTypes($this->suggester->conflicts($this->period)))->toContain('day_without_punches');
    });

    test('an absence without evidence of having been created under the imported punches rule is not reported as lost', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->completeImport)();
        $legacy = ($this->legacySuggestion)('2026-09-15');
        $this->suggester->generate($this->period);

        ($this->removePunches)(null, '2026-09-15');

        expect(BenefitSuggestionRun::sole()->created)->toBe(0)
            ->and(($this->conflictsOf)($legacy))->toBeEmpty()
            ->and(conflictTypes($this->suggester->conflicts($this->period)))->toContain('day_without_punches');
    });

    test('a suggestion created outside a run after the first run is not attributed to a run', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->completeImport)();
        $this->suggester->generate($this->period);
        $fromRun = BenefitAdjustment::sole();

        $this->travel(5)->minutes();
        $other = ($this->participant)(['name' => 'Bruno Lima']);
        ($this->presentOnBusinessDays)($other, except: ['2026-09-17']);
        $direct = $this->registrar->registerSuggestion($this->period, $other, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse('2026-09-17'))['adjustment'];

        ($this->removePunches)(null, '2026-09-15');
        ($this->removePunches)(null, '2026-09-17');

        expect($direct->created_at->greaterThan(BenefitSuggestionRun::sole()->created_at))->toBeTrue()
            ->and(($this->conflictsOf)($direct))->toBeEmpty()
            ->and(($this->conflictsOf)($fromRun))->toBeEmpty();
    });

    test('several runs, including runs without new suggestions, still demonstrate the origin', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15', '2026-09-29', '2026-09-30']);
        app(BusinessCalendar::class)
            ->businessDays(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-29'))
            ->each(fn (CarbonImmutable $day) => ($this->punch)($this->colleague, $day->toDateString()));
        $this->suggester->generate($this->period);
        $this->suggester->generate($this->period);
        ($this->punch)($this->employee, '2026-09-30');
        ($this->punch)($this->colleague, '2026-09-30');
        ($this->punch)($this->colleague, '2026-10-01');
        $this->suggester->generate($this->period);

        ($this->removePunches)(null, '2026-09-15');
        ($this->removePunches)(null, '2026-09-29');

        $types = collect($this->suggester->conflicts($this->period))->where('type', 'absence_without_imported_punches')->pluck('date')->all();

        expect(BenefitSuggestionRun::query()->orderBy('id')->pluck('created')->all())->toBe([1, 0, 1])
            ->and($types)->toBe(['2026-09-15', '2026-09-29']);
    });

    test('fewer suggestions than the runs created makes the origin unprovable', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15', '2026-09-16']);
        ($this->completeImport)();
        $this->suggester->generate($this->period);
        [$kept, $removed] = BenefitAdjustment::query()->orderBy('starts_on')->get()->all();
        DB::table('benefit_adjustments')->where('id', $removed->id)->delete();

        ($this->removePunches)(null, '2026-09-15');

        expect(BenefitSuggestionRun::sole()->created)->toBe(2)
            ->and(($this->conflictsOf)($kept))->toBeEmpty();
    });

    test('a rejected suggestion still counts as created by the run', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15', '2026-09-16']);
        ($this->completeImport)();
        $this->suggester->generate($this->period);
        [$kept, $rejected] = BenefitAdjustment::query()->orderBy('starts_on')->get()->all();
        $this->registrar->reject($rejected, 'Treinamento externo.');

        ($this->removePunches)(null, '2026-09-15');
        ($this->removePunches)(null, '2026-09-16');

        expect(($this->conflictsOf)($kept)->sole()['type'])->toBe('absence_without_imported_punches')
            ->and(($this->conflictsOf)($rejected))->toBeEmpty();
    });

    test('an absence suggestion changed in the review is not reported as lost', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->completeImport)();
        $this->suggester->generate($this->period);
        $changed = $this->registrar->update(BenefitAdjustment::sole(), AdjustmentReason::JustifiedAbsence, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), 'Consulta médica.')['adjustment'];

        ($this->removePunches)(null, '2026-09-15');

        expect($changed->source)->toBe(AdjustmentSource::Timesheet)
            ->and(($this->conflictsOf)($changed))->toBeEmpty();
    });

    test('an absence suggestion without any registered run is not reported as lost', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->completeImport)();
        $legacy = ($this->legacySuggestion)('2026-09-15');

        ($this->removePunches)(null, '2026-09-15');

        expect(BenefitSuggestionRun::count())->toBe(0)
            ->and(($this->conflictsOf)($legacy))->toBeEmpty();
    });

    test('a suggestion of an employee who is no longer a participant is still checked', function () {
        $outsider = Employee::factory()->create(['name' => 'Bruno Lima']);
        ($this->completeImport)();
        $saturday = $this->registrar->registerSuggestion($this->period, $outsider, AdjustmentReason::SaturdayWorked, CarbonImmutable::parse('2026-09-12'))['adjustment'];

        $conflict = ($this->conflictsOf)($saturday)->sole();

        expect($conflict['type'])->toBe('work_without_punch')
            ->and($conflict['employee_id'])->toBe($outsider->id);
    });

    test('rejected suggestions are not reported', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->punch)($this->employee, '2026-09-12');
        ($this->completeImport)();
        $this->suggester->generate($this->period);
        BenefitAdjustment::query()->get()->each(fn (BenefitAdjustment $adjustment) => $this->registrar->reject($adjustment, 'Não procede.'));

        ($this->removePunches)($this->employee, '2026-09-12');
        ($this->punch)($this->employee, '2026-09-15');

        expect(collect($this->suggester->conflicts($this->period))->whereNotNull('adjustment_id'))->toBeEmpty();
    });

    test('a closed competence recommends a retroactive manual adjustment', function () {
        ($this->presentOnBusinessDays)($this->employee);
        ($this->punch)($this->employee, '2026-09-12');
        ($this->completeImport)();
        $this->suggester->generate($this->period);
        $saturday = $this->registrar->confirm(BenefitAdjustment::sole());
        $this->period->forceFill(['status' => BenefitPeriodStatus::Closed])->save();

        ($this->removePunches)($this->employee, '2026-09-12');

        expect(($this->conflictsOf)($saturday)->sole()['action'])
            ->toBe('Competência 10/2026 fechada: se a correção proceder, lance um ajuste Manual retroativo na competência seguinte. Não reabra a competência apenas por isso.')
            ->and($this->period->fresh()->status)->toBe(BenefitPeriodStatus::Closed);
    });

    test('detecting conflicts writes nothing and keeps adjustments in every status unchanged', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15', '2026-09-16', '2026-09-29']);
        ($this->punch)($this->employee, '2026-09-12');
        ($this->punch)($this->employee, '2026-09-19');
        ($this->completeImport)();
        $this->suggester->generate($this->period);
        $adjustments = BenefitAdjustment::query()->orderBy('starts_on')->get();
        $this->registrar->confirm($adjustments[1]);
        $this->registrar->reject($adjustments[2], 'Treinamento externo.');
        $this->period->forceFill(['status' => BenefitPeriodStatus::Calculated, 'business_days' => 21])->save();

        ($this->removePunches)($this->employee, '2026-09-12');
        ($this->removePunches)($this->employee, '2026-09-19');
        ($this->punch)($this->employee, '2026-09-15', 'manual');
        ($this->punch)($this->employee, '2026-09-16', 'manual');
        ($this->removePunches)(null, '2026-10-01');
        ($this->removePunches)(null, '2026-09-30');

        $snapshot = fn () => [
            DB::table('benefit_adjustments')->orderBy('id')->get()->toArray(),
            DB::table('benefit_adjustment_impacts')->orderBy('id')->get()->toArray(),
            DB::table('benefit_periods')->orderBy('id')->get()->toArray(),
            DB::table('benefit_period_status_changes')->orderBy('id')->get()->toArray(),
            DB::table('benefit_suggestion_runs')->orderBy('id')->get()->toArray(),
            DB::table('points')->orderBy('id')->get()->toArray(),
        ];
        $before = $snapshot();

        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $first = $this->suggester->conflicts($this->period);
        $second = $this->suggester->conflicts($this->period);

        expect($writes)->toBe([])
            ->and($second)->toBe($first)
            ->and($snapshot())->toEqual($before)
            ->and(conflictTypes($first))->toContain('work_without_punch', 'absence_with_punch', 'suggestion_beyond_coverage');
    });

    test('generating again after corrections creates no duplicates', function () {
        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->punch)($this->employee, '2026-09-12');
        ($this->completeImport)();
        $this->suggester->generate($this->period);

        ($this->removePunches)($this->employee, '2026-09-12');
        ($this->punch)($this->employee, '2026-09-15', 'manual');

        expect($this->suggester->generate($this->period))->toMatchArray(['created' => 0])
            ->and(BenefitAdjustment::count())->toBe(2)
            ->and(BenefitAdjustment::query()->pluck('status')->unique()->all())->toBe([AdjustmentStatus::Pending]);
    });

    test('reading conflicts does not grow with employees or suggestions', function () {
        $countReads = function (): int {
            $reads = 0;
            DB::listen(function (QueryExecuted $query) use (&$reads) {
                if (preg_match('/^\s*select/i', $query->sql)) {
                    $reads++;
                }
            });
            $this->suggester->conflicts($this->period);

            return $reads;
        };

        ($this->presentOnBusinessDays)($this->employee, except: ['2026-09-15']);
        ($this->completeImport)();
        $this->suggester->generate($this->period);
        $small = $countReads();

        foreach (range(1, 5) as $index) {
            $employee = ($this->participant)();
            ($this->presentOnBusinessDays)($employee, except: ['2026-09-16', '2026-09-17']);
            ($this->punch)($employee, '2026-09-12');
        }
        $this->suggester->generate($this->period);
        Point::query()->whereDate('date', '2026-09-12')->delete();

        expect($countReads())->toBe($small);
    });

    test('the conflicts panel shows the coverage, the recommended action and the affected adjustment', function () {
        ($this->presentOnBusinessDays)($this->employee);
        ($this->punch)($this->employee, '2026-09-12');
        ($this->completeImport)();
        $this->suggester->generate($this->period);
        $saturday = BenefitAdjustment::sole();
        ($this->removePunches)($this->employee, '2026-09-12');

        Livewire::test(BenefitPeriodAdjustments::class, ['benefitPeriod' => $this->period])
            ->assertSeeText('Trabalho sem batida (12/09/2026)')
            ->assertSeeText('Cobertura do ponto: Ponto coberto até 30/09/2026, mas nenhuma batida importada nesta data.')
            ->assertSeeText('Ação recomendada (revisão manual): Revise o ajuste #'.$saturday->id.': rejeite com nota ou altere o motivo.')
            ->assertSeeHtml('href="#adjustment-'.$saturday->id.'"')
            ->assertSeeHtml('id="adjustment-'.$saturday->id.'"');

        expect($saturday->fresh()->status)->toBe(AdjustmentStatus::Pending);
    });
});
