<?php

use App\Enums\AlternativeReviewReason;
use App\Enums\CalendarDayType;
use App\Enums\TimesheetDayStatus;
use App\Services\AlternativeDayResult;
use App\Services\AlternativeTimesheetRule;

/**
 * Regra alternativa (docs/espelho-ponto-regra-alternativa.md). Jornada de segunda a
 * quinta 07:30–12:00/13:00–17:30 e de sexta até 16:30.
 */
const ALT_MONDAY_TO_THURSDAY = ['entry' => '07:30', 'break_start' => '12:00', 'break_end' => '13:00', 'exit' => '17:30'];
const ALT_FRIDAY = ['entry' => '07:30', 'break_start' => '12:00', 'break_end' => '13:00', 'exit' => '16:30'];

/**
 * @param  list<string>  $times
 * @param  array<string, string>|null  $schedule
 */
function alternativeDay(array $times, ?array $schedule = ALT_MONDAY_TO_THURSDAY, CalendarDayType $type = CalendarDayType::BusinessDay, int $official = 0): AlternativeDayResult
{
    return (new AlternativeTimesheetRule)->evaluate($type, $schedule, $times, $official);
}

function expectReview(AlternativeDayResult $result, AlternativeReviewReason $reason): void
{
    expect($result->status)->toBe(TimesheetDayStatus::OutsideSchedule)
        ->and($result->reviewReason)->toBe($reason)
        ->and($result->status->label())->toBe('Conferência manual — fora das janelas previstas')
        ->and($result->needsReview())->toBeTrue()
        ->and($result->overtimeMinutes)->toBeNull()
        ->and($result->delayMinutes)->toBeNull();
}

/*
 * Os 25 dias do Cartao_Ponto_Exemplo.pdf. Sábados e domingos recebem o valor do PDF como
 * extra oficial. Os meios períodos 29/06 e 09/07 terminam dentro do intervalo de almoço:
 * a saída vale como 12:00 e os minutos no almoço não contam (o PDF mostra 00:24/04:30 e
 * 01:24/04:30; o atraso coincide e o extra perde 17 e 12 minutos).
 */
const ALT_REFERENCE_PDF = [
    '26/06 sex' => [['07:27', '12:00', '13:00', '16:53'], 'fri', 26, 0],
    '27/06 sáb' => [['07:08', '12:05'], 'sat', 297, null],
    '28/06 dom' => [['07:20', '12:07'], 'sun', 287, null],
    '29/06 seg' => [['07:23', '12:17'], 'mon', 7, 270],
    '30/06 ter' => [['09:10', '12:00', '13:00', '17:50'], 'mon', 20, 100],
    '01/07 qua' => [['07:24', '12:00', '13:00', '19:44'], 'mon', 140, 0],
    '02/07 qui' => [['07:32', '12:00', '13:00', '19:42'], 'mon', 132, 0],
    '03/07 sex' => [['07:20', '12:00', '13:00', '16:49'], 'fri', 29, 0],
    '04/07 sáb' => [['06:14', '12:10'], 'sat', 356, null],
    '05/07 dom' => [['06:44', '12:08'], 'sun', 324, null],
    '06/07 seg' => [['07:31', '12:00', '13:00', '19:44'], 'mon', 134, 0],
    '07/07 ter' => [['07:31', '12:00', '13:00', '19:45'], 'mon', 135, 0],
    '08/07 qua' => [['07:31', '12:00', '13:00', '17:50'], 'mon', 20, 0],
    '09/07 qui' => [['06:18', '12:12'], 'mon', 72, 270],
    '10/07 sex' => [['07:29', '12:00', '13:00', '16:44'], 'fri', 15, 0],
    '13/07 seg' => [['07:33', '12:00', '13:00', '17:54'], 'mon', 24, 0],
    '14/07 ter' => [['07:33', '12:00', '13:00', '17:51'], 'mon', 21, 0],
    '15/07 qua' => [['07:24', '12:00', '13:00', '17:50'], 'mon', 26, 0],
    '16/07 qui' => [['08:40', '12:00', '13:00', '17:50'], 'mon', 20, 70],
    '17/07 sex' => [['07:22', '12:00', '13:00', '16:48'], 'fri', 26, 0],
    '20/07 seg' => [['07:22', '12:00', '13:00', '17:52'], 'mon', 30, 0],
    '21/07 ter' => [['07:32', '12:00', '13:00', '17:57'], 'mon', 27, 0],
    '22/07 qua' => [['07:23', '12:00', '13:00', '16:22'], 'mon', 7, 68],
    '23/07 qui' => [['07:33', '12:00', '13:00', '17:54'], 'mon', 24, 0],
    '24/07 sex' => [['07:21', '12:00', '13:00', '16:32'], 'fri', 11, 0],
];

dataset('reference pdf', ALT_REFERENCE_PDF);

/**
 * @param  list<string>  $times
 */
function referenceDay(array $times, string $kind, ?int $overtime): AlternativeDayResult
{
    return match ($kind) {
        'sat' => alternativeDay($times, null, CalendarDayType::Saturday, $overtime),
        'sun' => alternativeDay($times, null, CalendarDayType::Sunday, $overtime),
        'fri' => alternativeDay($times, ALT_FRIDAY),
        default => alternativeDay($times),
    };
}

test('the reference pdf days are reproduced under the current decisions', function (array $times, string $kind, int $overtime, ?int $delay) {
    $result = referenceDay($times, $kind, $overtime);

    expect($result->overtimeMinutes)->toBe($overtime)
        ->and($result->delayMinutes)->toBe($delay);
})->with('reference pdf');

test('the reference pdf totals keep only the minutes outside the lunch interval', function () {
    $overtime = 0;
    $delay = 0;
    $review = 0;

    foreach (ALT_REFERENCE_PDF as [$times, $kind, $expectedOvertime]) {
        $official = in_array($kind, ['sat', 'sun'], true) ? $expectedOvertime : null;
        $result = referenceDay($times, $kind, $official);
        $overtime += $result->overtimeMinutes ?? 0;
        $delay += $result->delayMinutes ?? 0;
        $review += (int) ($result->status === TimesheetDayStatus::OutsideSchedule);
    }

    expect($overtime)->toBe(2239 - 17 - 12)
        ->and($delay)->toBe(778)
        ->and($review)->toBe(0);
});

dataset('specification cases', [
    'entrada 07:36' => [['07:36', '12:00', '13:00', '17:30'], 0, 6],
    'atraso grande e extra' => [['09:10', '12:00', '13:00', '17:50'], 20, 100],
    'saída antecipada' => [['07:23', '12:00', '13:00', '16:22'], 7, 68],
    'saída 3 min antes' => [['07:30', '17:27'], 0, 0],
    'retorno do almoço às 13:03' => [['07:30', '12:00', '13:03', '17:30'], 0, 0],
    'almoço curto dentro do intervalo' => [['07:30', '12:00', '12:30', '17:30'], 0, 0],
    'almoço cobrindo o intervalo' => [['07:35', '11:26', '13:13', '17:36'], 6, 0],
    'saída duplicada' => [['07:26', '17:30', '17:30'], 4, 0],
    'entrada e saída dentro do limiar' => [['07:34', '12:00', '13:04', '17:26'], 0, 0],
    '10 min depois do intervalo (P1, critério geral pendente)' => [['12:00', '13:10'], 0, 530],
    'duração de exatamente 14h' => [['05:00', '19:00'], 240, 0],
    'meio período com saída no almoço' => [['07:23', '12:17'], 7, 270],
    'meio período à tarde' => [['13:30', '17:30'], 0, 300],
    'almoço de 12:10 a 12:50' => [['07:30', '12:10', '12:50', '17:30'], 0, 0],
]);

test('the specification cases that are calculated keep their expected values', function (array $times, int $overtime, int $delay) {
    $result = alternativeDay($times);

    expect($result->status)->toBe(TimesheetDayStatus::Regular)
        ->and($result->overtimeMinutes)->toBe($overtime)
        ->and($result->delayMinutes)->toBe($delay);
})->with('specification cases');

dataset('specification review cases', [
    'almoço deslocado' => [['08:27', '14:16', '16:04', '17:28'], AlternativeReviewReason::ShiftedLunch],
    'almoço parcialmente deslocado' => [['07:30', '11:30', '12:30', '17:30'], AlternativeReviewReason::ShiftedLunch],
    'fora das janelas às 22:00' => [['18:00', '22:00'], AlternativeReviewReason::NightShiftGuard],
    'fora das janelas com 4 marcações' => [['06:00', '07:00', '18:00', '19:00'], AlternativeReviewReason::PairOutsideWindows],
    'dia misto' => [['07:30', '12:00', '18:00', '20:00'], AlternativeReviewReason::PairOutsideWindows],
    'confinado ao intervalo' => [['12:10', '12:50'], AlternativeReviewReason::PairOutsideWindows],
    'limites exatos do intervalo' => [['12:00', '13:00'], AlternativeReviewReason::PairOutsideWindows],
    '1 min antes do intervalo' => [['11:59', '13:00'], AlternativeReviewReason::LunchBoundary],
    '1 min depois do intervalo' => [['12:00', '13:01'], AlternativeReviewReason::LunchBoundary],
    '10 min antes do intervalo' => [['11:50', '13:00'], AlternativeReviewReason::LunchBoundary],
    'turnos noturnos seguidos' => [['06:00', '22:00'], AlternativeReviewReason::NightShiftGuard],
    'marcação às 04:59' => [['04:59', '13:30'], AlternativeReviewReason::NightShiftGuard],
    'duração acima de 14h' => [['05:10', '19:20'], AlternativeReviewReason::NightShiftGuard],
]);

test('the specification review cases go to manual review without alternative values', function (array $times, AlternativeReviewReason $reason) {
    expectReview(alternativeDay($times), $reason);
})->with('specification review cases');

test('the entry threshold ignores up to five minutes and counts the whole delay above it', function (string $entry, int $delay) {
    expect(alternativeDay([$entry, '12:00', '13:00', '17:30'])->lateEntryMinutes)->toBe($delay);
})->with([
    '5 min' => ['07:35', 0],
    '6 min' => ['07:36', 6],
]);

test('the early leave threshold ignores up to five minutes and counts the whole difference above it', function (string $exit, int $delay) {
    expect(alternativeDay(['07:30', '12:00', '13:00', $exit])->earlyLeaveMinutes)->toBe($delay);
})->with([
    '5 min' => ['17:25', 0],
    '6 min' => ['17:24', 6],
]);

test('entry and exit thresholds are applied separately and do not add up', function () {
    $result = alternativeDay(['07:35', '12:00', '13:00', '17:25']);

    expect($result->lateEntryMinutes)->toBe(0)
        ->and($result->earlyLeaveMinutes)->toBe(0)
        ->and($result->delayMinutes)->toBe(0);
});

test('lunch gaps of five or six minutes are not delays', function (array $times) {
    $result = alternativeDay($times);

    expect($result->status)->toBe(TimesheetDayStatus::Regular)
        ->and($result->delayMinutes)->toBe(0)
        ->and($result->overtimeMinutes)->toBe(0);
})->with([
    'saída 5 min antes das 12:00' => [['07:30', '11:55', '13:00', '17:30']],
    'saída 6 min antes das 12:00' => [['07:30', '11:54', '13:00', '17:30']],
    'retorno 5 min depois das 13:00' => [['07:30', '12:00', '13:05', '17:30']],
    'retorno 6 min depois das 13:00' => [['07:30', '12:00', '13:06', '17:30']],
]);

test('a gap away from the lunch interval with work over lunch counts as shifted lunch', function () {
    expectReview(alternativeDay(['07:30', '09:00', '09:06', '17:30']), AlternativeReviewReason::ShiftedLunch);
});

test('punches on the same minute are deduplicated without changing the input', function () {
    $times = ['07:26', '17:30:10', '17:30'];

    $result = alternativeDay($times);

    expect($result->consideredTimes)->toBe(['07:26', '17:30'])
        ->and($result->overtimeMinutes)->toBe(4)
        ->and($times)->toBe(['07:26', '17:30:10', '17:30']);
});

test('the punch count is classified after deduplication', function (array $times, TimesheetDayStatus $status) {
    $result = alternativeDay($times);

    expect($result->status)->toBe($status)
        ->and($result->overtimeMinutes)->toBeNull()
        ->and($result->delayMinutes)->toBeNull();
})->with([
    'nenhuma' => [[], TimesheetDayStatus::NoPunches],
    'uma' => [['07:30'], TimesheetDayStatus::OddPunches],
    'três' => [['07:30', '12:00', '17:30'], TimesheetDayStatus::OddPunches],
    'cinco' => [['07:30', '12:00', '13:00', '17:30', '18:00'], TimesheetDayStatus::ExcessPunches],
    'seis' => [['07:30', '09:00', '09:10', '12:00', '13:00', '17:30'], TimesheetDayStatus::ExcessPunches],
]);

test('a business day without punches is only flagged', function () {
    $result = alternativeDay([]);

    expect($result->status->label())->toBe('Sem marcação')
        ->and($result->reviewReason)->toBeNull();
});

test('two punches presume the lunch interval', function () {
    $result = alternativeDay(['07:20', '17:40']);

    expect($result->earlyArrivalMinutes)->toBe(10)
        ->and($result->afterExitMinutes)->toBe(10)
        ->and($result->overtimeMinutes)->toBe(20)
        ->and($result->delayMinutes)->toBe(0);
});

test('punches inside the lunch interval are not counted as overtime or delay', function (array $times, int $overtime, int $delay) {
    $result = alternativeDay($times);

    expect($result->status)->toBe(TimesheetDayStatus::Regular)
        ->and($result->overtimeMinutes)->toBe($overtime)
        ->and($result->delayMinutes)->toBe($delay);
})->with([
    'saída às 12:00 e retorno às 13:00' => [['07:30', '12:00', '13:00', '17:30'], 0, 0],
    'almoço de 12:10 a 12:50' => [['07:30', '12:10', '12:50', '17:30'], 0, 0],
    'retorno às 12:59' => [['07:30', '12:00', '12:59', '17:30'], 0, 0],
    'entrada às 12:00 com saída à tarde (P1)' => [['12:00', '17:30'], 0, 270],
    'entrada às 13:00 é janela da tarde' => [['13:00', '17:30'], 0, 270],
]);

test('a half day with the exit inside the lunch interval ends at 12:00', function (array $times, int $overtime) {
    $result = alternativeDay($times);

    expect($result->status)->toBe(TimesheetDayStatus::Regular)
        ->and($result->overtimeMinutes)->toBe($overtime)
        ->and($result->lateEntryMinutes)->toBe(0)
        ->and($result->earlyLeaveMinutes)->toBe(270)
        ->and($result->consideredTimes)->toBe($times);
})->with([
    '07:23 12:17' => [['07:23', '12:17'], 7],
    '06:18 12:12' => [['06:18', '12:12'], 72],
    '07:30 12:00' => [['07:30', '12:00'], 0],
    '07:30 12:59' => [['07:30', '12:59'], 0],
]);

test('a half day with the entry inside the lunch interval starts at 13:00', function (array $times, ?array $schedule, int $overtime) {
    $result = alternativeDay($times, $schedule);

    expect($result->status)->toBe(TimesheetDayStatus::Regular)
        ->and($result->overtimeMinutes)->toBe($overtime)
        ->and($result->lateEntryMinutes)->toBe(270)
        ->and($result->earlyLeaveMinutes)->toBe(0);
})->with([
    '12:30 17:30' => [['12:30', '17:30'], ALT_MONDAY_TO_THURSDAY, 0],
    '12:59 17:40' => [['12:59', '17:40'], ALT_MONDAY_TO_THURSDAY, 10],
    '12:30 16:30 na sexta' => [['12:30', '16:30'], ALT_FRIDAY, 0],
]);

test('lunch punches incompatible with the existing rules stay in manual review', function (array $times, AlternativeReviewReason $reason) {
    expectReview(alternativeDay($times), $reason);
})->with([
    'duas marcações no almoço' => [['12:10', '12:50'], AlternativeReviewReason::PairOutsideWindows],
    'entrada no almoço e saída às 13:00' => [['12:30', '13:00'], AlternativeReviewReason::PairOutsideWindows],
    'limite aprovado 12:00 13:01' => [['12:00', '13:01'], AlternativeReviewReason::LunchBoundary],
    'entrada no almoço com marcação noturna' => [['12:30', '22:00'], AlternativeReviewReason::NightShiftGuard],
]);

test('the night shift guard boundaries', function (array $times, bool $guarded) {
    $result = alternativeDay($times);

    expect($result->reviewReason === AlternativeReviewReason::NightShiftGuard)->toBe($guarded);
})->with([
    'saída às 21:59' => [['13:00', '21:59'], false],
    'saída às 22:00' => [['13:00', '22:00'], true],
    'entrada às 04:59' => [['04:59', '13:30'], true],
    'entrada às 05:00' => [['05:00', '13:30'], false],
    'exatamente 14h' => [['05:00', '19:00'], false],
    '14h01' => [['05:00', '19:01'], true],
    'marcação noturna isolada' => [['22:30'], true],
]);

test('weekends and holidays repeat the official overtime without delay', function (CalendarDayType $type, array $times, int $official, TimesheetDayStatus $status) {
    $result = alternativeDay($times, null, $type, $official);

    expect($result->status)->toBe($status)
        ->and($result->overtimeMinutes)->toBe($official)
        ->and($result->delayMinutes)->toBeNull()
        ->and($result->usesOfficialOvertime)->toBeTrue();
})->with([
    'sábado' => [CalendarDayType::Saturday, ['07:08', '12:05'], 297, TimesheetDayStatus::NonWorkingDayWorked],
    'domingo sem marcação' => [CalendarDayType::Sunday, [], 0, TimesheetDayStatus::NonWorkingDay],
    'feriado com 3 marcações' => [CalendarDayType::Holiday, ['05:49', '12:01', '13:00'], 371, TimesheetDayStatus::NonWorkingDayWorked],
    'feriado com marcação noturna' => [CalendarDayType::Holiday, ['22:10', '23:00'], 50, TimesheetDayStatus::NonWorkingDayWorked],
]);

test('a business day without schedule is not calculated', function () {
    $result = alternativeDay(['07:30', '17:30'], null);

    expect($result->status)->toBe(TimesheetDayStatus::NoSchedule)
        ->and($result->overtimeMinutes)->toBeNull();
});

test('calculated days are the sum of their non negative parcels', function () {
    $rule = new AlternativeTimesheetRule;
    $checked = 0;

    for ($first = 5 * 60; $first <= 13 * 60; $first += 13) {
        for ($last = $first + 30; $last <= 21 * 60; $last += 17) {
            $times = [sprintf('%02d:%02d', intdiv($first, 60), $first % 60), sprintf('%02d:%02d', intdiv($last, 60), $last % 60)];
            $result = $rule->evaluate(CalendarDayType::BusinessDay, ALT_MONDAY_TO_THURSDAY, $times, 0);

            if ($result->status !== TimesheetDayStatus::Regular) {
                expect($result->overtimeMinutes)->toBeNull()->and($result->delayMinutes)->toBeNull();

                continue;
            }

            expect($result->overtimeMinutes)->toBe($result->earlyArrivalMinutes + $result->afterExitMinutes)
                ->and($result->delayMinutes)->toBe($result->lateEntryMinutes + $result->earlyLeaveMinutes)
                ->and(min($result->earlyArrivalMinutes, $result->afterExitMinutes, $result->lateEntryMinutes, $result->earlyLeaveMinutes))->toBeGreaterThanOrEqual(0)
                ->and($result->lateEntryMinutes === 0 || $result->lateEntryMinutes > AlternativeTimesheetRule::THRESHOLD_MINUTES)->toBeTrue()
                ->and($result->earlyLeaveMinutes === 0 || $result->earlyLeaveMinutes > AlternativeTimesheetRule::THRESHOLD_MINUTES)->toBeTrue();
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(200);
});
