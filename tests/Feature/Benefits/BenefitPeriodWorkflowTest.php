<?php

use App\Enums\BenefitPeriodStatus;
use App\Models\BenefitAdjustment;
use App\Models\BenefitCalculation;
use App\Models\BenefitPeriod;
use App\Models\BenefitPeriodEmployee;
use App\Models\BenefitPeriodStatusChange;
use App\Models\User;
use App\Services\BenefitPeriodWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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
