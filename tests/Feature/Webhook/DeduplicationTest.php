<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

const DEDUP_SECRET = 'ac4f8b1e9d2c7a6b5e3f0d8c1a9b7e6d4c2f1a0b9e8d7c6b5a4f3e2d1c0b9a88';

beforeEach(function (): void {
    config()->set('einvoicing.webhook.secret', DEDUP_SECRET);

    // Ce fichier éprouve l'encaissement, pas le traitement : la file est
    // simulée pour que l'état observé soit bien celui laissé par le contrôleur.
    Queue::fake();
});

/**
 * Envoie une livraison signée comme le fait la plateforme : horodatage en
 * millisecondes, clé d'idempotence en en-tête.
 *
 * @param  array<string, mixed>  $payload
 */
function livrer(array $payload, ?string $cle = null, ?string $siren = null): TestResponse
{
    $corps = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = (string) (time() * 1000);
    $checksum = hash('sha256', $corps);
    $canonique = $timestamp."\nPOST\n/einvoicing/webhook\n".$checksum;

    $entetes = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TIMESTAMP' => $timestamp,
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', $canonique, DEDUP_SECRET),
    ];

    if ($cle !== null) {
        $entetes['HTTP_X_IDEMPOTENCY_KEY'] = $cle;
    }

    if ($siren !== null) {
        $entetes['HTTP_X_TARGET_ELECTRONIC_ADDRESS'] = '0002:'.$siren;
    }

    return test()->call('POST', '/einvoicing/webhook', [], [], [], $entetes, $corps);
}

function statutType(string $statusId = 'sta-1', string $invoiceId = 'inv-1'): array
{
    return [
        'invoiceId' => $invoiceId,
        'statusId' => $statusId,
        'date' => '2026-08-18T17:36:01.136Z',
        'destType' => 'OPERATOR',
        'status' => ['code' => 'RECEIVED'],
        'json' => ['recipients' => [['siren' => '111111111', 'name' => 'DESTINATAIRE']]],
    ];
}

it('enregistre une livraison authentique', function (): void {
    livrer(statutType(), cle: 'idem-1');

    expect(WebhookEvent::count())->toBe(1);

    $event = WebhookEvent::first();
    expect($event->event_id)->toBe('idem-1')
        ->and($event->payload)->toHaveKey('statusId')
        ->and($event->received_at)->not->toBeNull();
});

it('ne traite qu\'une fois une livraison répétée', function (): void {
    $premiere = livrer(statutType(), cle: 'idem-2');
    $seconde = livrer(statutType(), cle: 'idem-2');

    // Un rejeu est un succès : la plateforme ne doit pas le relancer.
    expect($premiere->status())->toBeLessThan(300)
        ->and($seconde->status())->toBeLessThan(300)
        ->and(WebhookEvent::where('event_id', 'idem-2')->count())->toBe(1);
});

it('distingue deux livraisons différentes', function (): void {
    livrer(statutType('sta-1'), cle: 'idem-3');
    livrer(statutType('sta-2'), cle: 'idem-4');

    expect(WebhookEvent::count())->toBe(2);
});

it('retombe sur l\'identifiant du statut quand l\'en-tête d\'idempotence manque', function (): void {
    livrer(statutType('sta-99'));
    livrer(statutType('sta-99'));

    // Sans en-tête, l'identifiant du statut identifie la livraison de façon stable.
    expect(WebhookEvent::count())->toBe(1)
        ->and(WebhookEvent::first()->event_id)->toContain('sta-99');
});

it('produit une clé stable même sans aucun identifiant', function (): void {
    $payload = ['quelque' => 'chose', 'sans' => 'identifiant'];

    livrer($payload);
    livrer($payload);

    // Deux livraisons au contenu identique ne doivent pas créer deux lignes.
    expect(WebhookEvent::count())->toBe(1);
});

it('route la livraison vers le tenant destinataire', function (): void {
    $tenant = Tenant::create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => '1',
        'customer_id' => 'cust-1', 'siren' => '111111111', 'siret' => null, 'active' => true,
    ]);

    livrer(statutType(), cle: 'idem-5', siren: '111111111');

    $event = WebhookEvent::first();
    expect($event->tenant_id)->toBe($tenant->id)
        ->and($event->status)->toBe(WebhookEventStatus::Received);
});

it('conserve une livraison dont le tenant est introuvable', function (): void {
    livrer(statutType(), cle: 'idem-6', siren: '999999999');

    $event = WebhookEvent::first();

    // Rien n'est perdu : l'événement reste rejouable une fois le tenant créé.
    expect($event)->not->toBeNull()
        ->and($event->tenant_id)->toBeNull()
        ->and($event->status)->toBe(WebhookEventStatus::Unrouted)
        ->and($event->payload)->not->toBeEmpty();
});

it('répond 2xx même lorsque le routage échoue', function (): void {
    $reponse = livrer(statutType(), cle: 'idem-7', siren: '999999999');

    expect($reponse->status())->toBeLessThan(300);
});

it('n\'enregistre rien quand la signature est invalide', function (): void {
    test()->call('POST', '/einvoicing/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TIMESTAMP' => (string) (time() * 1000),
        'HTTP_X_SIGNATURE' => str_repeat('0', 64),
        'HTTP_X_IDEMPOTENCY_KEY' => 'idem-8',
    ], '{"statusId":"sta-8"}');

    expect(WebhookEvent::count())->toBe(0);
});

it('encaisse un payload illisible sans le perdre', function (): void {
    $corps = 'ceci n est pas du json';
    $timestamp = (string) (time() * 1000);
    $checksum = hash('sha256', $corps);
    $canonique = $timestamp."\nPOST\n/einvoicing/webhook\n".$checksum;

    $reponse = test()->call('POST', '/einvoicing/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TIMESTAMP' => $timestamp,
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', $canonique, DEDUP_SECRET),
        'HTTP_X_IDEMPOTENCY_KEY' => 'idem-9',
    ], $corps);

    expect($reponse->status())->toBeLessThan(300)
        ->and(WebhookEvent::count())->toBe(1);
});

it('supporte deux livraisons simultanées de la même clé', function (): void {
    // Simule la course : la contrainte d'unicité de la base est le seul arbitre fiable.
    $resultats = [];
    for ($i = 0; $i < 5; $i++) {
        $resultats[] = livrer(statutType(), cle: 'idem-course')->status();
    }

    expect(WebhookEvent::where('event_id', 'idem-course')->count())->toBe(1)
        ->and(array_filter($resultats, fn (int $s): bool => $s >= 300))->toBeEmpty();
});
