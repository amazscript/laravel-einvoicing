<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const PLATEFORME = 'https://api.example.test';

function tenantActif(string $siren = '111111111'): Tenant
{
    return Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => $siren,
        'customer_id' => 'cust-1', 'siren' => $siren, 'siret' => null, 'active' => true,
    ]);
}

function evenement(WebhookEventStatus $statut, array $payload = [], ?string $tenantId = null): WebhookEvent
{
    return WebhookEvent::query()->create([
        'event_id' => 'evt-'.bin2hex(random_bytes(4)),
        'event_type' => 'INVOICE_STATUS',
        'tenant_id' => $tenantId,
        'status' => $statut,
        'payload' => $payload ?: ['statusId' => 'sta-'.bin2hex(random_bytes(3))],
        'received_at' => now()->subDays(200),
    ]);
}

beforeEach(function (): void {
    config()->set('einvoicing.drivers.iopole.base_url', PLATEFORME);
    config()->set('einvoicing.drivers.iopole.token_url', PLATEFORME.'/token');
    config()->set('einvoicing.drivers.iopole.client_id', 'client');
    config()->set('einvoicing.drivers.iopole.client_secret', 'secret');
    config()->set('einvoicing.drivers.iopole.customer_id', 'cust-1');
    config()->set('einvoicing.webhook.secret', str_repeat('a', 64));
});

// ------------------------------------------------------------------ le secret

it('génère un secret assez long pour être sûr', function (): void {
    $this->artisan('einvoicing:secret')->assertSuccessful();
});

// ------------------------------------------------------------------- la purge

it('ne purge que les événements déjà traités', function (): void {
    evenement(WebhookEventStatus::Processed);
    evenement(WebhookEventStatus::Unrouted);
    evenement(WebhookEventStatus::Failed);

    $this->artisan('einvoicing:events:prune', ['--days' => 30])->assertSuccessful();

    // Non routé et en échec portent une donnée non exploitée : les effacer
    // reviendrait à perdre une facture.
    expect(WebhookEvent::query()->count())->toBe(2)
        ->and(WebhookEvent::query()->where('status', WebhookEventStatus::Processed)->count())->toBe(0);
});

it('épargne les événements récents', function (): void {
    $recent = evenement(WebhookEventStatus::Processed);
    $recent->forceFill(['received_at' => now()])->save();

    $this->artisan('einvoicing:events:prune', ['--days' => 30])->assertSuccessful();

    expect(WebhookEvent::query()->count())->toBe(1);
});

it('compte sans rien supprimer en simulation', function (): void {
    evenement(WebhookEventStatus::Processed);

    $this->artisan('einvoicing:events:prune', ['--days' => 30, '--dry-run' => true])->assertSuccessful();

    expect(WebhookEvent::query()->count())->toBe(1);
});

// ------------------------------------------------------------------- le rejeu

it('remet en file un événement dont le tenant existe désormais', function (): void {
    Queue::fake();
    $tenant = tenantActif();
    evenement(WebhookEventStatus::Unrouted, [], $tenant->id);

    $this->artisan('einvoicing:events:retry')->assertSuccessful();

    expect(WebhookEvent::query()->first()->status)->toBe(WebhookEventStatus::Received);
});

it('laisse en souffrance un événement toujours sans destinataire', function (): void {
    Queue::fake();
    evenement(WebhookEventStatus::Unrouted);

    $this->artisan('einvoicing:events:retry')->assertSuccessful();

    // Rien n'a changé côté tenants : l'événement doit le rester aussi.
    expect(WebhookEvent::query()->first()->status)->toBe(WebhookEventStatus::Unrouted);
    Queue::assertNothingPushed();
});

it('ne touche pas aux événements déjà traités', function (): void {
    Queue::fake();
    evenement(WebhookEventStatus::Processed);

    $this->artisan('einvoicing:events:retry')->assertSuccessful();

    expect(WebhookEvent::query()->first()->status)->toBe(WebhookEventStatus::Processed);
});

// ------------------------------------------------------------------ le repli

it('reprend les factures que la plateforme n\'a pas vues acquittées', function (): void {
    Queue::fake();
    tenantActif();

    Http::fake([
        PLATEFORME.'/v1/invoice/notSeen' => Http::response([['invoiceId' => 'inv-oubliee']]),
        PLATEFORME.'/v1/invoice/status/notSeen' => Http::response([]),
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
    ]);

    $this->artisan('einvoicing:poll')->assertSuccessful();

    expect(WebhookEvent::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->first()->event_type)->toBe('INVOICE_INBOUND');
});

it('ne reprend pas ce qu\'un webhook a déjà livré', function (): void {
    Queue::fake();
    $tenant = tenantActif();

    // Reçu par webhook : la clé vient de l'en-tête d'idempotence, tandis que le
    // repli ne connaît que l'identifiant métier. Le doublon se joue ici.
    WebhookEvent::query()->create([
        'event_id' => '01a01903-fae5-73b6-8887-f9864011e65b',
        'event_type' => 'INVOICE_INBOUND',
        'tenant_id' => $tenant->id,
        'status' => WebhookEventStatus::Processed,
        'payload' => ['invoiceId' => 'inv-deja-recue'],
        'received_at' => now(),
    ]);

    Http::fake([
        PLATEFORME.'/v1/invoice/notSeen' => Http::response([['invoiceId' => 'inv-deja-recue']]),
        PLATEFORME.'/v1/invoice/status/notSeen' => Http::response([]),
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
    ]);

    $this->artisan('einvoicing:poll')->assertSuccessful();

    expect(WebhookEvent::query()->count())->toBe(1);
});

it('n\'interroge que le dossier demandé', function (): void {
    Queue::fake();
    tenantActif('111111111');
    tenantActif('222222222');

    Http::fake([
        PLATEFORME.'/v1/invoice/notSeen' => Http::response([]),
        PLATEFORME.'/v1/invoice/status/notSeen' => Http::response([]),
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
    ]);

    $this->artisan('einvoicing:poll', ['--tenant' => '222222222'])->assertSuccessful();

    // Deux appels pour un seul dossier : factures et statuts.
    Http::assertSentCount(3);
});

it('signale l\'absence de dossier à interroger', function (): void {
    $this->artisan('einvoicing:poll')->assertFailed();
});
