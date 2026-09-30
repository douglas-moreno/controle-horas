<?php

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\AdjustmentStatus;
use App\Enums\AdjustmentTiming;
use App\Enums\BenefitPeriodStatus;
use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriod;
use App\Models\BenefitPeriodEmployee;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use App\Services\BenefitAdjustmentRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Competência 10/2026: janela de realizados 01/09 → 30/09/2026, previstos em outubro.
 * Setembro/2026: 05, 12, 19, 26 são sábados; 07/09 é feriado (segunda-feira).
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Holiday::factory()->create(['date' => '2026-09-07', 'description' => 'Independência']);
    Holiday::factory()->create(['date' => '2026-10-12', 'description' => 'Nossa Senhora Aparecida']);

    $this->registrar = app(BenefitAdjustmentRegistrar::class);
    $this->period = BenefitPeriod::factory()->forCompetence('2026-10')->create();
    $this->employee = Employee::factory()->create();

    $this->register = function (
        AdjustmentReason $reason,
        string $startsOn,
        ?string $endsOn = null,
        AdjustmentSource $source = AdjustmentSource::Manual,
        ?string $notes = null,
        array $impacts = [],
        ?BenefitPeriod $period = null,
        ?Employee $employee = null,
    ): array {
        return $this->registrar->register(
            $period ?? $this->period,
            $employee ?? $this->employee,
            $reason,
            $source,
            CarbonImmutable::parse($startsOn),
            CarbonImmutable::parse($endsOn ?? $startsOn),
            $notes,
            $impacts,
        );
    };
});

/**
 * @return array<string, int>
 */
function impactsOf(BenefitAdjustment $adjustment): array
{
    return $adjustment->impacts()->get()
        ->mapWithKeys(fn ($impact) => [$impact->benefit_type->value => $impact->quantity])
        ->sortKeys()
        ->all();
}

describe('creation', function () {
    test('a one day absence discounts one day of each benefit', function () {
        $adjustment = ($this->register)(AdjustmentReason::UnjustifiedAbsence, '2026-09-15')['adjustment'];

        expect($adjustment->days_count)->toBe(1)
            ->and(impactsOf($adjustment))->toBe(['vd' => -1, 'vr' => -1, 'vt' => -1]);
    });

    test('an absence interval counts the business days of the interval', function () {
        $adjustment = ($this->register)(AdjustmentReason::JustifiedAbsence, '2026-09-14', '2026-09-16')['adjustment'];

        expect($adjustment->days_count)->toBe(3)
            ->and(impactsOf($adjustment))->toBe(['vd' => -3, 'vr' => -3, 'vt' => -3]);
    });

    test('vacation counts only business days, skipping weekends and holidays', function () {
        $adjustment = ($this->register)(AdjustmentReason::Vacation, '2026-09-05', '2026-09-14')['adjustment'];

        expect($adjustment->days_count)->toBe(5)
            ->and($adjustment->starts_on->toDateString())->toBe('2026-09-05')
            ->and($adjustment->ends_on->toDateString())->toBe('2026-09-14')
            ->and(impactsOf($adjustment))->toBe(['vd' => -5, 'vr' => -5, 'vt' => -5]);
    });

    test('a holiday is excluded from the count', function () {
        $adjustment = ($this->register)(AdjustmentReason::MedicalCertificate, '2026-09-07', '2026-09-08')['adjustment'];

        expect($adjustment->days_count)->toBe(1);
    });

    test('an absence without business days is rejected', function () {
        expect(fn () => ($this->register)(AdjustmentReason::Leave, '2026-09-05', '2026-09-07'))
            ->toThrow(DomainException::class, 'não contém dias úteis');
    });

    test('worked days add one day of each benefit', function (AdjustmentReason $reason, string $date) {
        $adjustment = ($this->register)($reason, $date)['adjustment'];

        expect($adjustment->days_count)->toBe(1)
            ->and(impactsOf($adjustment))->toBe(['vd' => 1, 'vr' => 1, 'vt' => 1]);
    })->with([
        'saturday' => [AdjustmentReason::SaturdayWorked, '2026-09-12'],
        'sunday' => [AdjustmentReason::SundayWorked, '2026-09-13'],
        'holiday' => [AdjustmentReason::HolidayWorked, '2026-09-07'],
    ]);

    test('worked day reasons do not accept intervals', function () {
        expect(fn () => ($this->register)(AdjustmentReason::SaturdayWorked, '2026-09-12', '2026-09-13'))
            ->toThrow(DomainException::class, 'único dia');
    });

    test('a standard reason always creates exactly the three impacts', function (AdjustmentReason $reason) {
        $date = $reason->isWork() ? '2026-09-12' : '2026-09-15';
        $adjustment = ($this->register)($reason, $date, source: $reason->allowedSources()[0] === AdjustmentSource::Timesheet ? AdjustmentSource::Manual : $reason->allowedSources()[0])['adjustment'];

        expect($adjustment->impacts()->count())->toBe(3)
            ->and(array_keys(impactsOf($adjustment)))->toBe(['vd', 'vr', 'vt']);
    })->with(array_values(array_filter(AdjustmentReason::cases(), fn (AdjustmentReason $reason) => $reason !== AdjustmentReason::Manual)));

    test('standard impacts cannot be informed', function () {
        expect(fn () => ($this->register)(AdjustmentReason::Vacation, '2026-09-15', impacts: ['vt' => -2]))
            ->toThrow(DomainException::class, 'automáticos');

        expect(BenefitAdjustment::count())->toBe(0);
    });

    test('a manual adjustment accepts free impacts per benefit', function () {
        $adjustment = ($this->register)(AdjustmentReason::Manual, '2026-09-15', notes: 'Pago a menor em agosto.', impacts: ['vt' => 2, 'vr' => 0, 'vd' => -1])['adjustment'];

        expect(impactsOf($adjustment))->toBe(['vd' => -1, 'vt' => 2])
            ->and($adjustment->status)->toBe(AdjustmentStatus::Confirmed);
    });

    test('a manual adjustment requires notes', function () {
        expect(fn () => ($this->register)(AdjustmentReason::Manual, '2026-09-15', notes: '  ', impacts: ['vt' => 1]))
            ->toThrow(DomainException::class, 'exige observação');
    });

    test('a manual adjustment requires an effective impact', function () {
        expect(fn () => ($this->register)(AdjustmentReason::Manual, '2026-09-15', notes: 'Correção.', impacts: ['vt' => 0]))
            ->toThrow(DomainException::class, 'ao menos um impacto');
        expect(fn () => ($this->register)(AdjustmentReason::Manual, '2026-09-15', notes: 'Correção.'))
            ->toThrow(DomainException::class, 'ao menos um impacto');
    });

    test('manual impacts must be integers of a known benefit', function () {
        expect(fn () => ($this->register)(AdjustmentReason::Manual, '2026-09-15', notes: 'Correção.', impacts: ['vt' => '1']))
            ->toThrow(DomainException::class, 'número inteiro');
        expect(fn () => ($this->register)(AdjustmentReason::Manual, '2026-09-15', notes: 'Correção.', impacts: ['xx' => 1]))
            ->toThrow(DomainException::class, 'inválido');
    });

    test('the reason must accept the source', function () {
        expect(fn () => ($this->register)(AdjustmentReason::Manual, '2026-09-15', source: AdjustmentSource::Hr, notes: 'x', impacts: ['vt' => 1]))
            ->toThrow(DomainException::class, 'origem Manual');
        expect(fn () => ($this->register)(AdjustmentReason::Vacation, '2026-09-15', source: AdjustmentSource::Timesheet))
            ->toThrow(DomainException::class, 'não pode ter origem');
    });

    test('an employee that was not persisted is rejected', function () {
        expect(fn () => ($this->register)(AdjustmentReason::Vacation, '2026-09-15', employee: Employee::factory()->make()))
            ->toThrow(DomainException::class, 'Funcionário inválido');
    });

    test('audit columns are filled', function () {
        $adjustment = ($this->register)(AdjustmentReason::Vacation, '2026-09-15')['adjustment']->fresh();

        expect($adjustment->created_by)->toBe($this->user->id)
            ->and($adjustment->updated_by)->toBe($this->user->id)
            ->and($adjustment->reviewed_by)->toBeNull();
    });
});

describe('dates', function () {
    test('a realized event inside the window is accepted', function () {
        $adjustment = ($this->register)(AdjustmentReason::UnjustifiedAbsence, '2026-09-30')['adjustment'];

        expect($this->period->timingOf($adjustment->starts_on, $adjustment->ends_on))->toBe(AdjustmentTiming::Realized);
    });

    test('a forecast event inside the competence is accepted', function () {
        $adjustment = ($this->register)(AdjustmentReason::Vacation, '2026-10-01', '2026-10-05')['adjustment'];

        expect($adjustment->days_count)->toBe(3)
            ->and($this->period->timingOf($adjustment->starts_on, $adjustment->ends_on))->toBe(AdjustmentTiming::Forecast);
    });

    test('a timesheet suggestion outside the window is rejected', function (string $date) {
        expect(fn () => $this->registrar->registerSuggestion($this->period, $this->employee, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse($date)))
            ->toThrow(DomainException::class, 'janela de eventos realizados');
    })->with(['forecast' => '2026-10-06', 'retroactive' => '2026-08-31']);

    test('an import cannot be a forecast event', function () {
        expect(fn () => ($this->register)(AdjustmentReason::Vacation, '2026-10-06', source: AdjustmentSource::Import))
            ->toThrow(DomainException::class, 'previsto');
    });

    test('an interval crossing timing classes is rejected', function (string $startsOn, string $endsOn) {
        expect(fn () => ($this->register)(AdjustmentReason::Vacation, $startsOn, $endsOn, notes: 'Férias.'))
            ->toThrow(DomainException::class, 'Lance um ajuste para cada mês');
    })->with([
        'realized to forecast' => ['2026-09-28', '2026-10-02'],
        'retroactive to realized' => ['2026-08-28', '2026-09-02'],
    ]);

    test('dates after the competence are rejected', function () {
        expect(fn () => ($this->register)(AdjustmentReason::Vacation, '2026-10-30', '2026-11-03'))
            ->toThrow(DomainException::class, 'posteriores ao fim da competência');
        expect(fn () => ($this->register)(AdjustmentReason::Vacation, '2026-11-03'))
            ->toThrow(DomainException::class, 'posteriores ao fim da competência');
    });

    test('the initial date must not be after the final date', function () {
        expect(fn () => ($this->register)(AdjustmentReason::Vacation, '2026-09-16', '2026-09-15'))
            ->toThrow(DomainException::class, 'data inicial');
    });

    test('a retroactive correction requires notes', function () {
        expect(fn () => ($this->register)(AdjustmentReason::UnjustifiedAbsence, '2026-08-17'))
            ->toThrow(DomainException::class, 'retroativas exigem observação');

        expect(BenefitAdjustment::count())->toBe(0);
    });

    test('a retroactive correction is classified from its dates', function () {
        $adjustment = ($this->register)(AdjustmentReason::UnjustifiedAbsence, '2026-08-17', notes: 'Falta de agosto descoberta em setembro.')['adjustment'];

        expect($this->period->timingOf($adjustment->starts_on, $adjustment->ends_on))->toBe(AdjustmentTiming::Retroactive)
            ->and($adjustment->days_count)->toBe(1);
    });
});

describe('sources', function () {
    test('the initial status comes from the source', function (AdjustmentSource $source, AdjustmentReason $reason, AdjustmentStatus $status) {
        $adjustment = $source === AdjustmentSource::Timesheet
            ? $this->registrar->registerSuggestion($this->period, $this->employee, $reason, CarbonImmutable::parse('2026-09-15'))['adjustment']
            : ($this->register)($reason, '2026-09-15', source: $source)['adjustment'];

        expect($adjustment->source)->toBe($source)
            ->and($adjustment->status)->toBe($status);
    })->with([
        'manual' => [AdjustmentSource::Manual, AdjustmentReason::Vacation, AdjustmentStatus::Confirmed],
        'timesheet' => [AdjustmentSource::Timesheet, AdjustmentReason::UnjustifiedAbsence, AdjustmentStatus::Pending],
        'hr' => [AdjustmentSource::Hr, AdjustmentReason::Vacation, AdjustmentStatus::Confirmed],
        'import' => [AdjustmentSource::Import, AdjustmentReason::Vacation, AdjustmentStatus::Pending],
    ]);
});

describe('states', function () {
    beforeEach(function () {
        $this->pending = fn (string $date = '2026-09-15') => $this->registrar
            ->registerSuggestion($this->period, $this->employee, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse($date))['adjustment'];
    });

    test('a pending adjustment can be updated and its impacts are regenerated', function () {
        $adjustment = ($this->pending)();

        $updated = $this->registrar->update($adjustment, AdjustmentReason::MedicalCertificate, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-16'), 'Atestado entregue.')['adjustment'];

        expect($updated->id)->toBe($adjustment->id)
            ->and($updated->reason)->toBe(AdjustmentReason::MedicalCertificate)
            ->and($updated->source)->toBe(AdjustmentSource::Timesheet)
            ->and($updated->status)->toBe(AdjustmentStatus::Pending)
            ->and($updated->days_count)->toBe(2)
            ->and($updated->notes)->toBe('Atestado entregue.')
            ->and($updated->dedupe_key)->toBe($adjustment->dedupe_key)
            ->and(impactsOf($updated))->toBe(['vd' => -2, 'vr' => -2, 'vt' => -2])
            ->and(BenefitAdjustment::count())->toBe(1);
    });

    test('updating a timesheet suggestion keeps the window restriction', function () {
        expect(fn () => $this->registrar->update(($this->pending)(), AdjustmentReason::MedicalCertificate, CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-05')))
            ->toThrow(DomainException::class, 'janela');
    });

    test('updating does not accept free impacts for a standard reason', function () {
        expect(fn () => $this->registrar->update(($this->pending)(), AdjustmentReason::MedicalCertificate, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), null, ['vt' => -3]))
            ->toThrow(DomainException::class, 'automáticos');
    });

    test('only pending adjustments can be updated', function () {
        $confirmed = ($this->register)(AdjustmentReason::Vacation, '2026-09-15')['adjustment'];

        expect(fn () => $this->registrar->update($confirmed, AdjustmentReason::Leave, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15')))
            ->toThrow(DomainException::class, 'Somente ajustes pendentes');
    });

    test('a pending adjustment can be confirmed with review data', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00:00'));
        $adjustment = $this->registrar->confirm(($this->pending)(), 'Conferido no ponto.');

        expect($adjustment->fresh()->status)->toBe(AdjustmentStatus::Confirmed)
            ->and($adjustment->fresh()->reviewed_by)->toBe($this->user->id)
            ->and($adjustment->fresh()->reviewed_at->toDateTimeString())->toBe('2026-10-02 10:00:00')
            ->and($adjustment->fresh()->review_notes)->toBe('Conferido no ponto.');
    });

    test('confirmation does not require notes', function () {
        expect($this->registrar->confirm(($this->pending)())->fresh()->review_notes)->toBeNull();
    });

    test('only pending adjustments can be confirmed', function () {
        $adjustment = $this->registrar->confirm(($this->pending)());

        expect(fn () => $this->registrar->confirm($adjustment))->toThrow(DomainException::class, 'Somente ajustes pendentes');
    });

    test('a pending adjustment can be rejected with review data', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 11:00:00'));
        $adjustment = $this->registrar->reject(($this->pending)(), 'Funcionário estava em treinamento externo.');

        expect($adjustment->fresh()->status)->toBe(AdjustmentStatus::Rejected)
            ->and($adjustment->fresh()->reviewed_by)->toBe($this->user->id)
            ->and($adjustment->fresh()->reviewed_at->toDateTimeString())->toBe('2026-10-02 11:00:00')
            ->and($adjustment->fresh()->review_notes)->toBe('Funcionário estava em treinamento externo.');
        $this->assertModelExists($adjustment);
    });

    test('rejection requires notes', function () {
        $adjustment = ($this->pending)();

        expect(fn () => $this->registrar->reject($adjustment, '   '))->toThrow(DomainException::class, 'motivo da rejeição');
        expect($adjustment->fresh()->status)->toBe(AdjustmentStatus::Pending);
    });

    test('a confirmed adjustment can be rejected to cancel it', function () {
        $confirmed = ($this->register)(AdjustmentReason::Vacation, '2026-10-05')['adjustment'];

        expect($this->registrar->reject($confirmed, 'Férias canceladas.')->fresh()->status)->toBe(AdjustmentStatus::Rejected);
    });

    test('a rejected adjustment is not rejected again', function () {
        $adjustment = $this->registrar->reject(($this->pending)(), 'Não procede.');

        expect(fn () => $this->registrar->reject($adjustment, 'De novo.'))->toThrow(DomainException::class, 'já está rejeitado');
        expect($adjustment->fresh()->review_notes)->toBe('Não procede.');
    });

    test('a rejected adjustment can be reconsidered back to pending', function () {
        $adjustment = $this->registrar->reject(($this->pending)(), 'Não procede.');

        $reconsidered = $this->registrar->reconsider($adjustment, 'Batida era de outro funcionário.');

        expect($reconsidered->id)->toBe($adjustment->id)
            ->and($reconsidered->fresh()->status)->toBe(AdjustmentStatus::Pending)
            ->and($reconsidered->fresh()->review_notes)->toBe('Reconsiderado: Batida era de outro funcionário. Rejeição anterior: Não procede.')
            ->and(BenefitAdjustment::count())->toBe(1);

        expect($this->registrar->confirm($reconsidered)->fresh()->status)->toBe(AdjustmentStatus::Confirmed);
    });

    test('only rejected adjustments can be reconsidered', function () {
        expect(fn () => $this->registrar->reconsider(($this->pending)()))->toThrow(DomainException::class, 'Somente ajustes rejeitados');
    });

    test('a confirmed adjustment can be replaced', function () {
        $original = ($this->register)(AdjustmentReason::Vacation, '2026-10-01', '2026-10-05')['adjustment'];

        $replacement = $this->registrar->replace($original, AdjustmentReason::Vacation, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-02'), 'Férias reduzidas.')['adjustment'];

        $original->refresh();

        expect($original->status)->toBe(AdjustmentStatus::Rejected)
            ->and($original->review_notes)->toBe('Substituído pelo ajuste #'.$replacement->id.'.')
            ->and($original->reviewed_by)->toBe($this->user->id)
            ->and(impactsOf($original))->toBe(['vd' => -3, 'vr' => -3, 'vt' => -3])
            ->and($replacement->id)->not->toBe($original->id)
            ->and($replacement->related_adjustment_id)->toBe($original->id)
            ->and($replacement->status)->toBe(AdjustmentStatus::Confirmed)
            ->and($replacement->days_count)->toBe(2)
            ->and(impactsOf($replacement))->toBe(['vd' => -2, 'vr' => -2, 'vt' => -2])
            ->and(BenefitAdjustment::count())->toBe(2);
        $this->assertModelExists($original);
    });

    test('a failed replacement keeps the original confirmed', function () {
        $original = ($this->register)(AdjustmentReason::Vacation, '2026-10-01', '2026-10-05')['adjustment'];

        expect(fn () => $this->registrar->replace($original, AdjustmentReason::Vacation, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-03')))
            ->toThrow(DomainException::class);

        expect($original->fresh()->status)->toBe(AdjustmentStatus::Confirmed)
            ->and(BenefitAdjustment::count())->toBe(1);
    });

    test('only confirmed adjustments can be replaced', function () {
        expect(fn () => $this->registrar->replace(($this->pending)(), AdjustmentReason::Vacation, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15')))
            ->toThrow(DomainException::class, 'Somente ajustes confirmados');
    });

    test('a closed competence blocks every operation', function () {
        $pending = ($this->pending)();
        $confirmed = ($this->register)(AdjustmentReason::Vacation, '2026-10-05')['adjustment'];
        $rejected = $this->registrar->reject(($this->pending)('2026-09-16'), 'Não procede.');
        $this->period->forceFill(['status' => BenefitPeriodStatus::Closed])->save();

        $operations = [
            fn () => ($this->register)(AdjustmentReason::Vacation, '2026-09-21'),
            fn () => $this->registrar->registerSuggestion($this->period, $this->employee, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse('2026-09-22')),
            fn () => $this->registrar->update($pending, AdjustmentReason::Leave, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15')),
            fn () => $this->registrar->confirm($pending),
            fn () => $this->registrar->reject($pending, 'Não procede.'),
            fn () => $this->registrar->reconsider($rejected),
            fn () => $this->registrar->replace($confirmed, AdjustmentReason::Vacation, CarbonImmutable::parse('2026-10-06'), CarbonImmutable::parse('2026-10-06')),
            fn () => $this->registrar->confirmMany($this->period, [$pending->id]),
            fn () => $this->registrar->rejectMany($this->period, [$pending->id], 'Não procede.'),
        ];

        foreach ($operations as $operation) {
            expect($operation)->toThrow(DomainException::class, 'está fechada');
        }

        expect(BenefitAdjustment::count())->toBe(3)
            ->and($pending->fresh()->status)->toBe(AdjustmentStatus::Pending)
            ->and($confirmed->fresh()->status)->toBe(AdjustmentStatus::Confirmed)
            ->and($rejected->fresh()->status)->toBe(AdjustmentStatus::Rejected);
    });

    test('a relevant change invalidates a calculated competence', function (Closure $operation) {
        $pending = ($this->pending)();
        $this->period->forceFill(['status' => BenefitPeriodStatus::Calculated, 'business_days' => 21, 'calculated_at' => now()])->save();
        BenefitPeriodEmployee::factory()->for($this->period)->create();

        $operation->call($this, $pending);

        $period = $this->period->fresh();

        expect($period->status)->toBe(BenefitPeriodStatus::Open)
            ->and($period->business_days)->toBeNull()
            ->and($period->periodEmployees()->count())->toBe(0)
            ->and($period->statusChanges()->latest('id')->first()->to_status)->toBe(BenefitPeriodStatus::Open);
    })->with([
        'register' => [function () {
            ($this->register)(AdjustmentReason::Vacation, '2026-09-21');
        }],
        'update' => [function (BenefitAdjustment $pending) {
            $this->registrar->update($pending, AdjustmentReason::Leave, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'));
        }],
        'confirm' => [function (BenefitAdjustment $pending) {
            $this->registrar->confirm($pending);
        }],
        'reject' => [function (BenefitAdjustment $pending) {
            $this->registrar->reject($pending, 'Não procede.');
        }],
    ]);

    test('a failed operation does not invalidate a calculated competence', function () {
        $this->period->forceFill(['status' => BenefitPeriodStatus::Calculated])->save();

        expect(fn () => ($this->register)(AdjustmentReason::Vacation, '2026-11-02'))->toThrow(DomainException::class);

        expect($this->period->fresh()->status)->toBe(BenefitPeriodStatus::Calculated);
    });
});

describe('batch review', function () {
    beforeEach(function () {
        $this->suggest = fn (string $date, ?Employee $employee = null) => $this->registrar
            ->registerSuggestion($this->period, $employee ?? $this->employee, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse($date))['adjustment'];
    });

    test('many pending adjustments are confirmed with review data', function () {
        $first = ($this->suggest)('2026-09-15');
        $second = ($this->suggest)('2026-09-16');

        $confirmed = $this->registrar->confirmMany($this->period, [$first->id, $second->id], 'Lote conferido.');

        expect($confirmed)->toHaveCount(2);

        foreach ([$first, $second] as $adjustment) {
            expect($adjustment->fresh()->status)->toBe(AdjustmentStatus::Confirmed)
                ->and($adjustment->fresh()->reviewed_by)->toBe($this->user->id)
                ->and($adjustment->fresh()->review_notes)->toBe('Lote conferido.');
        }
    });

    test('many adjustments are rejected with the required note', function () {
        $first = ($this->suggest)('2026-09-15');
        $second = ($this->suggest)('2026-09-16');

        expect(fn () => $this->registrar->rejectMany($this->period, [$first->id, $second->id], ''))
            ->toThrow(DomainException::class, 'motivo da rejeição');

        $this->registrar->rejectMany($this->period, [$first->id, $second->id], 'Treinamento externo.');

        expect($first->fresh()->status)->toBe(AdjustmentStatus::Rejected)
            ->and($second->fresh()->review_notes)->toBe('Treinamento externo.');
    });

    test('a failing item aborts the whole batch and is identified', function () {
        $first = ($this->suggest)('2026-09-15');
        $alreadyConfirmed = $this->registrar->confirm(($this->suggest)('2026-09-16'));

        expect(fn () => $this->registrar->confirmMany($this->period, [$first->id, $alreadyConfirmed->id]))
            ->toThrow(DomainException::class, 'Nenhum ajuste foi confirmado. Ajuste #'.$alreadyConfirmed->id);

        expect($first->fresh()->status)->toBe(AdjustmentStatus::Pending);
    });

    test('adjustments of another competence are refused', function () {
        $other = BenefitPeriod::factory()->forCompetence('2026-11')->create();
        $foreign = $this->registrar->registerSuggestion($other, $this->employee, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse('2026-10-15'))['adjustment'];

        expect(fn () => $this->registrar->confirmMany($this->period, [$foreign->id]))
            ->toThrow(DomainException::class, 'Ajuste #'.$foreign->id.': não pertence a esta competência');
    });

    test('an empty batch is refused', function () {
        expect(fn () => $this->registrar->confirmMany($this->period, []))->toThrow(DomainException::class, 'Selecione ao menos um ajuste');
    });
});

describe('deduplication', function () {
    test('DD2 blocks two standard absences for the same employee and day', function () {
        ($this->register)(AdjustmentReason::Vacation, '2026-09-14', '2026-09-18');

        expect(fn () => ($this->register)(AdjustmentReason::MedicalCertificate, '2026-09-16'))
            ->toThrow(DomainException::class, 'Já existe uma ausência não rejeitada');

        expect(BenefitAdjustment::count())->toBe(1);
    });

    test('DD2 is checked again on confirmation', function () {
        $suggestion = $this->registrar->registerSuggestion($this->period, $this->employee, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse('2026-09-16'))['adjustment'];
        $this->registrar->reject($suggestion, 'Reclassificar.');
        ($this->register)(AdjustmentReason::MedicalCertificate, '2026-09-16');

        expect(fn () => $this->registrar->reconsider($suggestion))->toThrow(DomainException::class, 'Já existe uma ausência');

        BenefitAdjustment::whereKey($suggestion->id)->update(['status' => AdjustmentStatus::Pending]);

        expect(fn () => $this->registrar->confirm($suggestion))->toThrow(DomainException::class, 'Já existe uma ausência');
    });

    test('DD3 blocks two standard worked days for the same employee and day', function () {
        $this->registrar->registerSuggestion($this->period, $this->employee, AdjustmentReason::SaturdayWorked, CarbonImmutable::parse('2026-09-12'));

        expect(fn () => ($this->register)(AdjustmentReason::SundayWorked, '2026-09-12'))
            ->toThrow(DomainException::class, 'Já existe um trabalho não rejeitado');
    });

    test('a rejected event does not block a new one', function () {
        $vacation = ($this->register)(AdjustmentReason::Vacation, '2026-09-16')['adjustment'];
        $this->registrar->reject($vacation, 'Lançado errado.');

        expect(($this->register)(AdjustmentReason::MedicalCertificate, '2026-09-16')['adjustment']->exists)->toBeTrue();
    });

    test('the same day of another employee is not a duplicate', function () {
        ($this->register)(AdjustmentReason::Vacation, '2026-09-16');

        expect(($this->register)(AdjustmentReason::Vacation, '2026-09-16', employee: Employee::factory()->create())['alerts'])->toBe([]);
    });

    test('manual adjustments are not blocked by DD2 or DD3 but raise an alert (DD5)', function (AdjustmentReason $standard, string $date) {
        ($this->register)($standard, $date);

        $result = ($this->register)(AdjustmentReason::Manual, $date, notes: 'Pago a menor.', impacts: ['vt' => 2]);

        expect($result['adjustment']->exists)->toBeTrue()
            ->and($result['alerts'])->toHaveCount(1)
            ->and($result['alerts'][0]['level'])->toBe('warning')
            ->and($result['alerts'][0]['message'])->toContain('Já existe outro ajuste para este funcionário nesta data');
    })->with([
        'absence' => [AdjustmentReason::UnjustifiedAbsence, '2026-09-15'],
        'worked day' => [AdjustmentReason::SaturdayWorked, '2026-09-12'],
    ]);

    test('a standard event on a day with a manual adjustment raises the DD5 alert', function () {
        ($this->register)(AdjustmentReason::Manual, '2026-09-15', notes: 'Correção.', impacts: ['vr' => -1]);

        $alerts = ($this->register)(AdjustmentReason::UnjustifiedAbsence, '2026-09-15')['alerts'];

        expect($alerts[0]['level'])->toBe('warning')
            ->and($alerts[0]['message'])->toContain('Já existe outro ajuste');
    });

    test('events with different signs can coexist with a conflict alert', function () {
        ($this->register)(AdjustmentReason::SaturdayWorked, '2026-09-12');

        $result = ($this->register)(AdjustmentReason::UnjustifiedAbsence, '2026-09-12', '2026-09-14');

        expect($result['adjustment']->exists)->toBeTrue()
            ->and($result['alerts'][0]['level'])->toBe('warning')
            ->and($result['alerts'][0]['message'])->toContain('Conflito');
    });

    test('the same day in another competence is allowed with an informative alert (DD6)', function () {
        $september = BenefitPeriod::factory()->forCompetence('2026-09')->closed()->create();
        BenefitAdjustment::factory()->for($september)->for($this->employee)->absence('2026-08-14')->create();

        $result = ($this->register)(AdjustmentReason::UnjustifiedAbsence, '2026-08-14', notes: 'Estorno de lançamento anterior.');

        expect($result['adjustment']->exists)->toBeTrue()
            ->and($result['alerts'])->toHaveCount(1)
            ->and($result['alerts'][0]['level'])->toBe('info')
            ->and($result['alerts'][0]['message'])->toContain('competência 09/2026');
    });

    test('a worked day on a date of another kind raises an alert', function () {
        $alerts = ($this->register)(AdjustmentReason::SaturdayWorked, '2026-09-15')['alerts'];

        expect($alerts[0]['message'])->toContain('não é um sábado');
    });

    test('timesheet suggestions use the documented dedupe key', function () {
        $result = $this->registrar->registerSuggestion($this->period, $this->employee, AdjustmentReason::SaturdayWorked, CarbonImmutable::parse('2026-09-12'));

        expect($result['created'])->toBeTrue()
            ->and($result['adjustment']->dedupe_key)->toBe('timesheet:'.$this->period->id.':'.$this->employee->id.':2026-09-12:SaturdayWorked')
            ->and($result['adjustment']->source)->toBe(AdjustmentSource::Timesheet)
            ->and($result['adjustment']->status)->toBe(AdjustmentStatus::Pending);
    });

    test('a duplicated timesheet suggestion is not created', function () {
        $first = $this->registrar->registerSuggestion($this->period, $this->employee, AdjustmentReason::SaturdayWorked, CarbonImmutable::parse('2026-09-12'));
        $second = $this->registrar->registerSuggestion($this->period, $this->employee, AdjustmentReason::SaturdayWorked, CarbonImmutable::parse('2026-09-12'));

        expect($second['created'])->toBeFalse()
            ->and($second['adjustment']->id)->toBe($first['adjustment']->id)
            ->and(BenefitAdjustment::count())->toBe(1);
    });

    test('a rejected timesheet suggestion stays rejected and is not recreated', function () {
        $suggestion = $this->registrar->registerSuggestion($this->period, $this->employee, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse('2026-09-15'))['adjustment'];
        $this->registrar->reject($suggestion, 'Treinamento externo.');

        $again = $this->registrar->registerSuggestion($this->period, $this->employee, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse('2026-09-15'));

        expect($again['created'])->toBeFalse()
            ->and($again['adjustment']->id)->toBe($suggestion->id)
            ->and($again['adjustment']->status)->toBe(AdjustmentStatus::Rejected)
            ->and(BenefitAdjustment::count())->toBe(1);
    });

    test('a reclassified suggestion keeps its key and is not recreated', function () {
        $suggestion = $this->registrar->registerSuggestion($this->period, $this->employee, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse('2026-09-15'))['adjustment'];
        $this->registrar->update($suggestion, AdjustmentReason::JustifiedAbsence, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'));

        expect($this->registrar->registerSuggestion($this->period, $this->employee, AdjustmentReason::UnjustifiedAbsence, CarbonImmutable::parse('2026-09-15'))['created'])->toBeFalse()
            ->and(BenefitAdjustment::count())->toBe(1);
    });
});

describe('concurrency', function () {
    test('the competence is locked inside the transaction before the adjustment is written', function () {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = ['sql' => strtolower($query->sql), 'level' => $query->connection->transactionLevel()];
        });

        ($this->register)(AdjustmentReason::Vacation, '2026-09-15');

        $lockIndex = collect($queries)->search(fn (array $query) => str_contains($query['sql'], 'from "benefit_periods"') || str_contains($query['sql'], 'from `benefit_periods`'));
        $insertIndex = collect($queries)->search(fn (array $query) => str_contains($query['sql'], 'insert into') && str_contains($query['sql'], 'benefit_adjustments'));

        expect($lockIndex)->not->toBeFalse()
            ->and($insertIndex)->not->toBeFalse()
            ->and($lockIndex)->toBeLessThan($insertIndex)
            ->and($queries[$lockIndex]['level'])->toBeGreaterThan(1)
            ->and($queries[$insertIndex]['level'])->toBe($queries[$lockIndex]['level']);

        if (DB::getDriverName() === 'mysql') {
            expect($queries[$lockIndex]['sql'])->toContain('for update');
        }
    });

    test('two operations prepared from the same state cannot both create incompatible events', function () {
        $periodSeenByFirstAdmin = BenefitPeriod::find($this->period->id);
        $periodSeenBySecondAdmin = BenefitPeriod::find($this->period->id);

        $this->registrar->register($periodSeenByFirstAdmin, $this->employee, AdjustmentReason::Vacation, AdjustmentSource::Manual, CarbonImmutable::parse('2026-09-16'), CarbonImmutable::parse('2026-09-16'));

        expect(fn () => $this->registrar->register($periodSeenBySecondAdmin, $this->employee, AdjustmentReason::MedicalCertificate, AdjustmentSource::Manual, CarbonImmutable::parse('2026-09-16'), CarbonImmutable::parse('2026-09-16')))
            ->toThrow(DomainException::class, 'Já existe uma ausência');

        expect(BenefitAdjustment::count())->toBe(1);
    });

    test('the status of the competence is read under the lock, not from the given model', function () {
        $staleOpenPeriod = BenefitPeriod::find($this->period->id);
        BenefitPeriod::whereKey($this->period->id)->update(['status' => BenefitPeriodStatus::Closed]);

        expect($staleOpenPeriod->status)->toBe(BenefitPeriodStatus::Open)
            ->and(fn () => $this->registrar->register($staleOpenPeriod, $this->employee, AdjustmentReason::Vacation, AdjustmentSource::Manual, CarbonImmutable::parse('2026-09-16'), CarbonImmutable::parse('2026-09-16')))
            ->toThrow(DomainException::class, 'está fechada');
    });
});

test('impacts are written with a single insert', function () {
    $inserts = 0;
    DB::listen(function (QueryExecuted $query) use (&$inserts) {
        if (str_contains(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'benefit_adjustment_impacts')) {
            $inserts++;
        }
    });

    ($this->register)(AdjustmentReason::Vacation, '2026-09-15');

    expect($inserts)->toBe(1);
});

test('the default impact explanation matches what is stored', function () {
    expect($this->registrar->standardDaysCount(AdjustmentReason::Vacation, CarbonImmutable::parse('2026-09-05'), CarbonImmutable::parse('2026-09-14')))->toBe(5)
        ->and($this->registrar->standardDaysCount(AdjustmentReason::HolidayWorked, CarbonImmutable::parse('2026-09-07'), CarbonImmutable::parse('2026-09-07')))->toBe(1)
        ->and($this->registrar->standardDaysCount(AdjustmentReason::Manual, CarbonImmutable::parse('2026-09-07'), CarbonImmutable::parse('2026-09-07')))->toBeNull();
});
