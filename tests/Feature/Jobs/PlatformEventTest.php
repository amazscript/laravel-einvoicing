<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Events\InboundInvoiceInvalid;
use AmazScript\Einvoicing\Jobs\ProcessPlatformEvent;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Format générique d'événement, tel que la documentation le décrit :
 * un identifiant, un type, un horodatage, une charge utile.
 */
function evenementInvalide(array $remplacements = []): WebhookEvent
{
    return WebhookEvent::query()->create([
        'event_id' => 'evt-'.bin2hex(random_bytes(4)),
        'event_type' => 'INVOICE_INBOUND_INVALID',
        'status' => WebhookEventStatus::Received,
        'payload' => array_merge([
            'eventId' => '550e8400-e29b-41d4-a716-446655440000',
            'eventType' => 'INVOICE_INBOUND_INVALID',
            'timestamp' => '2026-02-09T10:00:00.000Z',
            'payload' => [
                'invoiceId' => 'abc123',
                'documentId' => 'doc456',
                'invoiceNumber' => 'INV-2026-001',
                'invoiceDate' => '2026-02-09',
                'validationErrors' => [
                    ['code' => 'VAL001', 'message' => 'Invalid XML structure'],
                ],
            ],
        ], $remplacements),
        'received_at' => now(),
    ]);
}

it('prévient l\'application qu\'une facture a été refusée', function (): void {
    Event::fake([InboundInvoiceInvalid::class]);

    (new ProcessPlatformEvent(evenementInvalide()->id))->handle(app('events'));

    Event::assertDispatched(InboundInvoiceInvalid::class, function (InboundInvoiceInvalid $e): bool {
        return $e->providerInvoiceId === 'abc123'
            && $e->invoiceNumber === 'INV-2026-001'
            && $e->validationErrors === [['code' => 'VAL001', 'message' => 'Invalid XML structure']];
    });
});

it('marque l\'événement comme traité', function (): void {
    $event = evenementInvalide();

    (new ProcessPlatformEvent($event->id))->handle(app('events'));

    expect($event->refresh()->status)->toBe(WebhookEventStatus::Processed);
});

it('supporte un refus sans détail de validation', function (): void {
    Event::fake([InboundInvoiceInvalid::class]);

    $event = evenementInvalide(['payload' => ['invoiceId' => 'abc123']]);
    (new ProcessPlatformEvent($event->id))->handle(app('events'));

    Event::assertDispatched(InboundInvoiceInvalid::class, function (InboundInvoiceInvalid $e): bool {
        return $e->validationErrors === [] && $e->invoiceNumber === null;
    });
});

it('reste sans effet si l\'événement a disparu', function (): void {
    Event::fake([InboundInvoiceInvalid::class]);

    (new ProcessPlatformEvent('00000000-0000-0000-0000-000000000000'))->handle(app('events'));

    Event::assertNothingDispatched();
});

it('achemine un refus depuis la route jusqu\'au job', function (): void {
    config()->set('einvoicing.webhook.secret', str_repeat('a', 64));
    Queue::fake();

    // Un refus concerne un dossier précis : sans destinataire résolu, l'événement
    // reste en UNROUTED et n'est volontairement pas traité.
    Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => '1',
        'customer_id' => 'cust-1', 'siren' => '111111111', 'siret' => null, 'active' => true,
    ]);

    $corps = json_encode(['eventId' => 'evt-1', 'eventType' => 'INVOICE_INBOUND_INVALID', 'payload' => []], JSON_THROW_ON_ERROR);
    $timestamp = (string) (time() * 1000);
    $checksum = hash('sha256', $corps);
    $signature = hash_hmac('sha256', $timestamp."\nPOST\n/einvoicing/webhook\n".$checksum, str_repeat('a', 64));

    $reponse = test()->call('POST', '/einvoicing/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TIMESTAMP' => $timestamp,
        'HTTP_X_SIGNATURE' => $signature,
        'HTTP_X_IDEMPOTENCY_KEY' => 'idem-invalid',
        'HTTP_X_TARGET_ELECTRONIC_ADDRESS' => '0225:111111111',
    ], $corps);

    expect($reponse->status())->toBe(202)
        ->and(WebhookEvent::query()->first()->event_type)->toBe('INVOICE_INBOUND_INVALID');

    Queue::assertPushed(ProcessPlatformEvent::class);
});
