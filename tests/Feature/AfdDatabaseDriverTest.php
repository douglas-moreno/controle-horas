<?php

use App\Services\AfdHistoricalCorrection;
use App\Services\AfdPrecheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Troca a conexão padrão por um SQLite em memória durante o callback, sem tocar no
 * banco de testes.
 */
function withUnsupportedDefaultConnection(Closure $callback): mixed
{
    $previous = config('database.default');
    config(['database.connections.afd_unsupported' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::setDefaultConnection('afd_unsupported');

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('afd_unsupported');
    }
}

test('the database identity reads the server identifier of the current driver', function () {
    $variable = DB::connection()->getDriverName() === 'mariadb' ? '@@server_uid' : '@@server_uuid';
    $expected = DB::selectOne('select '.$variable.' as id, @@hostname as hostname, database() as db');

    $target = app(AfdHistoricalCorrection::class)->target();

    expect($target['server_uuid'])->not->toBe('')
        ->toBe((string) $expected->id)
        ->and($target['hostname'])->toBe((string) $expected->hostname)
        ->and($target['database'])->toBe((string) $expected->db);
});

test('the database identity is accepted when it matches the current server', function () {
    $correction = app(AfdHistoricalCorrection::class);
    $target = $correction->target();

    expect($correction->assertTarget(['hostname' => $target['hostname'], 'server_uuid' => $target['server_uuid'], 'database' => $target['database']]))
        ->toBe($target);
});

test('mysql and mariadb are the supported drivers', function () {
    expect(AfdHistoricalCorrection::SUPPORTED_DRIVERS)->toBe(['mysql', 'mariadb'])
        ->and(DB::connection()->getDriverName())->toBeIn(AfdHistoricalCorrection::SUPPORTED_DRIVERS);
});

test('the precheck refuses an unsupported driver without running anything', function () {
    $ran = false;

    withUnsupportedDefaultConnection(function () use (&$ran) {
        expect(fn () => app(AfdPrecheck::class)->whileReadOnly(function () use (&$ran) {
            $ran = true;
        }))->toThrow(DomainException::class, 'A pré-validação exige conexão MySQL ou MariaDB');
    });

    expect($ran)->toBeFalse();
});

test('the historical correction refuses an unsupported driver', function () {
    withUnsupportedDefaultConnection(function () {
        expect(fn () => app(AfdHistoricalCorrection::class)->assertTarget(['hostname' => 'x', 'server_uuid' => 'x', 'database' => 'x']))
            ->toThrow(DomainException::class, 'A correção histórica exige MySQL ou MariaDB');
    });
});
