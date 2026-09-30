<?php

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\BenefitType;
use App\Livewire\Benefits\BenefitCarryForwardIndex;
use App\Models\BenefitPeriod;
use App\Models\BenefitRate;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\Holiday;
use App\Models\TransportFare;
use App\Models\TransportFarePrice;
use App\Models\TransportRoute;
use App\Models\User;
use App\Services\BenefitAdjustmentRegistrar;
use App\Services\BenefitCarryForwardReport;
use App\Services\BenefitPeriodWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Setembro/2026 gera saldo negativo (21 dias-base, ajuste de −23 → saldo 2);
 * outubro/2026 é a competência seguinte.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Holiday::factory()->create(['date' => '2026-09-07', 'description' => 'Independência']);
    Holiday::factory()->create(['date' => '2026-10-12', 'description' => 'Nossa Senhora Aparecida']);
    BenefitRate::factory()->vr('27.50')->validFrom('2026-01-01')->create();
    BenefitRate::factory()->vd('7.50')->validFrom('2026-01-01')->create();

    $this->workflow = app(BenefitPeriodWorkflow::class);
    $this->report = app(BenefitCarryForwardReport::class);
    $this->september = BenefitPeriod::factory()->forCompetence('2026-09')->create();

    $this->employee = Employee::factory()->create(['name' => 'Ana Souza']);
    EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vr)->between('2026-01-01')->create();
    EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vd)->between('2026-01-01')->create();

    $this->closeSeptemberWithCarry = function (array $impacts, ?Employee $employee = null): void {
        app(BenefitAdjustmentRegistrar::class)->register(
            $this->september, $employee ?? $this->employee, AdjustmentReason::Manual, AdjustmentSource::Manual,
            CarbonImmutable::parse('2026-08-17'), CarbonImmutable::parse('2026-08-17'), 'Pago a maior.', $impacts,
        );
        $this->workflow->calculate($this->september);
        $this->workflow->close($this->september);
    };
});

test('F9-27 a balance whose next competence does not exist or is not closed is awaiting', function () {
    ($this->closeSeptemberWithCarry)(['vr' => -23]);

    expect($this->report->rows())->toBe([[
        'employee_id' => $this->employee->id,
        'employee_name' => 'Ana Souza',
        'benefit_type' => 'vr',
        'origin_competence' => '09/2026',
        'days' => 2,
        'status' => 'Aguardando',
        'reason' => 'A competência 10/2026 ainda não foi criada.',
    ]]);

    BenefitPeriod::factory()->forCompetence('2026-10')->create();

    expect($this->report->rows()[0]['status'])->toBe(BenefitCarryForwardReport::AWAITING)
        ->and($this->report->rows()[0]['reason'])->toBe('A competência 10/2026 ainda não foi fechada.');
});

test('F9-28 a balance consumed by the next competence is applied', function () {
    ($this->closeSeptemberWithCarry)(['vr' => -23]);
    $october = BenefitPeriod::factory()->forCompetence('2026-10')->create();
    $this->workflow->calculate($october);

    $row = $this->report->rows()[0];

    expect($row['status'])->toBe(BenefitCarryForwardReport::APPLIED)
        ->and($row['reason'])->toBe('Aplicado na competência 10/2026.')
        ->and($row['days'])->toBe(2);
});

test('F9-29 a balance of a closed origin not consumed by the closed next competence is not applied', function () {
    EmployeeBenefit::query()->where('benefit_type', BenefitType::Vr)->update(['ends_on' => '2026-09-30']);
    ($this->closeSeptemberWithCarry)(['vr' => -23]);
    $october = BenefitPeriod::factory()->forCompetence('2026-10')->create();
    $this->workflow->calculate($october);
    $this->workflow->close($october);

    $row = $this->report->rows()[0];

    expect($row['status'])->toBe(BenefitCarryForwardReport::NOT_APPLIED)
        ->and($row['reason'])->toBe('Sem elegibilidade ao benefício em 01/10/2026. Pendência administrativa.')
        ->and($row['benefit_type'])->toBe('vr')
        ->and($row['origin_competence'])->toBe('09/2026');
});

test('F9-30 benefits are independent in the report', function () {
    $fare = TransportFare::factory()->create();
    TransportFarePrice::factory()->for($fare)->amount('5.40')->validFrom('2026-01-01')->create();
    EmployeeBenefit::factory()->for($this->employee)->ofType(BenefitType::Vt)->between('2026-01-01', '2026-09-30')->create();
    TransportRoute::factory()->for($this->employee)->for($fare)->between('2026-01-01')->create();
    ($this->closeSeptemberWithCarry)(['vt' => -25]);
    $october = BenefitPeriod::factory()->forCompetence('2026-10')->create();
    $this->workflow->calculate($october);
    $this->workflow->close($october);

    $rows = $this->report->rows();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['benefit_type'])->toBe('vt')
        ->and($rows[0]['days'])->toBe(4)
        ->and($rows[0]['status'])->toBe(BenefitCarryForwardReport::NOT_APPLIED);
});

test('a balance of a preview that is not closed is not listed', function () {
    app(BenefitAdjustmentRegistrar::class)->register(
        $this->september, $this->employee, AdjustmentReason::Manual, AdjustmentSource::Manual,
        CarbonImmutable::parse('2026-08-17'), CarbonImmutable::parse('2026-08-17'), 'Pago a maior.', ['vr' => -23],
    );
    $this->workflow->calculate($this->september);

    expect($this->report->rows())->toBe([]);
});

test('the report can be limited to an origin competence', function () {
    ($this->closeSeptemberWithCarry)(['vr' => -23]);
    $october = BenefitPeriod::factory()->forCompetence('2026-10')->create();

    expect($this->report->rows($this->september))->toHaveCount(1)
        ->and($this->report->rows($october))->toBe([]);
});

test('F9-31 the report is read only', function () {
    ($this->closeSeptemberWithCarry)(['vr' => -23]);
    $october = BenefitPeriod::factory()->forCompetence('2026-10')->create();
    $this->workflow->calculate($october);

    $tables = ['benefit_periods', 'benefit_period_employees', 'benefit_calculations', 'benefit_adjustments', 'benefit_adjustment_impacts', 'benefit_period_status_changes'];
    $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->toArray()]);

    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $this->report->rows();
    Livewire::test(BenefitCarryForwardIndex::class);

    expect($writes)->toBe([]);

    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toArray())->toEqual($before[$table]);
    }
});

test('the report does not query per employee', function () {
    foreach (range(1, 4) as $index) {
        $employee = Employee::factory()->create();
        EmployeeBenefit::factory()->for($employee)->ofType(BenefitType::Vr)->between('2026-01-01')->create();
        app(BenefitAdjustmentRegistrar::class)->register(
            $this->september, $employee, AdjustmentReason::Manual, AdjustmentSource::Manual,
            CarbonImmutable::parse('2026-08-17'), CarbonImmutable::parse('2026-08-17'), 'Pago a maior.', ['vr' => -22],
        );
    }
    ($this->closeSeptemberWithCarry)(['vr' => -23]);
    $october = BenefitPeriod::factory()->forCompetence('2026-10')->create();
    $this->workflow->calculate($october);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $rows = $this->report->rows();
    DB::disableQueryLog();

    expect($rows)->toHaveCount(5)
        ->and(collect($rows)->pluck('status')->unique()->all())->toBe([BenefitCarryForwardReport::APPLIED])
        ->and(count(DB::getQueryLog()))->toBeLessThanOrEqual(5);
});

describe('screen', function () {
    test('the carry forward screen renders the table for an authenticated user', function () {
        ($this->closeSeptemberWithCarry)(['vr' => -23]);

        $this->get(route('benefits.carry-forward.index'))
            ->assertOk()
            ->assertSee('Pendências de Saldo')
            ->assertSeeInOrder(['Funcionário', 'Benefício', 'Competência origem', 'Dias', 'Situação', 'Motivo'])
            ->assertSeeInOrder(['Ana Souza', 'VR', '09/2026', '2', 'Aguardando']);
    });

    test('the screen requires authentication', function () {
        auth()->logout();

        $this->get(route('benefits.carry-forward.index'))->assertRedirect(route('login'));
    });

    test('the sidebar links to the carry forward screen', function () {
        $this->get(route('benefits.periods.index'))
            ->assertSee('Pendências de saldo')
            ->assertSee(route('benefits.carry-forward.index'));
    });

    test('the screen filters by status and has no actions', function () {
        ($this->closeSeptemberWithCarry)(['vr' => -23]);

        Livewire::test(BenefitCarryForwardIndex::class)
            ->assertSee('Ana Souza')
            ->assertDontSee('wire:click', false)
            ->set('filterStatus', BenefitCarryForwardReport::APPLIED)
            ->assertDontSee('Ana Souza')
            ->set('filterStatus', BenefitCarryForwardReport::AWAITING)
            ->assertSee('Ana Souza');
    });
});
