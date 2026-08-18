<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing;

use AmazScript\Einvoicing\Drivers\Iopole\AccessTokenProvider;
use AmazScript\Einvoicing\Drivers\Iopole\Client;
use AmazScript\Einvoicing\Drivers\Iopole\ResponseMappers\ErrorMapper;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée du package dans l'application hôte.
 *
 * Ne fait à ce stade que charger la configuration et exposer les ressources
 * publiables. L'enregistrement de la route webhook arrive en D04, les
 * migrations en D03 et les commandes Artisan en D15.
 */
final class EinvoicingServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__.'/../config/einvoicing.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'einvoicing');

        $this->app->singleton(AccessTokenProvider::class, function ($app): AccessTokenProvider {
            return new AccessTokenProvider(
                $app->make(HttpFactory::class),
                $app->make(Cache::class),
                $this->driverConfig('token_url'),
                $this->driverConfig('client_id'),
                $this->driverConfig('client_secret'),
            );
        });

        $this->app->singleton(Client::class, function ($app): Client {
            return new Client(
                $app->make(HttpFactory::class),
                $app->make(AccessTokenProvider::class),
                $app->make(ErrorMapper::class),
                $this->driverConfig('base_url'),
                $this->driverConfig('customer_id'),
            );
        });
    }

    /**
     * Valeur de configuration du driver actif, toujours rendue en chaîne : une
     * variable d'environnement absente ne doit pas propager un null jusqu'aux
     * constructeurs typés.
     */
    private function driverConfig(string $key): string
    {
        $config = $this->app->make('config');
        $driver = $config->get('einvoicing.default', 'iopole');
        $value = $config->get("einvoicing.drivers.{$driver}.{$key}");

        return is_scalar($value) ? (string) $value : '';
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            self::CONFIG_PATH => $this->app->configPath('einvoicing.php'),
        ], 'einvoicing-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'einvoicing-migrations');
    }
}
