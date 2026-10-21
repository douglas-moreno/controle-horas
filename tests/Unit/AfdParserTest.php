<?php

use App\Enums\AfdRecordType;
use App\Enums\AfdRejectionReason;
use App\Services\AfdParser;
use Tests\Support\AfdFileBuilder;

beforeEach(function () {
    $this->parser = new AfdParser;
    $this->lines = AfdFileBuilder::make()
        ->company()
        ->punch('012345678901', '15092026', '0812')
        ->clockAdjustment()
        ->employee('012345678901')
        ->lines();
});

describe('record types', function () {
    test('every record type of the layout is recognized with its exact length', function () {
        $records = collect($this->lines)->map(fn (string $line, int $index) => $this->parser->parse($line."\r\n", $index + 1));

        expect($records->map(fn ($record) => $record->type)->all())->toBe([
            AfdRecordType::Header,
            AfdRecordType::Company,
            AfdRecordType::Punch,
            AfdRecordType::ClockAdjustment,
            AfdRecordType::Employee,
            AfdRecordType::Trailer,
        ])
            ->and(collect($this->lines)->map(fn (string $line) => strlen($line))->all())->toBe([232, 299, 34, 34, 87, 46])
            ->and($records->pluck('nsr')->all())->toBe([0, 1, 2, 3, 4, null]);
    });

    test('the header gives the rep serial and the dates of the file', function () {
        $header = $this->parser->parse($this->lines[0], 1);

        expect($header->repSerial)->toBe(AfdFileBuilder::REP_SERIAL)
            ->and($header->startsOn->toDateString())->toBe('2026-09-01')
            ->and($header->endsOn->toDateString())->toBe('2026-09-30')
            ->and($header->generatedAt->format('Y-m-d H:i'))->toBe('2026-09-30 18:00');
    });

    test('the punch reads the pis from positions 023 to 034', function () {
        $punch = $this->parser->parse($this->lines[2], 3);

        expect($punch->isValidPunch())->toBeTrue()
            ->and($punch->nsr)->toBe(2)
            ->and($punch->date->toDateString())->toBe('2026-09-15')
            ->and($punch->time)->toBe('08:12:00')
            ->and($punch->pis)->toBe('012345678901');
    });

    test('a pis starting with a non zero digit is read whole', function () {
        $punch = $this->parser->parse('000000001'.'3'.'15092026'.'0812'.'987654321098', 2);

        expect($punch->pis)->toBe('987654321098');
    });

    test('the trailer gives the quantity of each record type', function () {
        expect($this->parser->parse($this->lines[5], 6)->trailerCounts)->toBe(['company' => 1, 'punch' => 1, 'clock_adjustment' => 1, 'employee' => 1]);
    });

    test('crlf and lf give the same record', function () {
        $crlf = $this->parser->parse($this->lines[2]."\r\n", 3);
        $lf = $this->parser->parse($this->lines[2]."\n", 3);

        expect($lf)->toEqual($crlf);
    });

    test('an unknown record type is a structural error', function () {
        expect(fn () => $this->parser->parse('000000001'.'7'.'15092026'.'0812', 2))
            ->toThrow(DomainException::class, 'linha 2 (NSR 1): tipo de registro desconhecido');
    });

    test('a non numeric nsr is a structural error', function () {
        expect(fn () => $this->parser->parse('00000000A'.'3'.'15092026'.'0812'.'012345678901', 2))
            ->toThrow(DomainException::class, 'NSR não numérico');
    });

    test('a record shorter than the nsr and the type is a structural error', function () {
        expect(fn () => $this->parser->parse('0000001', 2))->toThrow(DomainException::class, 'registro incompleto');
    });
});

describe('header and trailer', function () {
    test('a header of another length is an unsupported layout', function (int $length) {
        expect(fn () => $this->parser->parse(str_pad(substr($this->lines[0], 0, 10), $length, '0'), 1))
            ->toThrow(DomainException::class, 'Layout de AFD não suportado');
    })->with([
        'longer header, such as the one of ordinance 671' => [302],
        'shorter header' => [200],
    ]);

    test('a header with a nsr other than zero is invalid', function () {
        expect(fn () => $this->parser->parse('000000001'.substr($this->lines[0], 9), 1))->toThrow(DomainException::class, 'NSR 000000000');
    });

    test('a header with an invalid date is invalid', function () {
        expect(fn () => $this->parser->parse(substr_replace($this->lines[0], '31022026', 204, 8), 1))
            ->toThrow(DomainException::class, 'cabeçalho com campos inválidos');
    });

    test('a header with a non numeric rep serial is invalid', function () {
        expect(fn () => $this->parser->parse(substr_replace($this->lines[0], 'ABCDEFGHIJKLMNOPQ', 187, 17), 1))
            ->toThrow(DomainException::class, 'cabeçalho com campos inválidos');
    });

    test('a trailer with a wrong length or a wrong last character is invalid', function (string $trailer) {
        expect(fn () => $this->parser->parse($trailer, 6))->toThrow(DomainException::class, 'trailer inválido');
    })->with([
        'short' => ['999999999'.str_repeat('0', 20)],
        'last character' => ['999999999'.str_repeat('0', 36).'8'],
        'non numeric count' => ['999999999'.str_repeat('0', 35).'X9'],
    ]);
});

describe('punch validation', function () {
    test('an invalid punch is returned with its nsr and reason and without pis', function (string $line, AfdRejectionReason $reason) {
        $punch = $this->parser->parse($line, 2);

        expect($punch->type)->toBe(AfdRecordType::Punch)
            ->and($punch->nsr)->toBe(1)
            ->and($punch->rejection)->toBe($reason)
            ->and($punch->isValidPunch())->toBeFalse()
            ->and($punch->pis)->toBeNull()
            ->and($punch->date)->toBeNull();
    })->with([
        'february 31' => ['000000001'.'3'.'31022026'.'0812'.'012345678901', AfdRejectionReason::InvalidDate],
        'date that overflowed into year 5' => ['000000001'.'3'.'00000006'.'0001'.'012345678901', AfdRejectionReason::InvalidDate],
        'year out of range' => ['000000001'.'3'.'15091999'.'0812'.'012345678901', AfdRejectionReason::InvalidDate],
        'non numeric date' => ['000000001'.'3'.'15/09/26'.'0812'.'012345678901', AfdRejectionReason::InvalidDate],
        'hour 24' => ['000000001'.'3'.'15092026'.'2460'.'012345678901', AfdRejectionReason::InvalidTime],
        'minute 60' => ['000000001'.'3'.'15092026'.'0860'.'012345678901', AfdRejectionReason::InvalidTime],
        'non numeric pis' => ['000000001'.'3'.'15092026'.'0812'.'01234567890X', AfdRejectionReason::InvalidPis],
        'zero pis' => ['000000001'.'3'.'15092026'.'0812'.'000000000000', AfdRejectionReason::InvalidPis],
        'truncated line' => ['000000001'.'3'.'15092026'.'0812'.'0123', AfdRejectionReason::InvalidLength],
        'long line of another layout' => ['000000001'.'3'.'15092026'.'0812'.'012345678901'.str_repeat('0', 16), AfdRejectionReason::InvalidLength],
    ]);

    test('the error messages never contain the content of the line', function () {
        $pis = '012345678901';

        try {
            $this->parser->parse('000000001'.'7'.'15092026'.'0812'.$pis, 2);
        } catch (DomainException $exception) {
            expect($exception->getMessage())->not->toContain($pis)->not->toContain('15092026');
        }

        expect($this->parser->parse('000000001'.'3'.'31022026'.'0812'.$pis, 2)->pis)->toBeNull();
    });
});

describe('other records', function () {
    test('a non punch record with a wrong length is a structural error', function (int $index, string $type) {
        expect(fn () => $this->parser->parse($this->lines[$index].'X', $index + 1))
            ->toThrow(DomainException::class, 'registro tipo '.$type);
    })->with([
        'company' => [1, '2'],
        'clock adjustment' => [3, '4'],
        'employee' => [4, '5'],
    ]);

    test('a non punch record with an invalid date is a structural error', function () {
        expect(fn () => $this->parser->parse(substr_replace($this->lines[4], '31022026', 10, 8), 5))
            ->toThrow(DomainException::class, 'registro de empregado com campos inválidos');
    });

    test('an employee record with an unknown operation is a structural error', function () {
        expect(fn () => $this->parser->parse(substr_replace($this->lines[4], 'X', 22, 1), 5))
            ->toThrow(DomainException::class, 'registro de empregado com campos inválidos');
    });
});

describe('dates and times', function () {
    test('dates are validated by the calendar and by the round trip', function (string $value, ?string $expected) {
        expect($this->parser->date($value)?->toDateString())->toBe($expected);
    })->with([
        ['29022028', '2028-02-29'],
        ['29022026', null],
        ['31042026', null],
        ['00012026', null],
        ['01132026', null],
        ['00000006', null],
        ['1509202', null],
    ]);

    test('times go from 0000 to 2359', function (string $value, ?string $expected) {
        expect($this->parser->time($value))->toBe($expected);
    })->with([
        ['0000', '00:00:00'],
        ['2359', '23:59:00'],
        ['2400', null],
        ['1260', null],
        ['12:3', null],
    ]);
});
