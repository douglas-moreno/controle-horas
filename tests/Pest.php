<?php

use App\Models\AfdHistoricalNsr;
use App\Models\Holiday;
use App\Services\AfdLegacyAlignment;
use App\Services\AfdParser;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->beforeEach(fn () => Holiday::flushCachedHolidays())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Registra marcações do AFD como pendências históricas conhecidas (`pending`), como o
 * lote de registro da correção histórica: o importador legado não as gravou (pulo das
 * primeiras linhas ou posição do trailer de uma execução anterior).
 *
 * @param  list<int>  $nsrs
 */
function registerHistoricalPendings(string $path, array $nsrs, string $classification = 'X'): void
{
    $lines = app(AfdLegacyAlignment::class)->readLines($path);
    $repSerial = app(AfdParser::class)->parse($lines[1], 1)->repSerial;

    foreach ($nsrs as $nsr) {
        $lineNumber = collect($lines)->search(fn (string $line, int $number) => $number > 1 && substr($line, 9, 1) === '3' && (int) substr($line, 0, 9) === $nsr);

        AfdHistoricalNsr::factory()->create([
            'rep_serial' => $repSerial,
            'nsr' => $nsr,
            'afd_line' => $lineNumber,
            'afd_line_sha256' => hash('sha256', $lines[$lineNumber]),
            'classification' => $classification,
        ]);
    }
}
