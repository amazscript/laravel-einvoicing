<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;

const REROUTE_API = 'https://api.example.test';

/**
 * Le payload d'une facture entrante ne porte **que** son identifiant : le
 * destinataire arrive dans un entête HTTP, que le rejeu n'a plus. Relevé en
 * réel — une facture émise puis reçue est restée UNROUTED sans moyen de la
 * récupérer.
 */
function evenementEntrantOrphelin(string $invoiceId = 'inv-distante'): WebhookEvent
{
    return WebhookEvent::query()->create([
        'event_id' => 'evt-'.$invoiceId,
        'event_type' => 'INVOICE_INBOUND',
        'status' => WebhookEventStatus::Unrouted,
        'received_at' => now(),
        'payload' => ['invoiceId' => $invoiceId, 'senderAcceptStatus' => 'ACCEPTED'],
    ]);
}

function dossierReroute(string $siren): Tenant
{
    return Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => $siren,
        'customer_id' => 'cust-1', 'siren' => $siren, 'siret' => null, 'active' => true,
    ]);
}

function fakeFacture(?string $siren, ?string $siret = null): void
{
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        REROUTE_API.'/v1/invoice/*' => Http::response([[
            'invoiceId' => 'inv-distante',
            'businessData' => [
                'invoiceId' => 'FA-2026-001',
                'seller' => ['name' => 'FOURNISSEUR', 'siren' => '111111111'],
                'buyer' => array_filter(['name' => 'MOI', 'siren' => $siren, 'siret' => $siret]),
            ],
        ]]),
    ]);
}

beforeEach(function (): void {
    // Plusieurs dossiers actifs, sinon la stratégie du « dossier unique par
    // défaut » route tout et le test ne prouve rien.
    dossierReroute('111111111');
    dossierReroute('222222222');

    config()->set('einvoicing.drivers.iopole.base_url', REROUTE_API);
    config()->set('einvoicing.drivers.iopole.token_url', REROUTE_API.'/token');
    config()->set('einvoicing.drivers.iopole.client_id', 'client');
    config()->set('einvoicing.drivers.iopole.client_secret', 'secret');
});

it('récupère une facture entrante en demandant son destinataire à la plateforme', function (): void {
    $dossier = dossierReroute('948779160');
    $event = evenementEntrantOrphelin();
    fakeFacture('948779160');

    $this->artisan('einvoicing:events:retry')->run();

    expect($event->fresh()->tenant_id)->toBe($dossier->id)
        ->and($event->fresh()->status)->not->toBe(WebhookEventStatus::Unrouted);
});

it('route par le SIRET quand la facture en porte un', function (): void {
    $dossier = Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => '1',
        'customer_id' => 'cust-1', 'siren' => '948779160', 'siret' => '94877916000012', 'active' => true,
    ]);
    $event = evenementEntrantOrphelin();
    fakeFacture(null, '94877916000012');

    $this->artisan('einvoicing:events:retry')->run();

    expect($event->fresh()->tenant_id)->toBe($dossier->id);
});

it('laisse l\'événement en souffrance si le destinataire reste inconnu', function (): void {
    $event = evenementEntrantOrphelin();
    fakeFacture('999999999');   // un destinataire qui n'est pas dans le parc

    $this->artisan('einvoicing:events:retry')->run();

    // Mal router est pire que ne pas router : rien n'est perdu, rien n'est inventé.
    expect($event->fresh()->status)->toBe(WebhookEventStatus::Unrouted)
        ->and($event->fresh()->tenant_id)->toBeNull();
});

it('n\'interroge pas la plateforme quand le payload suffit', function (): void {
    $dossier = dossierReroute('383421815');

    $event = WebhookEvent::query()->create([
        'event_id' => 'evt-complet',
        'event_type' => 'INVOICE_STATUS',
        'status' => WebhookEventStatus::Unrouted,
        'received_at' => now(),
        'payload' => ['invoiceId' => 'inv-x', 'json' => ['recipients' => [['siren' => '383421815']]]],
    ]);

    Http::fake(['*' => Http::response([], 500)]);

    $this->artisan('einvoicing:events:retry')->run();

    // Un appel réseau inutile sur chaque événement rejoué coûterait cher.
    expect($event->fresh()->tenant_id)->toBe($dossier->id);
    Http::assertNothingSent();
});

it('survit à une plateforme injoignable', function (): void {
    $event = evenementEntrantOrphelin();

    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        REROUTE_API.'/v1/invoice/*' => Http::response(['statusMessage' => 'boom'], 500),
    ]);

    $this->artisan('einvoicing:events:retry')->run();

    expect($event->fresh()->status)->toBe(WebhookEventStatus::Unrouted);
});
