<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing;

use AmazScript\Einvoicing\Contracts\SignatureVerifier;
use AmazScript\Einvoicing\Contracts\TenantResolver;
use AmazScript\Einvoicing\Drivers\Iopole\AccessTokenProvider;
use AmazScript\Einvoicing\Drivers\Iopole\Client;
use AmazScript\Einvoicing\Drivers\Iopole\ResponseMappers\ErrorMapper;
use AmazScript\Einvoicing\Tenancy\SiretResolver;
use AmazScript\Einvoicing\Webhook\HmacSignatureVerifier;
use AmazScript\Einvoicing\Webhook\WebhookController;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
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

        // Le résolveur est remplaçable : une application dont le routage suit une
        // autre règle déclare sa propre classe dans la configuration.
        $this->app->bind(TenantResolver::class, function ($app): TenantResolver {
            $configured = $app->make('config')->get('einvoicing.tenant_resolver', SiretResolver::class);

            return $app->make(is_string($configured) ? $configured : SiretResolver::class);
        });

        $this->app->bind(SignatureVerifier::class, function ($app): SignatureVerifier {
            $webhook = $app->make('config')->get('einvoicing.webhook', []);

            return new HmacSignatureVerifier(
                is_array($webhook) && is_string($webhook['secret'] ?? null) ? $webhook['secret'] : '',
                is_array($webhook) && is_numeric($webhook['tolerance'] ?? null) ? (int) $webhook['tolerance'] : 300,
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
     * Déclare l'URL de rappel unique de la plateforme.
     *
     * Aucune limitation de débit ne doit être appliquée ici : un 429 renvoyé à
     * la plateforme déclencherait sa stratégie de retry sans raison.
     */
    private function registerWebhookRoute(): void
    {
        $config = $this->app->make('config');
        $path = $config->get('einvoicing.webhook.path');

        if (! is_string($path) || $path === '') {
            return;
        }

        $middleware = $config->get('einvoicing.webhook.middleware', ['api']);

        Route::post($path, WebhookController::class)
            ->middleware(is_array($middleware) ? $middleware : ['api'])
            ->name('einvoicing.webhook');
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
        $this->registerWebhookRoute();

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
