<?php

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\AdjustmentStatus;
use App\Enums\BenefitPeriodStatus;
use App\Enums\BenefitType;
use App\Livewire\Benefits\BenefitPeriodCalculation;
use App\Livewire\Benefits\BenefitRateIndex;
use App\Models\BenefitAdjustment;
use App\Models\BenefitCalculation;
use App\Models\BenefitCalculationTransportItem;
use App\Models\BenefitPeriod;
use App\Models\BenefitPeriodEmployee;
use App\Models\BenefitPeriodStatusChange;
use App\Models\BenefitRate;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\Holiday;
use App\Models\TransportFare;
use App\Models\TransportFarePrice;
use App\Models\TransportRoute;
use App\Models\User;
use App\Services\BenefitAdjustmentRegistrar;
use App\Services\BenefitPeriodWorkflow;
use App\Services\BenefitRateRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->workflow = app(BenefitPeriodWorkflow::class);
});

describe('creation', function () {
    test('the first competence must be informed explicitly', function () {
        expect(fn () => $this->workflow->createNext())->toThrow(DomainException::class, 'primeira competência');
        expect(BenefitPeriod::count())->toBe(0);
    });

    test('the first competence is created on the first day of the informed month', function () {
        $period = $this->workflow->createNext(CarbonImmutable::parse('2026-09-17'));

        expect($period->competence->toDateString())->toBe('2026-09-01')
            ->and(DB::table('benefit_periods')->value('competence'))->toBe('2026-09-01')
            ->and($period->fresh()->status)->toBe(BenefitPeriodStatus::Open)
            ->and($period->fresh()->created_by)->toBe($this->user->id)
            ->and($period->fresh()->business_days)->toBeNull();
    });

    test('the next competence is the month after the latest one', function () {
        $this->workflow->createNext(CarbonImmutable::parse('2026-09-01'));

        $october = $this->workflow->createNext();
        $november = $this->workflow->createNext();

        expect($october->competence->toDateString())->toBe('2026-10-01')
            ->and($november->competence->toDateString())->toBe('2026-11-01');
    });

    test('the sequence crosses the year', function () {
        BenefitPeriod::factory()->forCompetence('2026-12')->closed()->create();

        expect($this->workflow->createNext()->competence->toDateString())->toBe('2027-01-01');
    });

    test('the next competence does not depend on the latest status', function (string $state) {
        BenefitPeriod::factory()->forCompetence('2026-09')->{$state}()->create();

        expect($this->workflow->createNext()->competence->toDateString())->toBe('2026-10-01');
    })->with(['calculated', 'closed']);

    test('a competence cannot be skipped', function () {
        BenefitPeriod::factory()->forCompetence('2026-09')->create();

        expect(fn () => $this->workflow->createNext(CarbonImmutable::parse('2026-11-01')))
            ->toThrow(DomainException::class, '10/2026');
        expect(BenefitPeriod::count())->toBe(1);
    });

    test('informing the next month explicitly is accepted', function () {
        BenefitPeriod::factory()->forCompetence('2026-09')->create();

        expect($this->workflow->createNext(CarbonImmutable::parse('2026-10-01'))->competence->toDateString())->toBe('2026-10-01');
    });

    test('an existing competence cannot be created again', function () {
        BenefitPeriod::factory()->forCompetence('2026-09')->create();
        BenefitPeriod::factory()->forCompetence('2026-10')->create();

        expect(fn () => $this->workflow->createNext(CarbonImmutable::parse('2026-10-01')))
            ->toThrow(DomainException::class);
        expect(BenefitPeriod::count())->toBe(2);
    });

    test('a duplicate caught by the database becomes a friendly error', function () {
        BenefitPeriod::factory()->forCompetence('2026-09')->create();

        BenefitPeriod::creating(function (BenefitPeriod $period) {
            DB::table('benefit_periods')->insert([
                'competence' => $period->competence->toDateString(),
                'status' => BenefitPeriodStatus::Open->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        expect(fn () => $this->workflow->createNext())->toThrow(DomainException::class, 'já foi criada');
    });
});

describe('event window', function () {
    test('the window of realized events is the previous calendar month', function (string $competence, string $start, string $end) {
        $period = BenefitPeriod::factory()->forCompetence($competence)->create();

        expect($period->referenceDate()->toDateString())->toBe($competence.'-01')
            ->and($period->windowStart()->toDateString())->toBe($start)
            ->and($period->windowEnd()->toDateString())->toBe($end);
    })->with([
        'october' => ['2026-10', '2026-09-01', '2026-09-30'],
        'february' => ['2026-02', '2026-01-01', '2026-01-31'],
        'march after february' => ['2026-03', '2026-02-01', '2026-02-28'],
        'march after leap february' => ['2028-03', '2028-02-01', '2028-02-29'],
        'year change' => ['2027-01', '2026-12-01', '2026-12-31'],
    ]);

    test('the month end of the competence is its last day', function () {
        expect(BenefitPeriod::factory()->forCompetence('2026-02')->create()->monthEnd()->toDateString())->toBe('2026-02-28');
    });
});

describe('history', function () {
    test('creation records the first status change', function () {
        $period = $this->workflow->createNext(CarbonImmutable::parse('2026-10-01'));

        $change = $period->statusChanges()->sole();

        expect($change->from_status)->toBeNull()
            ->and($change->to_status)->toBe(BenefitPeriodStatus::Open)
            ->and($change->reason)->toBe('Competência criada.')
            ->and($change->user_id)->toBe($this->user->id)
            ->and($change->created_at)->not->toBeNull();
    });

    test('status changes cannot be updated or deleted individually', function () {
        $period = $this->workflow->createNext(CarbonImmutable::parse('2026-10-01'));
        $change = $period->statusChanges()->sole();

        expect(fn () => $change->update(['reason' => 'alterado']))->toThrow(LogicException::class)
            ->and(fn () => $change->delete())->toThrow(LogicException::class);

        expect($change->fresh()->reason)->toBe('Competência criada.');
    });

    test('new transitions append to the history', function () {
        $period = BenefitPeriod::factory()->forCompetence('2026-10')->calculated()->create();
        BenefitPeriodStatusChange::factory()->for($period)->create(['to_status' => BenefitPeriodStatus::Open]);

        $this->workflow->invalidate($period, 'Ajuste alterado.');

        expect($period->statusChanges()->orderBy('id')->get()->map(fn ($change) => [$change->from_status, $change->to_status])->all())
            ->toBe([[null, BenefitPeriodStatus::Open], [BenefitPeriodStatus::Calculated, BenefitPeriodStatus::Open]]);
    });
});

describe('invalidation', function () {
    test('a calculated competence goes back to open discarding the preview', function () {
        $period = BenefitPeriod::factory()->forCompetence('2026-10')->calculated()->create(['business_days' => 21, 'calculated_by' => $this->user->id]);
        $calculation = BenefitCalculation::factory()->for(BenefitPeriodEmployee::factory()->for($period))->create();

        expect($this->workflow->invalidate($period, 'Ajuste alterado.'))->toBeTrue();

        $period->refresh();

        expect($period->status)->toBe(BenefitPeriodStatus::Open)
            ->and($period->only('business_days', 'calculated_at', 'calculated_by'))
            ->toBe(['business_days' => null, 'calculated_at' => null, 'calculated_by' => null])
            ->and($period->periodEmployees()->exists())->toBeFalse();
        $this->assertModelMissing($calculation);

        $change = $period->statusChanges()->sole();
        expect($change->from_status)->toBe(BenefitPeriodStatus::Calculated)
            ->and($change->to_status)->toBe(BenefitPeriodStatus::Open)
            ->and($change->reason)->toBe('Ajuste alterado.');
    });

    test('an open competence is left untouched', function () {
        $period = BenefitPeriod::factory()->forCompetence('2026-10')->create();

        expect($this->workflow->invalidate($period))->toBeFalse()
            ->and($period->statusChanges()->count())->toBe(0);
    });

    test('a closed competence cannot be invalidated', function () {
        $period = BenefitPeriod::factory()->forCompetence('2026-10')->closed()->create();

        expect(fn () => $this->workflow->invalidate($period))->toThrow(DomainException::class, 'fechada');
        expect($period->fresh()->status)->toBe(BenefitPeriodStatus::Closed);
    });
});

describe('deletion', function () {
    test('the latest open competence without snapshot can be deleted with its history', function () {
        $this->workflow->createNext(CarbonImmutable::parse('2026-09-01'));
        $october = $this->workflow->createNext();

        expect($this->workflow->deletionBlocker($october))->toBeNull();

        $this->workflow->delete($october);

        $this->assertModelMissing($october);
        expect(BenefitPeriodStatusChange::where('benefit_period_id', $october->id)->count())->toBe(0)
            ->and(BenefitPeriod::sole()->competence->toDateString())->toBe('2026-09-01');
    });

    test('calculated and closed competences cannot be deleted', function (string $state) {
        $period = BenefitPeriod::factory()->forCompetence('2026-10')->{$state}()->create();

        expect(fn () => $this->workflow->delete($period))->toThrow(DomainException::class, 'abertas');
        $this->assertModelExists($period);
    })->with(['calculated', 'closed']);

    test('an older competence cannot be deleted while a later one exists', function () {
        $september = BenefitPeriod::factory()->forCompetence('2026-09')->create();
        BenefitPeriod::factory()->forCompetence('2026-10')->create();

        expect(fn () => $this->workflow->delete($september))->toThrow(DomainException::class, 'mais recente');
        $this->assertModelExists($september);
    });

    test('a competence with a snapshot cannot be deleted', function () {
        $period = BenefitPeriod::factory()->forCompetence('2026-10')->create();
        BenefitPeriodEmployee::factory()->for($period)->create();

        expect(fn () => $this->workflow->delete($period))->toThrow(DomainException::class, 'apuração');
        $this->assertModelExists($period);
    });

    test('a competence with adjustments cannot be deleted', function () {
        $period = BenefitPeriod::factory()->forCompetence('2026-10')->create();
        BenefitAdjustment::factory()->for($period)->create();

        expect(fn () => $this->workflow->delete($period))->toThrow(DomainException::class, 'ajustes');
        $this->assertModelExists($period);
    });

    test('after deleting the latest the previous one becomes deletable', function () {
        $september = BenefitPeriod::factory()->forCompetence('2026-09')->create();
        $october = BenefitPeriod::factory()->forCompetence('2026-10')->create();

        expect($this->workflow->deletionBlocker($september))->not->toBeNull();

        $this->workflow->delete($october);

        expect($this->workflow->deletionBlocker($september->fresh()))->toBeNull();
    });
});

/**
 * F9 — fechamento, reabertura e imutabilidade. Setembro/2026 é a primeira competência
 * (janela agosto); outubro/2026 é a seguinte (janela setembro).
 */
describe('closing and reopening', function () {
    beforeEach(function () {
        Holiday::factory()->create(['date' => '2026-09-07', 'description' => 'Independência']);
        Holiday::factory()->create(['date' => '2026-10-12', 'description' => 'Nossa Senhora Aparecida']);

        $this->vr = BenefitRate::factory()->vr('27.50')->validFrom('2026-01-01')->create();
        $this->vd = BenefitRate::factory()->vd('7.50')->validFrom('2026-01-01')->create();
        $this->fare = TransportFare::factory()->create(['name' => 'CPTM']);
        $this->price = TransportFarePrice::factory()->for($this->fare)->amount('5.40')->validFrom('2026-01-01')->create();

        $this->employee = Employee::factory()->create(['name' => 'João Silva']);
        foreach (BenefitType::cases() as $type) {
            EmployeeBenefit::factory()->for($this->employee)->ofType($type)->between('2026-01-01')->create();
        }
        $this->route = TransportRoute::factory()->for($this->employee)->for($this->fare)->tripsPerDay(2)->between('2026-01-01')->create();

        $this->registrar = app(BenefitAdjustmentRegistrar::class);
        $this->september = BenefitPeriod::factory()->forCompetence('2026-09')->create();
        $this->october = BenefitPeriod::factory()->forCompetence('2026-10')->create();

        $this->calc = fn (BenefitType $type, BenefitPeriod $period): ?BenefitCalculation => BenefitCalculation::query()
            ->whereHas('benefitPeriodEmployee', fn ($query) => $query->where('benefit_period_id', $period->id)->where('employee_id', $this->employee->id))
            ->where('benefit_type', $type)
            ->first();

        $this->closeSeptember = function (): void {
            $this->workflow->calculate($this->september);
            $this->workflow->close($this->september);
        };
    });

    describe('closing', function () {
        test('F9-01 a calculated competence is closed', function () {
            $this->workflow->calculate($this->september);

            $this->workflow->close($this->september);

            $september = $this->september->fresh();

            expect($september->status)->toBe(BenefitPeriodStatus::Closed)
                ->and($september->closed_by)->toBe($this->user->id)
                ->and($september->closed_at)->not->toBeNull()
                ->and(($this->calc)(BenefitType::Vr, $september)->final_days)->toBe(21);
        });

        test('only a calculated competence can be closed', function () {
            expect(fn () => $this->workflow->close($this->september))->toThrow(DomainException::class, 'Somente competências calculadas');

            expect($this->september->fresh()->status)->toBe(BenefitPeriodStatus::Open);
        });

        test('F9-02 closing recalculates even when a snapshot exists', function () {
            $this->workflow->calculate($this->september);
            $previewId = ($this->calc)(BenefitType::Vr, $this->september)->id;

            BenefitRate::factory()->vr('30.00')->validFrom('2026-09-01')->create();
            $this->price->forceFill(['amount' => '6.00'])->saveQuietly();

            $this->workflow->close($this->september);

            $vr = ($this->calc)(BenefitType::Vr, $this->september);

            expect($vr->id)->not->toBe($previewId)
                ->and($vr->unit_amount)->toBe('30.00')
                ->and($vr->total_amount)->toBe('630.00')
                ->and(($this->calc)(BenefitType::Vt, $this->september)->unit_amount)->toBe('12.00');
        });

        test('F9-03 a pending adjustment blocks the closing', function () {
            $this->registrar->register($this->september, $this->employee, AdjustmentReason::Vacation, AdjustmentSource::Import, CarbonImmutable::parse('2026-08-17'), CarbonImmutable::parse('2026-08-17'));
            $this->workflow->calculate($this->september);

            expect($this->workflow->closeBlockers($this->september->fresh()))->toContain('1 ajuste(s) aguardando revisão. Confirme ou rejeite os ajustes pendentes antes de fechar.');
            expect(fn () => $this->workflow->close($this->september))->toThrow(DomainException::class, '1 ajuste(s) aguardando revisão');

            expect($this->september->fresh()->status)->toBe(BenefitPeriodStatus::Calculated)
                ->and(BenefitAdjustment::sole()->status)->toBe(AdjustmentStatus::Pending);
        });

        test('F9-04 a blocking issue blocks the closing', function () {
            $this->workflow->calculate($this->september);
            TransportFarePrice::factory()->for($other = TransportFare::factory()->create())->amount('5.00')->validFrom('2026-12-01')->create();
            TransportRoute::factory()->for($this->employee)->for($other)->between('2026-01-01')->create();

            expect(fn () => $this->workflow->close($this->september))->toThrow(DomainException::class, 'sem preço vigente');

            expect($this->september->fresh()->status)->toBe(BenefitPeriodStatus::Calculated);
        });

        test('F9-05 a warning does not block the closing', function () {
            Holiday::query()->delete();
            $this->workflow->calculate($this->september);

            expect(collect($this->workflow->calculate($this->september)['issues'])->pluck('severity')->all())->toBe(['warning']);

            $this->workflow->close($this->september);

            expect($this->september->fresh()->status)->toBe(BenefitPeriodStatus::Closed);
        });

        test('F9-06 vt without route blocks the closing', function () {
            $this->route->delete();
            $this->workflow->calculate($this->september);

            expect($this->workflow->closeBlockers($this->september->fresh()))->toBe(['João Silva é elegível a VT, mas não possui itinerário vigente em 01/09/2026.']);
            expect(fn () => $this->workflow->close($this->september))->toThrow(DomainException::class, 'não possui itinerário vigente');
        });

        test('F9-07 closing records the status change', function () {
            ($this->closeSeptember)();

            expect($this->september->statusChanges()->latest('id')->first()->only(['from_status', 'to_status', 'reason', 'user_id']))
                ->toBe(['from_status' => BenefitPeriodStatus::Calculated, 'to_status' => BenefitPeriodStatus::Closed, 'reason' => 'Competência fechada.', 'user_id' => $this->user->id]);
        });

        test('F9-08 closing is transactional', function () {
            $this->workflow->calculate($this->september);
            $preview = ($this->calc)(BenefitType::Vt, $this->september);
            $changes = $this->september->statusChanges()->count();

            $this->route->delete();

            expect(fn () => $this->workflow->close($this->september))->toThrow(DomainException::class);

            expect($this->september->fresh()->status)->toBe(BenefitPeriodStatus::Calculated)
                ->and($this->september->fresh()->closed_at)->toBeNull()
                ->and(($this->calc)(BenefitType::Vt, $this->september)->id)->toBe($preview->id)
                ->and(BenefitCalculationTransportItem::query()->where('benefit_calculation_id', $preview->id)->count())->toBe(1)
                ->and($this->september->statusChanges()->count())->toBe($changes);
        });

        test('the previous competence must be closed to close', function () {
            ($this->closeSeptember)();
            $this->workflow->calculate($this->october);
            $this->september->forceFill(['status' => BenefitPeriodStatus::Calculated])->save();

            expect(fn () => $this->workflow->close($this->october))->toThrow(DomainException::class, 'competência anterior (09/2026)');
        });
    });

    describe('immutability', function () {
        beforeEach(function () {
            ($this->closeSeptember)();
        });

        test('F9-09 a rate used by a closed competence cannot be edited', function () {
            expect(fn () => $this->vr->update(['amount' => '1.00']))->toThrow(DomainException::class, 'competência fechada');

            expect($this->vr->fresh()->amount)->toBe('27.50');
        });

        test('F9-10 a rate used by a closed competence cannot be deleted', function () {
            expect(fn () => $this->vd->delete())->toThrow(DomainException::class, 'competência fechada');
            expect(app(BenefitRateRegistrar::class)->canDelete($this->vd))->toBeFalse();

            Livewire::test(BenefitRateIndex::class)
                ->assertSee('Competência fechada')
                ->call('destroy', $this->vd->id);

            $this->assertModelExists($this->vd);
        });

        test('a rate that was never used but starts before the closed competence is protected too', function () {
            $old = BenefitRate::factory()->vr('20.00')->validFrom('2025-01-01')->create();

            expect(app(BenefitRateRegistrar::class)->canDelete($old))->toBeFalse()
                ->and(fn () => $old->delete())->toThrow(DomainException::class);
        });

        test('F9-11 a fare price used by a closed competence cannot be edited', function () {
            expect(fn () => $this->price->update(['amount' => '9.99']))->toThrow(DomainException::class, 'competência fechada');

            expect($this->price->fresh()->amount)->toBe('5.40');
        });

        test('F9-12 a fare price used by a closed competence cannot be deleted', function () {
            expect(fn () => $this->price->delete())->toThrow(DomainException::class, 'competência fechada');

            $this->assertModelExists($this->price);
        });

        test('F9-13 a later validity is still allowed', function () {
            $rate = app(BenefitRateRegistrar::class)->register(BenefitType::Vr, '30.00', CarbonImmutable::parse('2026-10-01'), $this->user->id);
            $price = TransportFarePrice::factory()->for($this->fare)->amount('6.00')->validFrom('2026-10-01')->create();

            $rate->update(['amount' => '31.00']);
            $price->update(['amount' => '6.10']);

            expect($rate->fresh()->amount)->toBe('31.00')
                ->and($price->fresh()->amount)->toBe('6.10')
                ->and(app(BenefitRateRegistrar::class)->canDelete($rate))->toBeTrue();

            $rate->delete();
            $price->delete();

            $this->assertModelMissing($rate);
            $this->assertModelMissing($price);
        });

        test('the closed snapshot keeps its values after later configuration changes', function () {
            BenefitRate::factory()->vr('40.00')->validFrom('2026-10-01')->create();
            $this->fare->update(['name' => 'Renomeada']);
            $this->route->update(['ends_on' => '2026-09-30']);

            expect(($this->calc)(BenefitType::Vr, $this->september)->total_amount)->toBe('577.50')
                ->and(($this->calc)(BenefitType::Vt, $this->september)->transportItems()->first()->fare_name)->toBe('CPTM');
        });

        test('without a closed competence nothing is protected', function () {
            BenefitPeriod::whereKey($this->september->id)->update(['status' => BenefitPeriodStatus::Open]);
            BenefitPeriodEmployee::query()->delete();

            $this->price->update(['amount' => '5.50']);

            expect($this->price->fresh()->amount)->toBe('5.50')
                ->and(BenefitPeriod::lastClosedCompetence())->toBeNull();
        });
    });

    describe('reopening', function () {
        test('F9-14 F9-16 F9-17 a closed competence reopens to open with history and without snapshot', function () {
            ($this->closeSeptember)();

            $this->workflow->reopen($this->september, 'Atestado entregue depois do fechamento.');

            $september = $this->september->fresh();

            expect($september->status)->toBe(BenefitPeriodStatus::Open)
                ->and($september->closed_at)->toBeNull()
                ->and($september->business_days)->toBeNull()
                ->and($september->periodEmployees()->count())->toBe(0)
                ->and(BenefitCalculation::count())->toBe(0)
                ->and($september->statusChanges()->latest('id')->first()->only(['from_status', 'to_status', 'reason', 'user_id']))
                ->toBe(['from_status' => BenefitPeriodStatus::Closed, 'to_status' => BenefitPeriodStatus::Open, 'reason' => 'Atestado entregue depois do fechamento.', 'user_id' => $this->user->id]);
        });

        test('F9-15 reopening requires a reason', function () {
            ($this->closeSeptember)();

            expect(fn () => $this->workflow->reopen($this->september, '   '))->toThrow(DomainException::class, 'motivo da reabertura');

            expect($this->september->fresh()->status)->toBe(BenefitPeriodStatus::Closed);
        });

        test('only a closed competence can be reopened', function () {
            $this->workflow->calculate($this->september);

            expect(fn () => $this->workflow->reopen($this->september, 'Motivo.'))->toThrow(DomainException::class, 'Somente competências fechadas');
        });

        test('F9-18 a reopened competence accepts adjustments and can be recalculated and closed again', function () {
            ($this->closeSeptember)();
            $this->workflow->reopen($this->september, 'Correção.');

            $this->registrar->register($this->september, $this->employee, AdjustmentReason::MedicalCertificate, AdjustmentSource::Manual, CarbonImmutable::parse('2026-08-17'), CarbonImmutable::parse('2026-08-17'));
            $this->workflow->calculate($this->september);
            $this->workflow->close($this->september);

            expect($this->september->fresh()->status)->toBe(BenefitPeriodStatus::Closed)
                ->and(($this->calc)(BenefitType::Vr, $this->september)->final_days)->toBe(20);
        });

        test('F9-19 reopening with the next competence calculated discards the next preview first', function () {
            $this->registrar->register($this->september, $this->employee, AdjustmentReason::Manual, AdjustmentSource::Manual, CarbonImmutable::parse('2026-08-17'), CarbonImmutable::parse('2026-08-17'), 'Pago a maior.', ['vr' => -23]);
            ($this->closeSeptember)();
            $this->workflow->calculate($this->october);

            expect(($this->calc)(BenefitType::Vr, $this->october)->carried_from_calculation_id)->toBe(($this->calc)(BenefitType::Vr, $this->september)->id);

            $this->workflow->reopen($this->september, 'Ajuste de agosto lançado errado.');

            $october = $this->october->fresh();

            expect($october->status)->toBe(BenefitPeriodStatus::Open)
                ->and($october->periodEmployees()->count())->toBe(0)
                ->and($october->statusChanges()->latest('id')->first()->reason)->toBe('Prévia descartada pela reabertura de 09/2026.')
                ->and($this->september->fresh()->status)->toBe(BenefitPeriodStatus::Open)
                ->and(BenefitCalculation::count())->toBe(0);
        });

        test('F9-20 reopening with the next competence closed is refused', function () {
            ($this->closeSeptember)();
            $this->workflow->calculate($this->october);
            $this->workflow->close($this->october);

            expect(fn () => $this->workflow->reopen($this->september, 'Motivo.'))
                ->toThrow(DomainException::class, 'A competência 10/2026 já está fechada');

            expect($this->september->fresh()->status)->toBe(BenefitPeriodStatus::Closed)
                ->and($this->october->fresh()->status)->toBe(BenefitPeriodStatus::Closed)
                ->and(($this->calc)(BenefitType::Vr, $this->september))->not->toBeNull();
        });

        test('F9-21 reopening with the next competence open works normally', function () {
            ($this->closeSeptember)();

            $this->workflow->reopen($this->september, 'Motivo.');

            expect($this->september->fresh()->status)->toBe(BenefitPeriodStatus::Open)
                ->and($this->october->fresh()->status)->toBe(BenefitPeriodStatus::Open)
                ->and($this->october->fresh()->statusChanges()->count())->toBe(0);
        });
    });

    describe('after closing', function () {
        beforeEach(function () {
            $this->pending = $this->registrar->register($this->september, $this->employee, AdjustmentReason::Vacation, AdjustmentSource::Import, CarbonImmutable::parse('2026-08-17'), CarbonImmutable::parse('2026-08-17'))['adjustment'];
            $this->registrar->reject($this->pending, 'Não procede.');
            $this->confirmed = $this->registrar->register($this->september, $this->employee, AdjustmentReason::Vacation, AdjustmentSource::Manual, CarbonImmutable::parse('2026-08-18'), CarbonImmutable::parse('2026-08-18'))['adjustment'];
            ($this->closeSeptember)();
        });

        test('F9-22 a new adjustment is refused', function () {
            expect(fn () => $this->registrar->register($this->september, $this->employee, AdjustmentReason::Vacation, AdjustmentSource::Manual, CarbonImmutable::parse('2026-08-19'), CarbonImmutable::parse('2026-08-19')))
                ->toThrow(DomainException::class, 'está fechada');
        });

        test('F9-23 an adjustment cannot be changed or replaced', function () {
            expect(fn () => $this->registrar->update($this->pending, AdjustmentReason::Leave, CarbonImmutable::parse('2026-08-17'), CarbonImmutable::parse('2026-08-17')))
                ->toThrow(DomainException::class, 'está fechada')
                ->and(fn () => $this->registrar->replace($this->confirmed, AdjustmentReason::Leave, CarbonImmutable::parse('2026-08-18'), CarbonImmutable::parse('2026-08-18')))
                ->toThrow(DomainException::class, 'está fechada');
        });

        test('F9-24 adjustments cannot be confirmed, rejected or reconsidered', function () {
            expect(fn () => $this->registrar->reconsider($this->pending))->toThrow(DomainException::class, 'está fechada')
                ->and(fn () => $this->registrar->reject($this->confirmed, 'Motivo.'))->toThrow(DomainException::class, 'está fechada')
                ->and(fn () => $this->registrar->confirmMany($this->september, [$this->confirmed->id]))->toThrow(DomainException::class, 'está fechada');

            expect($this->confirmed->fresh()->status)->toBe(AdjustmentStatus::Confirmed)
                ->and($this->pending->fresh()->status)->toBe(AdjustmentStatus::Rejected);
        });

        test('F9-25 a closed competence cannot be recalculated', function () {
            $snapshotId = ($this->calc)(BenefitType::Vr, $this->september)->id;

            expect(fn () => $this->workflow->calculate($this->september))->toThrow(DomainException::class, 'está fechada');

            expect(($this->calc)(BenefitType::Vr, $this->september)->id)->toBe($snapshotId);
        });

        test('F9-26 a closed competence cannot be deleted', function () {
            $this->october->delete();

            expect(fn () => $this->workflow->delete($this->september))->toThrow(DomainException::class, 'Somente competências abertas');

            $this->assertModelExists($this->september);
        });
    });

    describe('deletion rules', function () {
        test('F9-32 the latest open competence without snapshot and adjustments can be deleted', function () {
            $this->workflow->delete($this->october);

            $this->assertModelMissing($this->october);
        });

        test('F9-33 a competence with a later one cannot be deleted', function () {
            expect(fn () => $this->workflow->delete($this->september))->toThrow(DomainException::class, 'mais recente');
        });

        test('F9-34 a competence with adjustments cannot be deleted', function () {
            $this->registrar->register($this->october, $this->employee, AdjustmentReason::Vacation, AdjustmentSource::Manual, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'));

            expect(fn () => $this->workflow->delete($this->october))->toThrow(DomainException::class, 'ajustes lançados');
        });

        test('F9-35 F9-36 calculated and closed competences cannot be deleted', function () {
            $this->october->delete();
            $this->workflow->calculate($this->september);

            expect(fn () => $this->workflow->delete($this->september))->toThrow(DomainException::class, 'Somente competências abertas');

            $this->workflow->close($this->september);

            expect(fn () => $this->workflow->delete($this->september))->toThrow(DomainException::class, 'Somente competências abertas');
        });
    });

    describe('calculation screen', function () {
        test('the actions follow the status', function () {
            Livewire::test(BenefitPeriodCalculation::class, ['benefitPeriod' => $this->september])
                ->assertSee('Calcular')
                ->assertDontSee('Recalcular')
                ->assertDontSee('wire:click="close"', false)
                ->assertDontSee('wire:click="openReopenModal"', false)
                ->call('calculate')
                ->assertSee('Recalcular')
                ->assertSee('wire:click="close"', false)
                ->assertDontSee('wire:click="openReopenModal"', false);

            $this->workflow->close($this->september);

            Livewire::test(BenefitPeriodCalculation::class, ['benefitPeriod' => $this->september->fresh()])
                ->assertSee('wire:click="openReopenModal"', false)
                ->assertSee('O resultado está congelado')
                ->assertDontSee('Recalcular')
                ->assertDontSee('wire:click="close"', false);
        });

        test('the close button goes through the workflow', function () {
            $this->workflow->calculate($this->september);

            Livewire::test(BenefitPeriodCalculation::class, ['benefitPeriod' => $this->september->fresh()])
                ->call('close')
                ->assertSet('closeBlockers', []);

            expect($this->september->fresh()->status)->toBe(BenefitPeriodStatus::Closed);
        });

        test('the close blockers are shown and nothing changes', function () {
            $this->registrar->register($this->september, $this->employee, AdjustmentReason::Vacation, AdjustmentSource::Import, CarbonImmutable::parse('2026-08-17'), CarbonImmutable::parse('2026-08-17'));
            $this->route->delete();
            $this->workflow->calculate($this->september);

            Livewire::test(BenefitPeriodCalculation::class, ['benefitPeriod' => $this->september->fresh()])
                ->call('close')
                ->assertSee('Não é possível fechar a competência.')
                ->assertSee('1 ajuste(s) aguardando revisão.')
                ->assertSee('João Silva é elegível a VT, mas não possui itinerário vigente em 01/09/2026.');

            expect($this->september->fresh()->status)->toBe(BenefitPeriodStatus::Calculated);
        });

        test('the reopen modal requires a reason and reopens the competence', function () {
            ($this->closeSeptember)();

            Livewire::test(BenefitPeriodCalculation::class, ['benefitPeriod' => $this->september->fresh()])
                ->call('openReopenModal')
                ->assertSet('showReopenModal', true)
                ->call('reopen')
                ->assertHasErrors('reopenReason')
                ->set('reopenReason', 'Correção de férias.')
                ->call('reopen')
                ->assertHasNoErrors()
                ->assertSet('showReopenModal', false)
                ->assertSee('Calcular')
                ->assertDontSee('wire:click="openReopenModal"', false);

            expect($this->september->fresh()->status)->toBe(BenefitPeriodStatus::Open);
        });

        test('a refused reopening shows the reason', function () {
            ($this->closeSeptember)();
            $this->workflow->calculate($this->october);
            $this->workflow->close($this->october);

            Livewire::test(BenefitPeriodCalculation::class, ['benefitPeriod' => $this->september->fresh()])
                ->call('openReopenModal')
                ->set('reopenReason', 'Correção.')
                ->call('reopen')
                ->assertHasErrors('reopenReason')
                ->assertSee('A competência 10/2026 já está fechada');

            expect($this->september->fresh()->status)->toBe(BenefitPeriodStatus::Closed);
        });
    });
});
