<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Jobs\ProcessStatusUpdate;
use AmazScript\Einvoicing\Models\Status;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * Chaîne complète : une livraison réelle entre par la route, ressort en statut
 * consultable. C'est le parcours que vit le consommateur du package.
 */
function livraisonReelle(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/iopole-live-status-webhook.json'), true);
}

function envoyerLivraisonReelle(): TestResponse
{
    $v = livraisonReelle();

    // Le vecteur est figé dans le temps : on resigne à l'instant présent pour
    // que la fenêtre anti-rejeu ne le refuse pas.
    $timestamp = (string) (time() * 1000);
    $checksum = hash('sha256', $v['body']);
    $canonique = $timestamp."\nPOST\n/einvoicing/webhook\n".$checksum;

    return test()->call('POST', '/einvoicing/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TIMESTAMP' => $timestamp,
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', $canonique, $v['secret']),
        'HTTP_X_CHECKSUM' => $checksum,
        'HTTP_X_IDEMPOTENCY_KEY' => $v['headers']['x-idempotency-key'],
        'HTTP_X_TARGET_ELECTRONIC_ADDRESS' => $v['headers']['x-target-electronic-address'],
    ], $v['body']);
}

beforeEach(function (): void {
    config()->set('einvoicing.webhook.secret', livraisonReelle()['secret']);

    // Le destinataire de la livraison doit exister, sinon l'événement reste en
    // UNROUTED et n'est volontairement pas traité.
    Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company',
        'tenantable_id' => '1',
        'customer_id' => 'cust-1',
        'siren' => '111111111',
        'siret' => null,
        'active' => true,
    ]);
});

it('met le traitement en file plutôt que de le faire dans la requête', function (): void {
    Queue::fake();

    $reponse = envoyerLivraisonReelle();

    expect($reponse->status())->toBe(202);
    Queue::assertPushed(ProcessStatusUpdate::class);
});

it('ne met rien en file lorsqu\'une livraison est répétée', function (): void {
    Queue::fake();

    envoyerLivraisonReelle();
    envoyerLivraisonReelle();
    envoyerLivraisonReelle();

    // Trois livraisons, un seul traitement : c'est tout l'objet de la déduplication.
    Queue::assertPushed(ProcessStatusUpdate::class, 1);
    expect(WebhookEvent::query()->count())->toBe(1);
});

it('transforme une livraison réelle en statut consultable', function (): void {
    envoyerLivraisonReelle();

    // La file synchrone du test exécute le job dans la foulée.
    $status = Status::query()->first();

    expect($status)->not->toBeNull()
        ->and($status->code)->toBe('REJECTED')
        ->and($status->provider)->toBe('iopole')
        ->and($status->description)->toContain('No route found');
});

it('respecte la file d\'attente configurée', function (): void {
    Queue::fake();
    config()->set('einvoicing.queue.name', 'einvoicing-prioritaire');

    envoyerLivraisonReelle();

    Queue::assertPushed(function (ProcessStatusUpdate $job): bool {
        return $job->queue === 'einvoicing-prioritaire';
    });
});

it('ne traite pas une livraison dont le destinataire est inconnu', function (): void {
    Queue::fake();
    Tenant::query()->delete();

    $reponse = envoyerLivraisonReelle();

    // Encaissée mais pas traitée : on ignore à qui elle appartient. Elle reste
    // rejouable une fois le tenant créé.
    expect($reponse->status())->toBe(202)
        ->and(WebhookEvent::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->first()->status)->toBe(WebhookEventStatus::Unrouted);

    Queue::assertNothingPushed();
});
