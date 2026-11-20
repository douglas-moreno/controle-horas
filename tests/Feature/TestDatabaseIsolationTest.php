<?php

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

test('the tests run on the isolated MySQL testing database', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::selectOne('select database() as name')->name)->toBe(TestCase::TEST_DATABASE)
        ->and(DB::selectOne('select version() as version')->version)->toStartWith('8.');
});
