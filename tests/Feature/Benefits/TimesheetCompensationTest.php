<?php

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\AdjustmentStatus;
use App\Enums\AdjustmentTiming;
use App\Enums\BenefitPeriodStatus;
use App\Enums\BenefitType;
use App\Livewire\Benefits\BenefitPeriodAdjustments;
use App\Models\BenefitAdjustment;
use App\Models\BenefitCalculation;
use App\Models\BenefitPeriod;
use App\Models\BenefitPeriodEmployee;
use App\Models\BenefitRate;
use App\Models\BenefitSuggestionRun;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\Holiday;
use App\Models\Point;
use App\Models\User;
use App\Services\BenefitAdjustmentRegistrar;
use App\Services\BenefitPeriodWorkflow;
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
 * Origem O = 10/2026 (janela 01–30/09), destino D = 11/2026 (janela 01–31/10).
 * `closeOrigin` importa o ponto até 29/09 (horizonte 28/09), gera O e a fecha: a
 * cobertura registrada de O vai até 28/09 e a cauda é 29–30/09.
 * `importUntil` importa o ponto da cauda em diante até a data informada; um colega sem
 * benefício bate ponto em todos os dias úteis, então nenhum dia fica sem batidas.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Holiday::factory()->create(['date' => '2026-09-07', 'description' => 'Independência']);

    $this->suggester = app(TimesheetAdjustmentSuggester::class);
    $this->registrar = app(BenefitAdjustmentRegistrar::class);
    $this->origin = BenefitPeriod::factory()->forCompetence('2026-10')->create();
    $this->destination = BenefitPeriod::factory()->forCompetence('2026-11')->create();

    $this->participant = function (string $name, string $from = '2026-01-01', ?string $to = null): Employee {
        $employee = Employee::factory()->create(['name' => $name]);
        EmployeeBenefit::factory()->for($employee)->ofType(BenefitType::Vr)->between($from, $to)->create();

        return $employee;
    };

    $this->punch = fn (Employee $employee, string $date, string $type = 'importado') => Point::factory()
        ->forEmployee($employee)->on($date)->state(['type' => $type])->create();

    $this->presentBetween = function (Employee $employee, string $from, string $to, array $except = []): void {
        app(BusinessCalendar::class)
            ->businessDays(CarbonImmutable::parse($from), CarbonImmutable::parse($to))
            ->map(fn (CarbonImmutable $day) => $day->toDateString())
            ->reject(fn (string $date) => in_array($date, $except, true))
            ->each(fn (string $date) => ($this->punch)($employee, $date));
    };

    $this->colleague = Employee::factory()->create(['name' => 'Colega sem benefício']);
    $this->ana = ($this->participant)('Ana Souza');

    $this->closeOrigin = function (): void {
        ($this->presentBetween)($this->colleague, '2026-09-01', '2026-09-29');
        ($this->presentBetween)($this->ana, '2026-09-01', '2026-09-28');
        $this->suggester->generate($this->origin);
        $this->origin->forceFill(['status' => BenefitPeriodStatus::Closed, 'closed_at' => now()])->save();
    };

    $this->importUntil = function (string $lastDate, array $anaAbsent = [], array $participants = []): void {
        ($this->presentBetween)($this->colleague, '2026-09-30', $lastDate);
        ($this->punch)($this->colleague, $lastDate);

        foreach ([$this->ana, ...$participants] as $employee) {
            ($this->presentBetween)($employee, '2026-09-29', $lastDate, $anaAbsent);
        }
    };

    $this->compensations = fn () => BenefitAdjustment::query()
        ->where('benefit_period_id', $this->destination->id)
        ->where('dedupe_key', 'like', 'timesheet-comp:%')
        ->orderBy('starts_on')
        ->get();

    $this->conflictTypes = fn (BenefitPeriod $period) => collect($this->suggester->conflicts($period))->pluck('type')->all();
});

describe('tail eligibility', function () {
    test('an event after the registered coverage of the closed origin is compensated in the next competence', function () {
        ($this->closeOrigin)();
        $originRun = BenefitSuggestionRun::sole();
        ($this->punch)($this->ana, '2026-09-26');
        ($this->importUntil)('2026-10-31', anaAbsent: ['2026-09-30']);

        $summary = $this->suggester->generate($this->destination);
        $compensation = ($this->compensations)()->sole();

        expect($compensation->employee_id)->toBe($this->ana->id)
            ->and($compensation->reason)->toBe(AdjustmentReason::UnjustifiedAbsence)
            ->and($compensation->source)->toBe(AdjustmentSource::Timesheet)
            ->and($compensation->status)->toBe(AdjustmentStatus::Pending)
            ->and($compensation->starts_on->toDateString())->toBe('2026-09-30')
            ->and($compensation->ends_on->toDateString())->toBe('2026-09-30')
            ->and($this->destination->timingOf($compensation->starts_on, $compensation->ends_on))->toBe(AdjustmentTiming::Retroactive)
            ->and($compensation->dedupe_key)->toBe('timesheet-comp:'.$this->origin->id.':'.$this->ana->id.':2026-09-30:UnjustifiedAbsence')
            ->and($compensation->notes)->toBe('Compensação da competência 10/2026: evento de 30/09/2026 posterior ao ponto apurado naquela competência (cobertura registrada até 28/09/2026 pela execução #'.$originRun->id.' de '.$originRun->created_at->format('d/m/Y H:i').'). Sugerido com o ponto considerado completo até 30/10/2026.')
            ->and($compensation->impacts()->orderBy('benefit_type')->pluck('quantity', 'benefit_type')->all())->toBe(['vd' => -1, 'vr' => -1, 'vt' => -1])
            ->and($summary)->toMatchArray([
                'created' => 1,
                'compensation_created' => 1,
                'tail_status' => 'processable',
                'tail_from' => '2026-09-29',
                'tail_to' => '2026-09-30',
            ]);

        $run = $this->destination->suggestionRuns()->sole();

        expect($run->tail_origin_period_id)->toBe($this->origin->id)
            ->and($run->tail_origin_run_id)->toBe($originRun->id)
            ->and($run->tail_origin_covered_until->toDateString())->toBe('2026-09-28')
            ->and($run->tail_from->toDateString())->toBe('2026-09-29')
            ->and($run->tail_to->toDateString())->toBe('2026-09-30')
            ->and($run->tail_pending_days)->toBe(0)
            ->and([$run->created, $run->compensation_created, $run->compensation_skipped_existing, $run->compensation_skipped_covered, $run->compensation_not_compensable])->toBe([1, 1, 0, 0, 0]);
    });

    test('an event inside the registered coverage is not compensated', function () {
        ($this->presentBetween)($this->colleague, '2026-09-01', '2026-09-29');
        ($this->presentBetween)($this->ana, '2026-09-01', '2026-09-28', except: ['2026-09-15']);
        $this->suggester->generate($this->origin);
        $this->registrar->reject(BenefitAdjustment::sole(), 'Treinamento externo.');
        $this->origin->forceFill(['status' => BenefitPeriodStatus::Closed])->save();
        ($this->importUntil)('2026-10-31');

        $this->suggester->generate($this->destination);

        expect(($this->compensations)())->toBeEmpty();
    });

    test('a worked day in the tail is compensated', function () {
        Holiday::factory()->create(['date' => '2026-09-29', 'description' => 'Feriado municipal']);
        ($this->closeOrigin)();
        ($this->importUntil)('2026-10-31');
        ($this->punch)($this->ana, '2026-09-29');

        $this->suggester->generate($this->destination);

        expect(($this->compensations)()->map(fn (BenefitAdjustment $adjustment) => $adjustment->reason->name.' '.$adjustment->starts_on->toDateString())->all())
            ->toBe(['HolidayWorked 2026-09-29']);
    });

    test('a business day of the tail without imported punches is not compensated and is reported', function () {
        ($this->closeOrigin)();
        ($this->importUntil)('2026-10-31');
        Point::query()->whereDate('date', '2026-09-29')->delete();

        $summary = $this->suggester->generate($this->destination);
        $conflict = collect($this->suggester->conflicts($this->destination))->firstWhere('type', 'day_without_punches');

        expect(($this->compensations)())->toBeEmpty()
            ->and($summary['compensation_skipped_unobserved_days'])->toBe(1)
            ->and($conflict['date'])->toBe('2026-09-29')
            ->and($conflict['message'])->toContain('cauda da competência 10/2026');
    });

    test('a tail before the current horizon waits for the import', function () {
        ($this->closeOrigin)();

        $tail = $this->suggester->tailCoverage($this->destination);

        expect($tail['status'])->toBe('waiting')
            ->and($tail['from']->toDateString())->toBe('2026-09-29')
            ->and($tail['to'])->toBeNull()
            ->and($tail['pending_days'])->toBe(2)
            ->and(fn () => $this->suggester->generate($this->destination))->toThrow(DomainException::class, 'antes do início da janela');

        Livewire::test(BenefitPeriodAdjustments::class, ['benefitPeriod' => $this->destination])
            ->assertSee('Dias a compensar: 29/09/2026 a 30/09/2026, aguardando importação.');

        expect(BenefitAdjustment::query()->where('benefit_period_id', $this->destination->id)->count())->toBe(0);
    });

    test('a closed origin without a registered run produces no tail and a warning', function () {
        $this->origin->forceFill(['status' => BenefitPeriodStatus::Closed])->save();
        ($this->presentBetween)($this->colleague, '2026-09-01', '2026-09-29');
        ($this->importUntil)('2026-10-31', anaAbsent: ['2026-09-30']);

        $summary = $this->suggester->generate($this->destination);
        $run = $this->destination->suggestionRuns()->sole();

        expect(($this->compensations)())->toBeEmpty()
            ->and($summary['tail_status'])->toBe('no_history')
            ->and($run->tail_origin_period_id)->toBe($this->origin->id)
            ->and($run->tail_origin_run_id)->toBeNull()
            ->and($run->tail_origin_covered_until)->toBeNull()
            ->and($run->tail_from)->toBeNull();

        Livewire::test(BenefitPeriodAdjustments::class, ['benefitPeriod' => $this->destination])
            ->assertSee('A competência 10/2026 foi fechada sem execução registrada da geração do ponto. Não é possível verificar quais dias de 01/09/2026 a 30/09/2026 foram apurados. Se houver eventos não considerados, lance ajustes Manuais retroativos nesta competência.');
    });

    test('an origin that is not closed keeps its events', function () {
        ($this->presentBetween)($this->colleague, '2026-09-01', '2026-09-29');
        ($this->presentBetween)($this->ana, '2026-09-01', '2026-09-28');
        $this->suggester->generate($this->origin);
        ($this->importUntil)('2026-10-31', anaAbsent: ['2026-09-30']);

        expect($this->suggester->generate($this->destination)['tail_status'])->toBe('none')
            ->and(($this->compensations)())->toBeEmpty();

        $this->suggester->generate($this->origin);

        expect(BenefitAdjustment::query()->where('benefit_period_id', $this->origin->id)->pluck('starts_on')->map->toDateString()->all())->toBe(['2026-09-30']);
    });

    test('an origin fully covered has nothing to compensate', function () {
        ($this->presentBetween)($this->colleague, '2026-09-01', '2026-09-30');
        ($this->presentBetween)($this->ana, '2026-09-01', '2026-09-30');
        ($this->punch)($this->colleague, '2026-10-01');
        $this->suggester->generate($this->origin);
        $this->origin->forceFill(['status' => BenefitPeriodStatus::Closed])->save();
        ($this->importUntil)('2026-10-31');

        expect($this->suggester->tailCoverage($this->destination)['status'])->toBe('complete')
            ->and($this->suggester->generate($this->destination)['compensation_created'])->toBe(0);
    });

    test('the tail is computed across the year boundary', function () {
        $january = BenefitPeriod::factory()->forCompetence('2027-01')->create();
        $february = BenefitPeriod::factory()->forCompetence('2027-02')->create();
        ($this->presentBetween)($this->colleague, '2026-12-01', '2026-12-29');
        ($this->presentBetween)($this->ana, '2026-12-01', '2026-12-28');
        $this->suggester->generate($january);
        $january->forceFill(['status' => BenefitPeriodStatus::Closed])->save();
        ($this->presentBetween)($this->colleague, '2026-12-30', '2027-01-29');
        ($this->punch)($this->colleague, '2027-01-30');
        ($this->presentBetween)($this->ana, '2026-12-29', '2027-01-29', except: ['2026-12-30']);

        $summary = $this->suggester->generate($february);
        $compensation = BenefitAdjustment::query()->where('benefit_period_id', $february->id)->sole();

        expect($summary)->toMatchArray(['tail_from' => '2026-12-29', 'tail_to' => '2026-12-31', 'compensation_created' => 1])
            ->and($compensation->starts_on->toDateString())->toBe('2026-12-30')
            ->and($compensation->dedupe_key)->toBe('timesheet-comp:'.$january->id.':'.$this->ana->id.':2026-12-30:UnjustifiedAbsence')
            ->and($february->timingOf($compensation->starts_on, $compensation->ends_on))->toBe(AdjustmentTiming::Retroactive);
    });
});

describe('participants', function () {
    test('only participants of both competences are compensated and the others are reported', function () {
        $leaving = ($this->participant)('Bruno Lima', '2026-01-01', '2026-10-31');
        $arriving = ($this->participant)('Carla Dias', '2026-11-01');
        ($this->presentBetween)($leaving, '2026-09-01', '2026-09-28');
        ($this->presentBetween)($arriving, '2026-09-01', '2026-09-28');
        ($this->closeOrigin)();
        ($this->importUntil)('2026-10-31', anaAbsent: ['2026-09-30'], participants: [$leaving, $arriving]);

        $summary = $this->suggester->generate($this->destination);
        $notCompensable = collect($this->suggester->conflicts($this->destination))->where('type', 'tail_not_compensable')->values();

        expect(($this->compensations)()->pluck('employee_id')->all())->toBe([$this->ana->id])
            ->and($summary['compensation_not_compensable'])->toBe(1)
            ->and($notCompensable->pluck('employee_id')->all())->toBe([$leaving->id])
            ->and($notCompensable->sole()['date'])->toBe('2026-09-30')
            ->and($notCompensable->sole()['message'])->toContain('participava da competência 10/2026 e não participa de 11/2026');
    });
});

describe('destination state', function () {
    test('a calculated destination is reopened by the creation of a compensation', function () {
        ($this->closeOrigin)();
        ($this->importUntil)('2026-10-31', anaAbsent: ['2026-09-30']);
        $this->destination->forceFill(['status' => BenefitPeriodStatus::Calculated, 'business_days' => 21])->save();

        $this->suggester->generate($this->destination);

        expect($this->destination->fresh()->status)->toBe(BenefitPeriodStatus::Open)
            ->and($this->destination->statusChanges()->latest('id')->first()->reason)->toBe('Prévia descartada por alteração de ajustes.');
    });

    test('a calculated destination without new compensations keeps its calculation', function () {
        ($this->closeOrigin)();
        ($this->importUntil)('2026-10-31');
        $this->destination->forceFill(['status' => BenefitPeriodStatus::Calculated, 'business_days' => 21])->save();

        $this->suggester->generate($this->destination);

        expect($this->destination->fresh()->status)->toBe(BenefitPeriodStatus::Calculated);
    });

    test('a closed destination receives nothing and reports the uncompensated event', function () {
        ($this->closeOrigin)();
        ($this->importUntil)('2026-10-31', anaAbsent: ['2026-09-30']);
        $this->destination->forceFill(['status' => BenefitPeriodStatus::Closed])->save();

        expect(fn () => $this->suggester->generate($this->destination))->toThrow(DomainException::class, 'está fechada');

        $conflict = collect($this->suggester->conflicts($this->destination))->where('type', 'uncompensated_tail')->sole();

        expect(($this->compensations)())->toBeEmpty()
            ->and($conflict['employee_id'])->toBe($this->ana->id)
            ->and($conflict['date'])->toBe('2026-09-30')
            ->and($conflict['message'])->toContain('cobertura registrada até 28/09/2026')
            ->and($conflict['message'])->toContain('a competência de destino 11/2026 está fechada')
            ->and($conflict['action'])->toContain('lance um ajuste Manual retroativo na próxima competência editável');
    });

    test('with origin and destination closed the event is reported on both pages and created nowhere', function () {
        $december = BenefitPeriod::factory()->forCompetence('2026-12')->create();
        ($this->closeOrigin)();
        ($this->importUntil)('2026-11-30', anaAbsent: ['2026-09-30']);
        $this->destination->forceFill(['status' => BenefitPeriodStatus::Closed])->save();

        $this->suggester->generate($december);

        $onDestination = collect($this->suggester->conflicts($this->destination))->where('type', 'uncompensated_tail');
        $onDecember = collect($this->suggester->conflicts($december))->where('type', 'uncompensated_tail');

        expect(BenefitAdjustment::query()->where('dedupe_key', 'like', 'timesheet-comp:%')->count())->toBe(0)
            ->and($onDestination->pluck('date')->all())->toBe(['2026-09-30'])
            ->and($onDecember->pluck('date')->all())->toBe(['2026-09-30']);
    });
});

describe('idempotency and states', function () {
    beforeEach(function () {
        ($this->closeOrigin)();
        ($this->importUntil)('2026-10-31', anaAbsent: ['2026-09-30']);
        $this->suggester->generate($this->destination);
        $this->compensation = ($this->compensations)()->sole();
    });

    test('repeated runs never duplicate a compensation', function () {
        ($this->punch)($this->colleague, '2026-11-02');

        expect($this->suggester->generate($this->destination))->toMatchArray(['compensation_created' => 0, 'compensation_skipped_existing' => 1])
            ->and($this->suggester->generate($this->destination))->toMatchArray(['compensation_created' => 0, 'compensation_skipped_existing' => 1])
            ->and(($this->compensations)())->toHaveCount(1)
            ->and($this->destination->suggestionRuns()->pluck('compensation_created')->all())->toBe([1, 0, 0]);
    });

    test('the unique key rejects a concurrent duplicate', function () {
        expect(fn () => BenefitAdjustment::factory()->for($this->destination)->for($this->ana)->create(['dedupe_key' => $this->compensation->dedupe_key]))
            ->toThrow(UniqueConstraintViolationException::class);

        $again = $this->registrar->registerCompensation($this->destination, $this->origin, $this->ana, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse('2026-09-30'), 'Repetida.');

        expect($again['created'])->toBeFalse()
            ->and($again['adjustment']->id)->toBe($this->compensation->id);
    });

    test('a compensation is confirmed, rejected and reconsidered through the normal flow', function () {
        $this->registrar->confirm($this->compensation);
        expect($this->compensation->fresh()->status)->toBe(AdjustmentStatus::Confirmed);

        $this->registrar->reject($this->compensation->fresh(), 'Não procede.');
        $this->suggester->generate($this->destination);

        expect($this->compensation->fresh()->status)->toBe(AdjustmentStatus::Rejected)
            ->and(($this->compensations)())->toHaveCount(1);

        $this->registrar->reconsider($this->compensation->fresh());

        expect($this->compensation->fresh()->status)->toBe(AdjustmentStatus::Pending);
    });

    test('editing a compensation changes the reason and notes but never the date', function () {
        $updated = $this->registrar->update($this->compensation, AdjustmentReason::MedicalCertificate, CarbonImmutable::parse('2026-09-30'), CarbonImmutable::parse('2026-09-30'), 'Atestado entregue.')['adjustment'];

        expect($updated->reason)->toBe(AdjustmentReason::MedicalCertificate)
            ->and($updated->notes)->toBe('Atestado entregue.')
            ->and($updated->source)->toBe(AdjustmentSource::Timesheet)
            ->and(fn () => $this->registrar->update($updated->fresh(), AdjustmentReason::MedicalCertificate, CarbonImmutable::parse('2026-09-29'), CarbonImmutable::parse('2026-09-29'), 'Outra data.'))
            ->toThrow(DomainException::class, 'A data de uma compensação não pode ser alterada')
            ->and($updated->fresh()->starts_on->toDateString())->toBe('2026-09-30');
    });

    test('a confirmed compensation enters the calculation of the destination and a rejected one does not', function (bool $confirm, int $negativeDays) {
        BenefitRate::factory()->vr('27.50')->validFrom('2026-01-01')->create();
        $confirm
            ? $this->registrar->confirm($this->compensation)
            : $this->registrar->reject($this->compensation, 'Não procede.');

        app(BenefitPeriodWorkflow::class)->calculate($this->destination);

        $calculation = DB::table('benefit_calculations')
            ->join('benefit_period_employees', 'benefit_period_employees.id', '=', 'benefit_calculations.benefit_period_employee_id')
            ->where('benefit_period_employees.benefit_period_id', $this->destination->id)
            ->where('benefit_period_employees.employee_id', $this->ana->id)
            ->where('benefit_calculations.benefit_type', BenefitType::Vr->value)
            ->first(['base_days', 'negative_days', 'final_days']);

        expect((int) $calculation->negative_days)->toBe($negativeDays)
            ->and((int) $calculation->final_days)->toBe((int) $calculation->base_days - $negativeDays);
    })->with([
        'confirmed' => [true, 1],
        'rejected' => [false, 0],
    ]);

    test('the screen edits the reason of a compensation and refuses a new date', function () {
        Livewire::test(BenefitPeriodAdjustments::class, ['benefitPeriod' => $this->destination])
            ->call('openUpdateModal', $this->compensation->id)
            ->set('formReason', AdjustmentReason::MedicalCertificate->value)
            ->set('formNotes', 'Atestado entregue.')
            ->call('save')
            ->assertHasNoErrors()
            ->call('openUpdateModal', $this->compensation->id)
            ->set('formStartsOn', '2026-09-29')
            ->set('formEndsOn', '2026-09-29')
            ->call('save')
            ->assertHasErrors('form');

        expect($this->compensation->fresh()->reason)->toBe(AdjustmentReason::MedicalCertificate)
            ->and($this->compensation->fresh()->starts_on->toDateString())->toBe('2026-09-30')
            ->and($this->compensation->fresh()->dedupe_key)->toBe('timesheet-comp:'.$this->origin->id.':'.$this->ana->id.':2026-09-30:UnjustifiedAbsence');
    });

    test('reopening the origin creates no compensation and the origin skips the compensated day', function () {
        $this->origin->forceFill(['status' => BenefitPeriodStatus::Open])->save();

        expect($this->suggester->generate($this->destination)['tail_status'])->toBe('none')
            ->and($this->suggester->generate($this->origin))->toMatchArray(['created' => 0, 'skipped_covered' => 1])
            ->and(BenefitAdjustment::query()->where('benefit_period_id', $this->origin->id)->count())->toBe(0)
            ->and(($this->compensations)())->toHaveCount(1);
    });
});

describe('restricted exception', function () {
    beforeEach(function () {
        ($this->closeOrigin)();
        ($this->importUntil)('2026-10-31', anaAbsent: ['2026-09-30']);
        $this->compensate = fn (string $date, ?BenefitPeriod $destination = null, ?BenefitPeriod $origin = null, ?Employee $employee = null) => $this->registrar->registerCompensation(
            $destination ?? $this->destination,
            $origin ?? $this->origin,
            $employee ?? $this->ana,
            AdjustmentReason::UnjustifiedAbsence,
            CarbonImmutable::parse($date),
            'Compensação de teste.',
        );
    });

    test('the generic register still refuses a retroactive timesheet adjustment', function () {
        expect(fn () => $this->registrar->register($this->destination, $this->ana, AdjustmentReason::UnjustifiedAbsence, AdjustmentSource::Timesheet, CarbonImmutable::parse('2026-09-30'), CarbonImmutable::parse('2026-09-30'), 'Tentativa.'))
            ->toThrow(DomainException::class, 'Sugestões do ponto devem estar dentro da janela de eventos realizados')
            ->and(fn () => $this->registrar->registerSuggestion($this->destination, $this->ana, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse('2026-09-30')))
            ->toThrow(DomainException::class, 'Sugestões do ponto devem estar dentro da janela de eventos realizados');
    });

    test('a regular suggestion cannot be moved to a retroactive date', function () {
        $regular = $this->registrar->registerSuggestion($this->destination, $this->ana, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse('2026-10-15'))['adjustment'];

        expect(fn () => $this->registrar->update($regular, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse('2026-09-30'), CarbonImmutable::parse('2026-09-30'), 'Mover.'))
            ->toThrow(DomainException::class, 'Sugestões do ponto devem estar dentro da janela de eventos realizados');
    });

    test('a compensation outside the tail is refused', function (string $date) {
        expect(fn () => ($this->compensate)($date))->toThrow(DomainException::class, 'não está na cauda da competência 10/2026 (29/09/2026 a 30/09/2026)');
    })->with(['inside the coverage' => '2026-09-28', 'after the origin window' => '2026-10-01']);

    test('a compensation from an origin that is not closed is refused', function () {
        $this->origin->forceFill(['status' => BenefitPeriodStatus::Calculated])->save();

        expect(fn () => ($this->compensate)('2026-09-30'))->toThrow(DomainException::class, 'não está fechada');
    });

    test('a compensation outside the next competence is refused', function () {
        $december = BenefitPeriod::factory()->forCompetence('2026-12')->create();

        expect(fn () => ($this->compensate)('2026-09-30', destination: $december))->toThrow(DomainException::class, 'imediatamente seguinte');
    });

    test('a compensation into a closed destination is refused', function () {
        $this->destination->forceFill(['status' => BenefitPeriodStatus::Closed])->save();

        expect(fn () => ($this->compensate)('2026-09-30'))->toThrow(DomainException::class, 'está fechada');
    });

    test('a compensation for an employee outside both competences is refused', function () {
        $arriving = ($this->participant)('Carla Dias', '2026-11-01');

        expect(fn () => ($this->compensate)('2026-09-30', employee: $arriving))->toThrow(DomainException::class, 'participe da competência de origem e da de destino');
    });

    test('a compensation from an origin without a registered run is refused', function () {
        $september = BenefitPeriod::factory()->forCompetence('2026-09')->closed()->create();
        $this->origin->forceFill(['status' => BenefitPeriodStatus::Open])->save();

        expect(fn () => ($this->compensate)('2026-08-31', destination: $this->origin, origin: $september))->toThrow(DomainException::class, 'não tem execução registrada');
    });
});

describe('conflicts of compensations', function () {
    beforeEach(function () {
        ($this->closeOrigin)();
        ($this->importUntil)('2026-10-31', anaAbsent: ['2026-09-30']);
        $this->suggester->generate($this->destination);
        $this->compensation = ($this->compensations)()->sole();
        $this->conflictsOf = fn (BenefitPeriod $page) => collect($this->suggester->conflicts($page))->where('adjustment_id', $this->compensation->id)->values();
    });

    test('a punch added to a compensated absence is reported once on each page with the same action', function () {
        ($this->punch)($this->ana, '2026-09-30', 'manual');

        $onDestination = ($this->conflictsOf)($this->destination)->sole();
        $onOrigin = ($this->conflictsOf)($this->origin)->sole();

        expect($onDestination['type'])->toBe('absence_with_punch')
            ->and($onDestination['date'])->toBe('2026-09-30')
            ->and($onDestination['message'])->toContain('competência 11/2026, compensação da competência 10/2026')
            ->and($onOrigin['type'])->toBe('absence_with_punch')
            ->and($onOrigin['action'])->toBe($onDestination['action'])
            ->and($onDestination['action'])->toBe('Revise o ajuste #'.$this->compensation->id.': rejeite com nota ou altere o motivo.');
    });

    test('a compensated absence whose day lost every imported punch is reported', function () {
        Point::query()->whereDate('date', '2026-09-30')->delete();

        expect(($this->conflictsOf)($this->destination)->sole()['type'])->toBe('absence_without_imported_punches');
    });

    test('a horizon that moves back before a compensation reports the run that processed the tail', function () {
        Point::query()->whereDate('date', '>=', '2026-09-30')->delete();

        $conflict = ($this->conflictsOf)($this->destination)->sole();

        expect($conflict['type'])->toBe('suggestion_beyond_coverage')
            ->and($conflict['message'])->toContain('foi sugerido com o ponto coberto até 30/09/2026')
            ->and($conflict['message'])->toContain('o ponto atual é considerado completo somente até 28/09/2026');
    });

    test('a compensation outside the horizon is reported with the run that processed the tail', function () {
        $other = ($this->participant)('Bruno Lima');
        $direct = $this->registrar->registerCompensation($this->destination, $this->origin, $other, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse('2026-09-29'), 'Direta.')['adjustment'];
        Point::query()->whereDate('date', '>=', '2026-09-29')->delete();

        expect(collect($this->suggester->conflicts($this->destination))->where('adjustment_id', $direct->id)->sole()['type'])->toBe('suggestion_beyond_coverage');
    });

    test('a compensated worked day whose punch was removed is reported', function () {
        Holiday::factory()->create(['date' => '2026-09-29', 'description' => 'Feriado municipal']);
        $this->registrar->reject($this->compensation, 'Não procede.');
        ($this->punch)($this->colleague, '2026-11-02');
        $this->suggester->generate($this->destination);
        $holiday = ($this->compensations)()->firstWhere('reason', AdjustmentReason::HolidayWorked);

        Point::query()->where('pis', $this->ana->pis)->whereDate('date', '2026-09-29')->delete();

        expect(collect($this->suggester->conflicts($this->destination))->where('adjustment_id', $holiday->id)->sole()['type'])->toBe('work_without_punch');
    });

    test('an existing compensation is never also reported as uncompensated', function () {
        $this->destination->forceFill(['status' => BenefitPeriodStatus::Closed])->save();

        expect(($this->conflictTypes)($this->destination))->not->toContain('uncompensated_tail')
            ->and(collect($this->suggester->conflicts($this->destination))->groupBy(fn (array $conflict) => $conflict['type'].$conflict['employee_id'].$conflict['date'])->every(fn ($group) => $group->count() === 1))->toBeTrue();
    });

    test('detecting conflicts writes nothing', function () {
        ($this->punch)($this->ana, '2026-09-30', 'manual');
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $first = $this->suggester->conflicts($this->destination);

        expect($this->suggester->conflicts($this->destination))->toBe($first)
            ->and($this->suggester->conflicts($this->origin))->not->toBeEmpty()
            ->and($writes)->toBe([]);
    });
});

describe('compensation without a destination run', function () {
    test('a compensation outside the horizon without a run that processed it has an unknown previous coverage', function () {
        ($this->closeOrigin)();
        ($this->importUntil)('2026-10-31');
        $direct = $this->registrar->registerCompensation($this->destination, $this->origin, $this->ana, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse('2026-09-30'), 'Direta.')['adjustment'];
        Point::query()->whereDate('date', '>=', '2026-09-30')->delete();

        $conflict = collect($this->suggester->conflicts($this->destination))->where('adjustment_id', $direct->id)->sole();

        expect($this->destination->suggestionRuns()->count())->toBe(0)
            ->and($conflict['type'])->toBe('suggestion_coverage_unknown')
            ->and($conflict['message'])->toContain('a cobertura anterior é desconhecida')
            ->and($conflict['message'])->toContain('o ponto atual é considerado completo somente até 28/09/2026');
    });
});

describe('origin preservation and locking', function () {
    test('the closed origin and its snapshot are unchanged', function () {
        ($this->closeOrigin)();
        $periodEmployee = BenefitPeriodEmployee::factory()->for($this->origin)->create(['employee_id' => $this->ana->id]);
        BenefitCalculation::factory()->create(['benefit_period_employee_id' => $periodEmployee->id]);
        ($this->importUntil)('2026-10-31', anaAbsent: ['2026-09-30']);

        $snapshot = fn () => [
            DB::table('benefit_periods')->where('id', $this->origin->id)->get()->toArray(),
            DB::table('benefit_period_employees')->where('benefit_period_id', $this->origin->id)->get()->toArray(),
            DB::table('benefit_calculations')->orderBy('id')->get()->toArray(),
            DB::table('benefit_period_status_changes')->where('benefit_period_id', $this->origin->id)->orderBy('id')->get()->toArray(),
            DB::table('benefit_adjustments')->where('benefit_period_id', $this->origin->id)->orderBy('id')->get()->toArray(),
            DB::table('benefit_suggestion_runs')->where('benefit_period_id', $this->origin->id)->orderBy('id')->get()->toArray(),
        ];
        $before = $snapshot();

        $this->suggester->generate($this->destination);
        $this->suggester->conflicts($this->destination);
        $this->suggester->conflicts($this->origin);

        expect($snapshot())->toEqual($before)
            ->and(($this->compensations)())->toHaveCount(1);
    });

    test('the origin is locked before the destination', function () {
        ($this->closeOrigin)();
        ($this->importUntil)('2026-10-31', anaAbsent: ['2026-09-30']);

        $locks = [];
        DB::listen(function (QueryExecuted $query) use (&$locks) {
            if (preg_match('/from [`"]benefit_periods[`"] where [`"]benefit_periods[`"]\.[`"]id[`"] = \?/i', $query->sql)) {
                $locks[] = ['id' => $query->bindings[0], 'for_update' => str_contains(strtolower($query->sql), 'for update')];
            }
        });

        $this->suggester->generate($this->destination);

        expect(array_column(array_slice($locks, 0, 2), 'id'))->toBe([$this->origin->id, $this->destination->id]);

        if (DB::getDriverName() === 'mysql') {
            expect(array_column(array_slice($locks, 0, 2), 'for_update'))->toBe([true, true]);
        }
    });
});

describe('screen', function () {
    test('the destination shows the tail, the summary and the compensation', function () {
        ($this->closeOrigin)();
        $originRun = BenefitSuggestionRun::sole();
        ($this->importUntil)('2026-10-31', anaAbsent: ['2026-09-30']);

        Livewire::test(BenefitPeriodAdjustments::class, ['benefitPeriod' => $this->destination])
            ->assertSee('Compensação da competência 10/2026: cobertura registrada até 28/09/2026 (execução #'.$originRun->id)
            ->assertSee('Dias a compensar: 29/09/2026 a 30/09/2026 processáveis')
            ->call('generateSuggestions')
            ->assertSet('generationSummary.compensation_created', 1)
            ->assertSee('Compensações da competência anterior (29/09/2026 a 30/09/2026): 1 criada(s)')
            ->assertSee('cauda 29/09/2026 a 30/09/2026: 1 compensação(ões) criada(s)')
            ->assertSee('Compensação da competência 10/2026: evento de 30/09/2026');
    });

    test('the closed destination lists the uncompensated and the not compensable events', function () {
        $leaving = ($this->participant)('Bruno Lima', '2026-01-01', '2026-10-31');
        ($this->presentBetween)($leaving, '2026-09-01', '2026-09-28');
        ($this->closeOrigin)();
        ($this->importUntil)('2026-10-31', anaAbsent: ['2026-09-30'], participants: [$leaving]);
        $this->destination->forceFill(['status' => BenefitPeriodStatus::Closed])->save();

        Livewire::test(BenefitPeriodAdjustments::class, ['benefitPeriod' => $this->destination])
            ->assertSeeText('Evento da cauda não compensado (30/09/2026)')
            ->assertSeeText('Evento da cauda sem compensação possível (30/09/2026)');
    });
});
