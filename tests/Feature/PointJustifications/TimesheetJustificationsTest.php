<?php

use App\Enums\JustificationCategory;
use App\Enums\JustificationTreatment;
use App\Models\PointJustification;
use App\Models\PointJustificationReason;
use App\Services\TimesheetJustifications;
use Carbon\CarbonImmutable;

/*
 * F5.4: testes diretos das funções puras de TimesheetJustifications (plano F5, cenários
 * 1–4 e 11), sem banco. Jornada 07:30–12:00 e 13:00–17:30; `lateEntry`/`earlyLeave` são
 * os atrasos já calculados pelo espelho (entrada com a tolerância já descontada).
 */
beforeEach(function () {
    $this->service = new TimesheetJustifications;
    $this->schedule = ['entry' => '07:30', 'break_start' => '12:00', 'break_end' => '13:00', 'exit' => '17:30'];
    $this->date = CarbonImmutable::parse('2026-07-08');
    $this->nextId = 1;

    $this->justification = function (array $attributes = []): PointJustification {
        $justification = new PointJustification;
        $justification->forceFill([
            'id' => $this->nextId++,
            'occurrence_date' => '2026-07-08',
            'start_time' => null,
            'end_time' => null,
            'ends_next_day' => false,
            'treatment' => JustificationTreatment::FullAllowance,
            'allowed_minutes' => null,
            ...$attributes,
        ]);
        $justification->setRelation('reason', (new PointJustificationReason)->forceFill(['name' => 'Consulta médica', 'category' => JustificationCategory::Absence]));

        return $justification;
    };

    $this->interval = fn (string $start, ?string $end, array $attributes = []) => ($this->justification)(['start_time' => $start.':00', 'end_time' => $end === null ? null : $end.':00', ...$attributes]);

    $this->allowance = fn (array $justifications, string $first = '08:30', string $last = '17:30', int $late = 55, int $early = 0) => $this->service
        ->allowance($this->date, $this->schedule, $first, $last, $late, $early, $justifications);
});

describe('allowance', function () {
    test('full allowance is limited to the late entry after the tolerance', function () {
        $justification = ($this->interval)('07:30', '08:30');

        expect(($this->allowance)([$justification]))->toBe([
            'late_entry' => 55,
            'early_leave' => 0,
            'total' => 55,
            'by_justification' => [$justification->id => 55],
        ]);
    });

    test('only the part of the interval inside the delay is allowed', function () {
        expect(($this->allowance)([($this->interval)('08:00', '09:00')])['total'])->toBe(30)
            ->and(($this->allowance)([($this->interval)('09:00', '10:00')])['total'])->toBe(0);
    });

    test('the early leave is allowed in full, without tolerance', function () {
        $result = ($this->allowance)([($this->interval)('17:00', '17:30')], last: '17:00', late: 0, early: 30);

        expect($result)->toMatchArray(['late_entry' => 0, 'early_leave' => 30, 'total' => 30]);
    });

    test('partial allowance takes the informed minutes, entry first', function (int $informed, int $late, int $early) {
        $justification = ($this->justification)(['treatment' => JustificationTreatment::PartialAllowance, 'allowed_minutes' => $informed]);

        expect(($this->allowance)([$justification], last: '17:00', early: 30))->toMatchArray(['late_entry' => $late, 'early_leave' => $early, 'total' => $late + $early]);
    })->with([
        'below the late entry' => [20, 20, 0],
        'beyond the late entry' => [70, 55, 15],
        'beyond the whole delay' => [500, 55, 30],
    ]);

    test('treatments without allowance and single punches allow nothing', function (array $attributes) {
        $result = ($this->allowance)([($this->justification)($attributes)]);

        expect($result)->toBe(['late_entry' => 0, 'early_leave' => 0, 'total' => 0, 'by_justification' => []]);
    })->with([
        'no allowance' => [['treatment' => JustificationTreatment::NoAllowance]],
        'hour bank' => [['treatment' => JustificationTreatment::HourBank]],
        'single punch' => [['start_time' => '07:30:00', 'end_time' => null]],
    ]);

    test('the scheduled break is never allowed', function () {
        $result = ($this->allowance)([($this->justification)()], first: '13:30', late: 300);

        expect($result['total'])->toBe(300);
    });

    test('several justifications never exceed the delay', function () {
        $first = ($this->interval)('07:30', '08:30');
        $second = ($this->interval)('08:00', '08:30');
        $third = ($this->justification)(['treatment' => JustificationTreatment::PartialAllowance, 'allowed_minutes' => 60]);

        $result = ($this->allowance)([$first, $second, $third], last: '17:00', early: 30);

        expect($result)->toMatchArray(['late_entry' => 55, 'early_leave' => 30, 'total' => 85])
            ->and($result['by_justification'])->toBe([$first->id => 55, $second->id => 0, $third->id => 30]);
    });

    test('a justification of the previous day crossing midnight allows only the part after midnight', function () {
        $crossing = ($this->interval)('22:00', '08:00', ['occurrence_date' => '2026-07-07', 'ends_next_day' => true]);
        $previousFullDay = ($this->justification)(['occurrence_date' => '2026-07-07']);

        expect(($this->allowance)([$crossing])['total'])->toBe(30)
            ->and(($this->allowance)([$previousFullDay])['total'])->toBe(0);
    });
});

describe('review and suggestions', function () {
    test('full day justifications are matched to their own date and treatment', function (array $attributes, bool $justifies, bool $suppresses) {
        $justification = ($this->justification)($attributes);

        expect($this->service->justifiesAbsence($this->date, [$justification]) !== null)->toBe($justifies)
            ->and($this->service->suppressesAbsenceSuggestion($this->date, [$justification]) !== null)->toBe($suppresses);
    })->with([
        'full allowance' => [[], true, true],
        'partial allowance' => [['treatment' => JustificationTreatment::PartialAllowance, 'allowed_minutes' => 240], true, true],
        'no allowance' => [['treatment' => JustificationTreatment::NoAllowance], true, false],
        'hour bank (not supported)' => [['treatment' => JustificationTreatment::HourBank], false, false],
        'interval' => [['start_time' => '07:30:00', 'end_time' => '17:30:00'], false, false],
        'other date' => [['occurrence_date' => '2026-07-07'], false, false],
        'crossing midnight from the previous day' => [['occurrence_date' => '2026-07-07', 'start_time' => '22:00:00', 'end_time' => '23:59:00', 'ends_next_day' => true], false, false],
    ]);
});

describe('presentation', function () {
    test('the note describes the treatment, the reason and the period', function (array $attributes, int $allowed, string $note) {
        expect($this->service->note($this->service->present(($this->justification)($attributes), $allowed)))->toBe($note);
    })->with([
        'allowed interval' => [['start_time' => '07:30:00', 'end_time' => '08:30:00'], 55, 'Abonado — Consulta médica, 07:30–08:30: 00:55'],
        'full day without effect' => [[], 0, 'Justificativa — Consulta médica, dia inteiro'],
        'not allowed' => [['treatment' => JustificationTreatment::NoAllowance], 0, 'Não abonado — Consulta médica, dia inteiro'],
        'single punch' => [['start_time' => '12:00:00', 'treatment' => JustificationTreatment::NoAllowance], 0, 'Não abonado — Consulta médica, marcação às 12:00'],
        'crossing midnight' => [['start_time' => '22:00:00', 'end_time' => '08:00:00', 'ends_next_day' => true], 30, 'Abonado — Consulta médica, 22:00–08:00 (dia seguinte): 00:30'],
    ]);
});
