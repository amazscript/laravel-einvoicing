<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Tests;

use AmazScript\Einvoicing\EinvoicingServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            EinvoicingServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        /** @var Application $app */

        // Clé jetable, régénérée à chaque test : le chiffrement du customer-id doit être
        // testé pour de vrai, sans qu'aucune clé ne traîne dans le dépôt.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'einvoicing_testing');
        $app['config']->set('database.connections.einvoicing_testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // Les contraintes de clé étrangère ne sont pas actives par défaut sous
            // SQLite : sans cette ligne, les tests d'intégrité ne testeraient rien.
            'foreign_key_constraints' => true,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
