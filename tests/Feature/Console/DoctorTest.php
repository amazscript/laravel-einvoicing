<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Models\WebhookEvent;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;

const SECRET_REEL = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

function configurerCorrectement(): void
{
    config()->set('einvoicing.drivers.iopole.base_url', 'https://api.example.test');
    config()->set('einvoicing.drivers.iopole.token_url', 'https://api.example.test/token');
    config()->set('einvoicing.drivers.iopole.client_id', 'client-abc');
    config()->set('einvoicing.drivers.iopole.client_secret', 'secret-xyz');
    config()->set('einvoicing.drivers.iopole.customer_id', 'cust-1');
    config()->set('einvoicing.webhook.secret', SECRET_REEL);
    config()->set('einvoicing.webhook.middleware', ['api']);
}

it('ne signale rien quand tout est en ordre', function (): void {
    configurerCorrectement();
    Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => '1',
        'customer_id' => 'cust-1', 'siren' => '111111111', 'siret' => null, 'active' => true,
    ]);

    $this->artisan('einvoicing:doctor', ['--no-network' => true])->assertSuccessful();
});

it('signale un secret manquant', function (): void {
    configurerCorrectement();
    config()->set('einvoicing.webhook.secret', null);

    $this->artisan('einvoicing:doctor', ['--no-network' => true])
        ->expectsOutputToContain('toute livraison sera rejetée')
        ->assertFailed();
});

it('signale un secret trop court', function (): void {
    configurerCorrectement();
    config()->set('einvoicing.webhook.secret', 'trop-court');

    $this->artisan('einvoicing:doctor', ['--no-network' => true])
        ->expectsOutputToContain('trop court')
        ->assertFailed();
});

it('signale une limitation de débit sur la route', function (): void {
    configurerCorrectement();
    config()->set('einvoicing.webhook.middleware', ['api', 'throttle:60,1']);

    // Un 429 renvoyé à la plateforme déclencherait ses relances pour rien.
    $this->artisan('einvoicing:doctor', ['--no-network' => true])
        ->expectsOutputToContain('relances inutiles')
        ->assertFailed();
});

it('signale l\'absence de dossier actif', function (): void {
    configurerCorrectement();

    $this->artisan('einvoicing:doctor', ['--no-network' => true])
        ->expectsOutputToContain('UNROUTED')
        ->assertFailed();
});

it('signale les événements restés en souffrance', function (): void {
    configurerCorrectement();
    Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => '1',
        'customer_id' => 'cust-1', 'siren' => '111111111', 'siret' => null, 'active' => true,
    ]);
    WebhookEvent::query()->create([
        'event_id' => 'evt-1', 'event_type' => 'INVOICE_STATUS',
        'status' => WebhookEventStatus::Unrouted, 'payload' => [], 'received_at' => now(),
    ]);

    $this->artisan('einvoicing:doctor', ['--no-network' => true])
        ->expectsOutputToContain('events:retry')
        ->assertFailed();
});

it('n\'affiche jamais les identifiants dans son rapport', function (): void {
    configurerCorrectement();

    $sortie = new BufferedOutput;
    $this->app[Kernel::class]->call('einvoicing:doctor', ['--no-network' => true], $sortie);
    $texte = $sortie->fetch();

    // Ce rapport est fait pour être collé dans une conversation de support.
    expect($texte)->not->toContain('secret-xyz')
        ->and($texte)->not->toContain('client-abc')
        ->and($texte)->not->toContain(SECRET_REEL);
});

it('rend compte de l\'état de la plateforme', function (): void {
    configurerCorrectement();
    Http::fake([
        'https://api.example.test/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        'https://api.example.test/v1/config/customer/id' => Http::response('cust-1'),
        'https://api.example.test/v1/config/webhook' => Http::response([[
            'status' => 'ACTIVE',
            'interopData' => ['endpoints' => ['invoice' => ['callbackUrl' => 'https://exemple.test/webhook'], 'authentication' => []]],
        ]]),
    ]);

    $this->artisan('einvoicing:doctor')->expectsOutputToContain('jeton obtenu');
});

it('signale un webhook actif dépourvu de secret', function (): void {
    configurerCorrectement();
    Http::fake([
        'https://api.example.test/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        'https://api.example.test/v1/config/customer/id' => Http::response('cust-1'),
        'https://api.example.test/v1/config/webhook' => Http::response([[
            'status' => 'ACTIVE',
            'interopData' => ['endpoints' => ['invoice' => ['callbackUrl' => 'https://exemple.test/webhook']]],
        ]]),
    ]);

    $this->artisan('einvoicing:doctor')->expectsOutputToContain('aucun webhook actif ne porte de secret');
});
