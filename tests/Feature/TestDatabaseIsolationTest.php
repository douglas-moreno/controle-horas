<?php

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

test('the tests run on the isolated MySQL or MariaDB testing database', function () {
    $driver = DB::connection()->getDriverName();
    $version = DB::selectOne('select version() as version')->version;

    expect($driver)->toBeIn(TestCase::TEST_DRIVERS)
        ->and(DB::selectOne('select database() as name')->name)->toBe(TestCase::TEST_DATABASE);

    if ($driver === 'mariadb') {
        expect($version)->toContain('MariaDB');
    } else {
        expect($version)->toStartWith('8.')->not->toContain('MariaDB');
    }
});
