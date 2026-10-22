<?php

use App\Http\Controllers\AlternativeComparisonExportController;
use App\Livewire\AlternativeComparisonReport;
use App\Livewire\AlternativeEmployeeCodes;
use App\Models\Employee;
use App\Models\Point;
use App\Models\User;
use App\Services\AlternativeComparison;
use App\Services\AlternativeEmployeeCode;
use App\Services\TimesheetMirror;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/**
 * Fase 7.5: tela de comparação, correspondência código → funcionário e CSV.
 * Período: 2026-07-06 segunda a 2026-07-12 domingo.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ana = Employee::factory()->create(['name' => 'Ana Comparada', 'pis' => '11122233344', 'position' => 'Operadora', 'recision_date' => null]);
    $this->bruno = Employee::factory()->create(['name' => 'Bruno Comparado', 'pis' => '55566677788', 'position' => 'Operador', 'recision_date' => null]);

    foreach ([
        [$this->ana, '2026-07-06', ['07:36', '12:00', '13:00', '17:30']],
        [$this->ana, '2026-07-08', ['08:27', '14:16', '16:04', '17:28']],
        [$this->ana, '2026-07-11', ['07:08', '12:05']],
        [$this->bruno, '2026-07-07', ['07:30', '12:00', '13:00', '17:30']],
        [$this->bruno, '2026-07-09', ['07:26', '17:30', '17:30']],
    ] as [$employee, $date, $times]) {
        foreach ($times as $time) {
            Point::factory()->forEmployee($employee)->on($date)->create(['time' => $time.':00']);
        }
    }
});

function comparisonPeriod(): array
{
    return ['start' => '2026-07-06', 'end' => '2026-07-12'];
}

/**
 * @return list<list<string>>
 */
function comparisonCsvRows(string $content): array
{
    expect(str_starts_with($content, "\u{FEFF}"))->toBeTrue();

    $lines = preg_split('/\r?\n/', trim(substr($content, 3)));

    return array_map(fn (string $line) => str_getcsv($line, ';', '"', ''), $lines);
}

function enableAlternativeRule(): void
{
    config(['timesheet.alternative_rule.enabled' => true]);
}

test('guests are redirected to the login on the three surfaces', function (string $route, array $parameters) {
    enableAlternativeRule();

    $this->get(route($route, $parameters))->assertRedirect(route('login'));
})->with([
    'screen' => ['reports.alternative-comparison', []],
    'codes' => ['reports.alternative-comparison.codes', []],
    'csv' => ['reports.alternative-comparison.csv', comparisonPeriod()],
]);

test('authenticated users can access the three surfaces', function () {
    enableAlternativeRule();
    $this->actingAs(User::factory()->create());

    $this->get(route('reports.alternative-comparison', ['startDate' => '2026-07-06', 'endDate' => '2026-07-12']))->assertOk();
    $this->get(route('reports.alternative-comparison.codes'))->assertOk();
    $this->get(route('reports.alternative-comparison.csv', comparisonPeriod()))->assertOk();
});

test('nothing is produced while the alternative rule is disabled by default', function () {
    $this->actingAs(User::factory()->create());
    $code = app(AlternativeEmployeeCode::class)->for($this->ana->id);

    expect(config('timesheet.alternative_rule.enabled'))->toBeFalse();

    Livewire::withQueryParams(['startDate' => '2026-07-06', 'endDate' => '2026-07-12'])
        ->test(AlternativeComparisonReport::class)
        ->assertSee('A regra alternativa está desligada')
        ->assertDontSee($code)
        ->assertDontSee('Totais por funcionário');

    Livewire::test(AlternativeEmployeeCodes::class)
        ->assertSee('A regra alternativa está desligada')
        ->assertDontSee($code)
        ->assertDontSee('Ana Comparada');

    $this->get(route('reports.alternative-comparison.csv', comparisonPeriod()))->assertNotFound();

    $comparison = app(AlternativeComparison::class)->compare(CarbonImmutable::parse('2026-07-06'), CarbonImmutable::parse('2026-07-12'), 'test');

    expect($comparison['enabled'])->toBeFalse()
        ->and($comparison['employees'])->toBe([])
        ->and($comparison['days'])->toBe([])
        ->and($comparison['totals'])->toBeNull();

    $this->get(route('dashboard'))->assertDontSee('Comparação alternativa');
});

test('the sidebar links to the comparison only when the rule is enabled', function () {
    $this->actingAs(User::factory()->create());
    enableAlternativeRule();

    $this->get(route('dashboard'))->assertSee('Comparação alternativa');
});

test('the comparison lists totals by code and the divergent days', function () {
    enableAlternativeRule();

    $comparison = app(AlternativeComparison::class)->compare(CarbonImmutable::parse('2026-07-06'), CarbonImmutable::parse('2026-07-12'), 'test');
    $codes = app(AlternativeEmployeeCode::class);
    $employees = collect($comparison['employees'])->keyBy('code');
    $days = collect($comparison['days'])->keyBy(fn (array $day) => $day['code'].'|'.$day['date']);
    $ana = $codes->for($this->ana->id);
    $bruno = $codes->for($this->bruno->id);

    expect($comparison['days'])->toHaveCount(14)
        ->and($employees[$ana])->toBe([
            'code' => $ana,
            'official_overtime_minutes' => 297,
            'alternative_overtime_minutes' => 297,
            'official_delay_minutes' => 55,
            'alternative_delay_minutes' => 6,
            'official_review_days' => 4,
            'alternative_review_days' => 5,
            'divergent_days' => 2,
        ])
        ->and($employees[$bruno])->toMatchArray([
            'official_overtime_minutes' => 4,
            'alternative_overtime_minutes' => 4,
            'official_delay_minutes' => 0,
            'alternative_delay_minutes' => 0,
            'divergent_days' => 1,
        ])
        ->and($comparison['totals'])->toBe([
            'official_overtime_minutes' => 301,
            'alternative_overtime_minutes' => 301,
            'official_delay_minutes' => 55,
            'alternative_delay_minutes' => 6,
            'official_review_days' => 8,
            'alternative_review_days' => 8,
            'divergent_days' => 3,
        ])
        ->and($days[$ana.'|2026-07-06'])->toMatchArray([
            'official_delay_minutes' => 1,
            'alternative_delay_minutes' => 6,
            'divergent' => true,
        ])
        ->and($days[$ana.'|2026-07-08'])->toMatchArray([
            'official_status' => 'Calculado',
            'official_overtime_minutes' => 0,
            'official_delay_minutes' => 54,
            'alternative_status' => 'Conferência manual — fora das janelas previstas',
            'alternative_review_reason' => 'shifted_lunch',
            'alternative_review_reason_label' => 'Almoço fora do intervalo previsto',
            'alternative_overtime_minutes' => null,
            'alternative_delay_minutes' => null,
            'divergent' => true,
        ])
        ->and($days[$ana.'|2026-07-07'])->toMatchArray([
            'official_status' => 'Sem marcação',
            'alternative_status' => 'Sem marcação',
            'alternative_overtime_minutes' => null,
            'divergent' => false,
        ])
        ->and($days[$ana.'|2026-07-11'])->toMatchArray([
            'official_overtime_minutes' => 297,
            'alternative_overtime_minutes' => 297,
            'divergent' => false,
        ])
        ->and($days[$bruno.'|2026-07-09'])->toMatchArray([
            'times' => ['07:26', '17:30'],
            'official_status' => 'Marcações incompletas',
            'official_delay_minutes' => null,
            'alternative_status' => 'Calculado',
            'alternative_delay_minutes' => 0,
            'divergent' => true,
        ]);
});

test('the official values of the comparison are the official mirror values, identical with the rule disabled', function () {
    $start = CarbonImmutable::parse('2026-07-06');
    $end = CarbonImmutable::parse('2026-07-12');
    $pointsBefore = Point::query()->orderBy('id')->get()->toArray();
    $officialDisabled = app(TimesheetMirror::class)->forEmployee($this->ana, $start, $end);

    enableAlternativeRule();

    $comparison = app(AlternativeComparison::class)->compare($start, $end, 'test');
    $officialEnabled = app(TimesheetMirror::class)->forEmployee($this->ana, $start, $end);
    $anaDays = collect($comparison['days'])->where('code', app(AlternativeEmployeeCode::class)->for($this->ana->id))->values();

    expect(array_map(fn (array $day) => Arr::except($day, 'alternative'), $officialEnabled['days']))->toEqual($officialDisabled['days'])
        ->and(Arr::except($officialEnabled['totals'], 'alternative'))->toEqual($officialDisabled['totals'])
        ->and($anaDays->pluck('official_overtime_minutes')->all())->toBe(array_column($officialDisabled['days'], 'overtime_minutes'))
        ->and($anaDays->pluck('official_delay_minutes')->all())->toBe(array_column($officialDisabled['days'], 'delay_minutes'))
        ->and(Point::query()->orderBy('id')->get()->toArray())->toBe($pointsBefore);
});

test('the screen shows codes, official and alternative values, null as not calculated and the review reason', function () {
    enableAlternativeRule();
    $this->actingAs(User::factory()->create());
    $ana = app(AlternativeEmployeeCode::class)->for($this->ana->id);

    Livewire::withQueryParams(['startDate' => '2026-07-06', 'endDate' => '2026-07-12'])
        ->test(AlternativeComparisonReport::class)
        ->assertSee('Totais por funcionário')
        ->assertSee($ana)
        ->assertSeeInOrder(['Total', '05:01', '05:01', '00:55', '00:06', '8', '8', '3'])
        ->assertSeeInOrder([$ana, '08/07/2026 Qua', '08:27 14:16 16:04 17:28', 'Calculado', '00:00', '00:54', 'Conferência manual — fora das janelas previstas', 'Almoço fora do intervalo previsto', '—', '—'])
        ->assertSee('Dias divergentes')
        ->assertDontSee('Ana Comparada')
        ->assertDontSee('11122233344')
        ->assertSee('jornada 1=07:30-12:00-13:00-17:30');
});

test('the screen can list every day and validates the period', function () {
    enableAlternativeRule();
    $this->actingAs(User::factory()->create());

    $component = Livewire::withQueryParams(['startDate' => '2026-07-06', 'endDate' => '2026-07-12'])
        ->test(AlternativeComparisonReport::class)
        ->assertSee('Dias divergentes')
        ->assertSee('(3)')
        ->set('onlyDivergent', false)
        ->assertSee('Todos os dias')
        ->assertSee('(14)');

    $component->set('startDate', '2026-07-13')->assertSee('Informe um período válido.');
});

test('a period without punches has no rows', function () {
    enableAlternativeRule();
    $this->actingAs(User::factory()->create());

    Livewire::withQueryParams(['startDate' => '2026-08-03', 'endDate' => '2026-08-09'])
        ->test(AlternativeComparisonReport::class)
        ->assertSee('Nenhuma marcação no período.');

    $rows = comparisonCsvRows($this->get(route('reports.alternative-comparison.csv', ['start' => '2026-08-03', 'end' => '2026-08-09']))->streamedContent());

    expect($rows)->toBe([AlternativeComparisonExportController::HEADER]);
});

test('the csv has one row per code and day, without name or pis, matching the comparison', function () {
    enableAlternativeRule();
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('reports.alternative-comparison.csv', comparisonPeriod()));
    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->headers->get('Content-Disposition'))->toContain('comparacao-regra-alternativa-2026-07-06-a-2026-07-12.csv');

    $content = $response->streamedContent();
    $rows = comparisonCsvRows($content);
    $header = array_shift($rows);
    $records = collect($rows)->map(fn (array $row) => array_combine($header, $row));
    $comparison = app(AlternativeComparison::class)->compare(CarbonImmutable::parse('2026-07-06'), CarbonImmutable::parse('2026-07-12'), 'test');
    $ana = app(AlternativeEmployeeCode::class)->for($this->ana->id);

    expect($header)->toBe(AlternativeComparisonExportController::HEADER)
        ->and($records)->toHaveCount(14)
        ->and($content)->not->toContain('Ana Comparada')
        ->and($content)->not->toContain('Bruno Comparado')
        ->and($content)->not->toContain('11122233344')
        ->and($content)->not->toContain('55566677788')
        ->and($records->pluck('codigo')->all())->toBe(array_column($comparison['days'], 'code'))
        ->and($records->sum(fn (array $row) => (int) $row['oficial_extra_min']))->toBe($comparison['totals']['official_overtime_minutes'])
        ->and($records->sum(fn (array $row) => (int) $row['alternativa_extra_min']))->toBe($comparison['totals']['alternative_overtime_minutes'])
        ->and($records->sum(fn (array $row) => (int) $row['oficial_atraso_min']))->toBe($comparison['totals']['official_delay_minutes'])
        ->and($records->sum(fn (array $row) => (int) $row['alternativa_atraso_min']))->toBe($comparison['totals']['alternative_delay_minutes'])
        ->and($records->where('divergente', 'sim')->count())->toBe($comparison['totals']['divergent_days'])
        ->and($records->firstWhere(fn (array $row) => $row['codigo'] === $ana && $row['data'] === '08/07/2026'))->toBe([
            'codigo' => $ana,
            'data' => '08/07/2026',
            'tipo_dia' => 'Dia útil',
            'marcacoes' => '08:27 14:16 16:04 17:28',
            'oficial_situacao' => 'Calculado',
            'oficial_extra_min' => '0',
            'oficial_atraso_min' => '54',
            'alternativa_situacao' => 'Conferência manual — fora das janelas previstas',
            'alternativa_motivo' => 'Almoço fora do intervalo previsto',
            'alternativa_extra_min' => '',
            'alternativa_atraso_min' => '',
            'divergente' => 'sim',
            'configuracao' => $comparison['configuration']['descriptor'],
        ]);
});

test('the csv validates the period', function (array $parameters, string $field) {
    enableAlternativeRule();
    $this->actingAs(User::factory()->create());

    $this->get(route('reports.alternative-comparison.csv', $parameters))->assertSessionHasErrors($field);
})->with([
    'missing start' => [['end' => '2026-07-12'], 'start'],
    'invalid end' => [['start' => '2026-07-06', 'end' => '12/07/2026'], 'end'],
    'inverted period' => [['start' => '2026-07-12', 'end' => '2026-07-06'], 'end'],
]);

test('text cells that could be read as formulas are neutralized', function (string|int|null $value, string $expected) {
    expect(AlternativeComparisonExportController::cell($value))->toBe($expected);
})->with([
    'equals' => ['=1+1', "'=1+1"],
    'plus' => ['+55', "'+55"],
    'minus' => ['-2', "'-2"],
    'at' => ['@SUM(A1)', "'@SUM(A1)"],
    'tab' => ["\tx", "'\tx"],
    'carriage return' => ["\rx", "'\rx"],
    'plain text' => ['Calculado', 'Calculado'],
    'time' => ['07:30 12:00', '07:30 12:00'],
    'integer' => [-5, '-5'],
    'null' => [null, ''],
]);

test('the employee code is stable, distinct and does not expose the id, name or pis', function () {
    $codes = app(AlternativeEmployeeCode::class);
    $code = $codes->for($this->ana->id);

    expect($code)->toMatch('/^F[0-9A-F]{10}$/')
        ->and($codes->for($this->ana->id))->toBe($code)
        ->and(app(AlternativeEmployeeCode::class)->for($this->ana->id))->toBe($code)
        ->and($codes->for($this->bruno->id))->not->toBe($code)
        ->and($code)->not->toContain((string) $this->ana->pis);

    config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);

    expect($codes->for($this->ana->id))->not->toBe($code);
});

test('the codes screen maps each code to the employee for authenticated users', function () {
    enableAlternativeRule();
    $this->actingAs(User::factory()->create());
    $codes = app(AlternativeEmployeeCode::class);

    Livewire::test(AlternativeEmployeeCodes::class)
        ->assertSeeInOrder([$codes->for($this->ana->id), 'Ana Comparada'])
        ->assertSeeInOrder([$codes->for($this->bruno->id), 'Bruno Comparado'])
        ->assertDontSee('11122233344')
        ->set('search', 'bruno')
        ->assertSee('Bruno Comparado')
        ->assertDontSee('Ana Comparada');
});

test('every comparison records the schedule configuration used, enabled or disabled', function () {
    Log::spy();
    $this->actingAs($user = User::factory()->create());

    $this->get(route('reports.alternative-comparison.csv', comparisonPeriod()))->assertNotFound();

    enableAlternativeRule();

    $this->get(route('reports.alternative-comparison.csv', comparisonPeriod()))->assertOk()->streamedContent();

    $configuration = app(AlternativeComparison::class)->configuration();
    $records = fn (bool $enabled) => fn (string $message, array $context) => $message === 'Comparação da regra alternativa'
        && $context['surface'] === 'csv'
        && $context['alternative_rule_enabled'] === $enabled
        && $context['start'] === '2026-07-06'
        && $context['end'] === '2026-07-12'
        && $context['user_id'] === $user->id
        && $context['configuration_id'] === $configuration['id']
        && $context['configuration'] === $configuration['descriptor'];

    Log::shouldHaveReceived('info')->withArgs($records(false))->once();
    Log::shouldHaveReceived('info')->withArgs($records(true))->once();

    expect($configuration['descriptor'])->toBe('jornada 1=07:30-12:00-13:00-17:30;2=07:30-12:00-13:00-17:30;3=07:30-12:00-13:00-17:30;4=07:30-12:00-13:00-17:30;5=07:30-12:00-13:00-16:30; tolerancia_entrada_oficial=5');
});

test('the recorded configuration follows the configured schedule', function () {
    $before = app(AlternativeComparison::class)->configuration();

    config(['timesheet.schedule.5.exit' => '17:00']);

    $after = app(AlternativeComparison::class)->configuration();

    expect($after['id'])->not->toBe($before['id'])
        ->and($after['descriptor'])->toContain('5=07:30-12:00-13:00-17:00');
});

test('the print page and the official reports show no alternative data with the rule enabled', function () {
    enableAlternativeRule();
    $this->actingAs(User::factory()->create());
    $code = app(AlternativeEmployeeCode::class)->for($this->ana->id);

    foreach ([
        route('reports.timesheet.print', ['start' => '2026-07-06', 'end' => '2026-07-12', 'employees' => [$this->ana->id]]),
        route('reports.extra-hours'),
        route('reports.resume'),
        route('employees.horas-extras', ['employee' => $this->ana->id, 'startDate' => '2026-07-06', 'endDate' => '2026-07-12']),
    ] as $url) {
        $this->get($url)->assertOk()
            ->assertDontSee($code)
            ->assertDontSee('Conferência manual — fora das janelas previstas')
            ->assertDontSee('Almoço fora do intervalo previsto');
    }
});
