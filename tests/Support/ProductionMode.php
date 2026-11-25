<?php

namespace Tests\Support;

use Illuminate\Contracts\Foundation\MaintenanceMode;

/**
 * Simula o ambiente de produção nos testes, com a aplicação em manutenção ou não, sem
 * gravar storage/framework/down, que derrubaria os testes HTTP executados em paralelo.
 */
final class ProductionMode
{
    public static function enter(bool $down): void
    {
        app()['env'] = 'production';
        app()->instance(MaintenanceMode::class, new class($down) implements MaintenanceMode
        {
            public function __construct(private bool $down) {}

            public function activate(array $payload): void {}

            public function deactivate(): void {}

            public function active(): bool
            {
                return $this->down;
            }

            public function data(): array
            {
                return [];
            }
        });
    }

    public static function leave(): void
    {
        app()['env'] = 'testing';
    }
}
