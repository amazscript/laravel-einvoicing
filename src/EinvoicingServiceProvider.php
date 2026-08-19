<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing;

use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use AmazScript\Einvoicing\Contracts\PayloadInterpreter;
use AmazScript\Einvoicing\Contracts\SignatureVerifier;
use AmazScript\Einvoicing\Contracts\StatusMapper;
use AmazScript\Einvoicing\Contracts\TenantResolver;
use AmazScript\Einvoicing\Drivers\Iopole\AccessTokenProvider;
use AmazScript\Einvoicing\Drivers\Iopole\Client;
use AmazScript\Einvoicing\Drivers\Iopole\IopoleInvoiceGateway;
use AmazScript\Einvoicing\Drivers\Iopole\IopolePayloadInterpreter;
use AmazScript\Einvoicing\Drivers\Iopole\ResponseMappers\ErrorMapper;
use AmazScript\Einvoicing\Drivers\Iopole\ResponseMappers\IopoleStatusMapper;
use AmazScript\Einvoicing\Storage\InvoiceFileStore;
use AmazScript\Einvoicing\Tenancy\SiretResolver;
use AmazScript\Einvoicing\Webhook\HmacSignatureVerifier;
use AmazScript\Einvoicing\Webhook\WebhookController;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The package's entry point into the host application.
 *
 * Registers the callback route, the publishable resources, the Artisan commands,
 * and the bindings that let each replaceable piece — signature verifier, tenant
 * resolver, platform driver — be swapped by the application.
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

        // The resolver is replaceable: an application whose routing follows a
        // different rule declares its own class in the configuration.
        $this->app->bind(TenantResolver::class, function ($app): TenantResolver {
            $configured = $app->make('config')->get('einvoicing.tenant_resolver', SiretResolver::class);

            return $app->make(is_string($configured) ? $configured : SiretResolver::class);
        });

        $this->app->bind(InvoiceGateway::class, IopoleInvoiceGateway::class);

        $this->app->singleton(Einvoicing::class, function ($app): Einvoicing {
            return new Einvoicing(
                $app->make(Client::class),
                $app->make(InvoiceGateway::class),
                $app->make(InvoiceFileStore::class),
            );
        });
        $this->app->bind(PayloadInterpreter::class, IopolePayloadInterpreter::class);
        $this->app->bind(StatusMapper::class, IopoleStatusMapper::class);

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
     * Declares the platform's single callback URL.
     *
     * No rate limiting may be applied here: a 429 returned to the platform would
     * trigger its retry strategy for nothing.
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
     * A configuration value of the active driver, always returned as a string: a
     * missing environment variable must not propagate a null into typed
     * constructors.
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

        $this->commands([
            Console\DoctorCommand::class,
            Console\InstallCommand::class,
            Console\PollCommand::class,
            Console\PruneEventsCommand::class,
            Console\RetryEventsCommand::class,
            Console\SecretCommand::class,
            Console\SyncRetryStrategyCommand::class,
            Console\SyncWebhooksCommand::class,
        ]);
    }
}
