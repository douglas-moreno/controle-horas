<?php

use Tests\TestCase;

/*
 * Trava dos testes de concorrência real e dos processos filhos: só `testing` ou o banco
 * isolado do modo paralelo (`testing_test_N`, N inteiro positivo), pelo nome inteiro.
 */
test('only the test database and the parallel isolated databases are accepted', function (string $database, bool $accepted) {
    expect(TestCase::isTestDatabase($database))->toBe($accepted);
})->with([
    ['testing', true],
    ['testing_test_1', true],
    ['testing_test_4', true],
    ['testing_test_12', true],
    ['laravel', false],
    ['', false],
    ['testing_test_0', false],
    ['testing_test_01', false],
    ['testing_test_', false],
    ['testing_test_1a', false],
    ['testing_test_-1', false],
    ['testing_production', false],
    ['testing2', false],
    ['xtesting', false],
    ['laravel_testing', false],
    ['testing_test_1_laravel', false],
    ["testing\n", false],
    ["testing_test_1\n", false],
    ['TESTING', false],
]);
