<?php

use App\Enums\PunchKind;
use App\Services\PunchSequence;

beforeEach(function () {
    $this->sequence = new PunchSequence;
});

test('classifies the candidate by its position in the day', function (array $existing, string $candidate, int $position, PunchKind $kind) {
    $result = $this->sequence->classify($existing, $candidate);

    expect($result['position'])->toBe($position)
        ->and($result['kind'])->toBe($kind)
        ->and($result['total'])->toBe(count($existing) + 1);
})->with([
    'first of an empty day' => [[], '07:30', 1, PunchKind::Entry],
    'second' => [['07:30:00'], '12:00', 2, PunchKind::Exit],
    'before the only punch' => [['12:00:00'], '07:30', 1, PunchKind::Entry],
    'third' => [['07:30:00', '12:00:00'], '13:00', 3, PunchKind::Entry],
    'fourth' => [['07:30:00', '12:00:00', '13:00:00'], '17:30', 4, PunchKind::Exit],
    'missing lunch return between existing' => [['07:30:00', '12:00:00', '17:30:00'], '13:00', 3, PunchKind::Entry],
    'fifth' => [['07:30:00', '12:00:00', '13:00:00', '17:30:00'], '18:00', 5, PunchKind::Entry],
    'sixth' => [['07:30:00', '12:00:00', '13:00:00', '17:30:00', '18:00:00'], '22:00', 6, PunchKind::Exit],
    'unordered input' => [['17:30:00', '07:30:00', '13:00:00'], '12:00', 2, PunchKind::Exit],
    'seconds ignored' => [['07:30:59'], '07:31', 2, PunchKind::Exit],
]);

test('a tie with an existing punch is refused', function (array $existing) {
    expect(fn () => $this->sequence->classify($existing, '08:00'))
        ->toThrow(DomainException::class, 'empate impede determinar a sequência');
})->with([
    'same minute' => [['08:00:00']],
    'same minute with seconds' => [['07:00:00', '08:00:45']],
]);

test('an existing punch without a valid time makes the sequence undetermined', function (array $existing) {
    expect(fn () => $this->sequence->classify($existing, '08:00'))
        ->toThrow(DomainException::class, 'não é possível determinar a sequência');
})->with([
    'null' => [['07:00:00', null]],
    'garbage' => [['xx:yy']],
]);

test('an invalid candidate time is refused', function () {
    expect(fn () => $this->sequence->classify([], '25:00'))->toThrow(DomainException::class, 'Horário da marcação inválido.');
});

test('a night shift is classified by calendar date, as in the timesheet mirror', function () {
    expect($this->sequence->classify([], '01:30')['kind'])->toBe(PunchKind::Entry)
        ->and($this->sequence->classify(['22:00:00'], '23:30')['kind'])->toBe(PunchKind::Exit);
});

test('the informed kind must match the side of the sequence', function (PunchKind $informed, int $position, bool $compatible) {
    $classification = ['position' => $position, 'kind' => $position % 2 === 1 ? PunchKind::Entry : PunchKind::Exit, 'total' => 4];
    $check = fn () => $this->sequence->ensureCompatible($informed, $classification, '12:00:00');

    if ($compatible) {
        expect($check)->not->toThrow(DomainException::class);

        return;
    }

    expect($check)->toThrow(DomainException::class, 'incompatível com o tipo informado');
})->with([
    'entry on odd' => [PunchKind::Entry, 1, true],
    'break end on odd' => [PunchKind::BreakEnd, 3, true],
    'exit on even' => [PunchKind::Exit, 4, true],
    'break start on even' => [PunchKind::BreakStart, 2, true],
    'exit on odd' => [PunchKind::Exit, 1, false],
    'entry on even' => [PunchKind::Entry, 2, false],
    'break start on odd' => [PunchKind::BreakStart, 3, false],
]);
