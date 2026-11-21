<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Banco isolado exigido pelos testes (phpunit.xml). Qualquer outra conexão padrão —
     * em particular o banco operacional `laravel` ou SQLite — interrompe o teste antes
     * de RefreshDatabase executar as migrations.
     */
    public const TEST_DATABASE = 'testing';

    /**
     * Nomes de banco aceitos pelos testes de concorrência real e pelos processos filhos:
     * `testing` ou o banco isolado do modo paralelo, `testing_test_N` (N inteiro positivo,
     * ParallelTesting::token()). Nome inteiro, sem sufixo nem quebra de linha (`\z`).
     */
    public const TEST_DATABASE_PATTERN = '/^testing(?:_test_[1-9][0-9]*)?\z/';

    public static function isTestDatabase(string $database): bool
    {
        return preg_match(self::TEST_DATABASE_PATTERN, $database) === 1;
    }

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $driver = $app['config']->get("database.connections.{$connection}.driver");
        $database = $app['config']->get("database.connections.{$connection}.database");

        if ($driver !== 'mysql' || $database !== self::TEST_DATABASE) {
            throw new RuntimeException("Testes exigem MySQL no banco '".self::TEST_DATABASE."'; conexão atual: {$driver} / {$database}.");
        }

        return $app;
    }
}
