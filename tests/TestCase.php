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
