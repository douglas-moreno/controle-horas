<?php

use App\Services\Money;

test('decimal strings are converted to exact cents', function (string $amount, int $expectedCents) {
    expect(Money::toCents($amount))->toBe($expectedCents);
})->with([
    '5.40' => ['5.40', 540],
    '10.32' => ['10.32', 1032],
    '0.1' => ['0.1', 10],
    '1234.5' => ['1234.5', 123450],
    '0' => ['0', 0],
    '0.00' => ['0.00', 0],
    '27.50' => ['27.50', 2750],
    '7.5' => ['7.5', 750],
    '0.01' => ['0.01', 1],
    'integer string' => ['42', 4200],
    'negative' => ['-5.40', -540],
    'negative zero' => ['-0.00', 0],
]);

test('integers are treated as whole amounts', function () {
    expect(Money::toCents(5))->toBe(500)
        ->and(Money::toCents(0))->toBe(0)
        ->and(Money::toCents(-3))->toBe(-300);
});

test('cents are converted back to a two decimal string', function (int $cents, string $expectedAmount) {
    expect(Money::fromCents($cents))->toBe($expectedAmount);
})->with([
    '540' => [540, '5.40'],
    '1032' => [1032, '10.32'],
    '10' => [10, '0.10'],
    '123450' => [123450, '1234.50'],
    'zero' => [0, '0.00'],
    'one cent' => [1, '0.01'],
    'negative' => [-540, '-5.40'],
    'negative cents only' => [-5, '-0.05'],
]);

test('conversion round trips without losing cents', function (string $amount) {
    expect(Money::fromCents(Money::toCents($amount)))->toBe($amount);
})->with(['5.40', '10.32', '433.44', '45000.84', '0.01']);

test('amounts with more than two decimal places are rejected', function () {
    Money::toCents('1.234');
})->throws(InvalidArgumentException::class, 'mais de duas casas decimais');

test('invalid formats are rejected', function (string $amount) {
    Money::toCents($amount);
})->throws(InvalidArgumentException::class)->with([
    'empty' => [''],
    'blank' => [' '],
    'comma decimal separator' => ['5,40'],
    'thousands separator' => ['1.234,56'],
    'letters' => ['abc'],
    'currency symbol' => ['R$ 5.40'],
    'leading dot' => ['.50'],
    'trailing dot' => ['5.'],
    'surrounding spaces' => [' 5.40 '],
    'explicit plus sign' => ['+5.40'],
    'scientific notation' => ['1e3'],
]);

test('amounts beyond the supported range are rejected', function () {
    Money::toCents('1234567890123456.00');
})->throws(InvalidArgumentException::class, 'fora do limite');
