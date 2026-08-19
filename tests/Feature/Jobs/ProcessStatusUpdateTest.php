<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Contracts\StatusMapper;
use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Events\InvoiceStatusUpdated;
use AmazScript\Einvoicing\Events\OutboundInvoiceNotDelivered;
use AmazScript\Einvoicing\Jobs\ProcessStatusUpdate;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\Status;
use AmazScript\Einvoicing\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;

/**
 * Le payload utilisé ici provient d'une livraison réellement émise par la
 * plateforme : c'est un test de contrat, pas une invention.
 */
function payloadStatutReel(): array
{
    $vecteur = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/iopole-live-status-webhook.json'), true);

    return json_decode($vecteur['body'], true);
}

function evenementRecu(?array $payload = null, string $type = 'INVOICE_STATUS'): WebhookEvent
{
    return WebhookEvent::query()->create([
        'event_id' => 'evt-'.bin2hex(random_bytes(4)),
        'event_type' => $type,
        'status' => WebhookEventStatus::Received,
        'payload' => $payload ?? payloadStatutReel(),
        'received_at' => now(),
    ]);
}

it('consigne un statut réellement émis par la plateforme', function (): void {
    $event = evenementRecu();

    (new ProcessStatusUpdate($event->id))->handle(app(StatusMapper::class), app('events'));

    $status = Status::query()->first();
    $attendu = payloadStatutReel();

    expect(Status::query()->count())->toBe(1)
        ->and($status->provider_status_id)->toBe($attendu['statusId'])
        ->and($status->code)->toBe('REJECTED')
        ->and($status->dest_type)->toBe('OPERATOR')
        ->and($status->occurred_at)->not->toBeNull()
        ->and($status->payload)->toHaveKey('xml');
});

it('reprend la raison du rejet comme description', function (): void {
    (new ProcessStatusUpdate(evenementRecu()->id))->handle(app(StatusMapper::class), app('events'));

    expect(Status::query()->first()->description)->toContain('No route found');
});

it('accepte un statut sans value ni description', function (): void {
    // Constaté en réel : la documentation montre code/value/desc, la plateforme
    // n'envoie parfois que le code.
    $event = evenementRecu([
        'statusId' => 'sta-minimal', 'invoiceId' => 'inv-1',
        'status' => ['code' => 'RECEIVED'], 'date' => '2026-08-18T17:36:01.136Z',
    ]);

    (new ProcessStatusUpdate($event->id))->handle(app(StatusMapper::class), app('events'));

    $status = Status::query()->first();
    expect($status->code)->toBe('RECEIVED')
        ->and($status->value)->toBeNull()
        ->and($status->description)->toBeNull();
});

it('rattache le statut à la facture quand elle est connue', function (): void {
    $facture = InboundInvoice::query()->create([
        'provider' => 'iopole',
        'provider_invoice_id' => payloadStatutReel()['invoiceId'],
    ]);

    (new ProcessStatusUpdate(evenementRecu()->id))->handle(app(StatusMapper::class), app('events'));

    expect(Status::query()->first()->invoice_id)->toBe($facture->id);
});

it('conserve un statut portant sur une facture inconnue', function (): void {
    (new ProcessStatusUpdate(evenementRecu()->id))->handle(app(StatusMapper::class), app('events'));

    // Un statut peut précéder sa facture : il ne doit pas être perdu pour autant.
    expect(Status::query()->count())->toBe(1)
        ->and(Status::query()->first()->invoice_id)->toBeNull();
});

it('ne crée pas de doublon lorsqu\'il est rejoué', function (): void {
    $event = evenementRecu();
    $mapper = app(StatusMapper::class);

    (new ProcessStatusUpdate($event->id))->handle($mapper, app('events'));
    (new ProcessStatusUpdate($event->id))->handle($mapper, app('events'));
    (new ProcessStatusUpdate($event->id))->handle($mapper, app('events'));

    expect(Status::query()->count())->toBe(1);
});

it('marque l\'événement comme traité', function (): void {
    $event = evenementRecu();

    (new ProcessStatusUpdate($event->id))->handle(app(StatusMapper::class), app('events'));

    $event->refresh();
    expect($event->status)->toBe(WebhookEventStatus::Processed)
        ->and($event->processed_at)->not->toBeNull();
});

it('émet un événement applicatif', function (): void {
    Event::fake([InvoiceStatusUpdated::class]);
    $event = evenementRecu();

    (new ProcessStatusUpdate($event->id))->handle(app(StatusMapper::class), app('events'));

    Event::assertDispatched(InvoiceStatusUpdated::class, function (InvoiceStatusUpdated $e): bool {
        return $e->status->code === 'REJECTED';
    });
});

it('signale un payload inexploitable sans perdre l\'événement', function (): void {
    $event = evenementRecu(['rien' => 'de reconnaissable']);

    (new ProcessStatusUpdate($event->id))->handle(app(StatusMapper::class), app('events'));

    $event->refresh();
    expect(Status::query()->count())->toBe(0)
        ->and($event->status)->toBe(WebhookEventStatus::Failed)
        ->and($event->failed_reason)->not->toBeNull()
        ->and($event->payload)->not->toBeEmpty();
});

it('ne divulgue pas le payload dans la raison de l\'échec', function (): void {
    $event = evenementRecu(['siret' => '12345678900011', 'montant' => 1234.56]);

    (new ProcessStatusUpdate($event->id))->handle(app(StatusMapper::class), app('events'));

    expect($event->refresh()->failed_reason)->not->toContain('12345678900011');
});

it('reste sans effet si l\'événement a disparu', function (): void {
    (new ProcessStatusUpdate('00000000-0000-0000-0000-000000000000'))
        ->handle(app(StatusMapper::class), app('events'));

    expect(Status::query()->count())->toBe(0);
});

it('recule de plus en plus entre deux tentatives', function (): void {
    $job = new ProcessStatusUpdate('peu-importe');

    expect($job->backoff())->toBe([10, 60, 300, 900])
        ->and($job->tries)->toBe(5);
});

it('lit le code réseau sous networkCode', function (): void {
    // Forme réellement observée : { code: RECEIVED, networkCode: "202" }.
    $event = evenementRecu([
        'statusId' => 'sta-network', 'invoiceId' => 'inv-1',
        'status' => ['code' => 'RECEIVED', 'networkCode' => '202'],
        'date' => '2026-08-19T07:54:46.000Z',
    ]);

    (new ProcessStatusUpdate($event->id))->handle(app(StatusMapper::class), app('events'));

    expect(Status::query()->first()->value)->toBe('202');
});

it('signale une facture restée en chemin', function (): void {
    Event::fake([OutboundInvoiceNotDelivered::class]);

    // Cas réel : destinataire absent de l'annuaire.
    (new ProcessStatusUpdate(evenementRecu()->id))->handle(app(StatusMapper::class), app('events'));

    Event::assertDispatched(
        OutboundInvoiceNotDelivered::class,
        fn (OutboundInvoiceNotDelivered $e): bool => $e->reason === 'ROUTING_FAILURE'
            && str_contains((string) $e->message, 'No route found'),
    );
});

it('ne crie pas à l\'échec de remise sur un statut ordinaire', function (): void {
    Event::fake([OutboundInvoiceNotDelivered::class]);

    $event = evenementRecu([
        'statusId' => 'sta-ok', 'invoiceId' => 'inv-1',
        'status' => ['code' => 'RECEIVED', 'networkCode' => '202'],
    ]);
    (new ProcessStatusUpdate($event->id))->handle(app(StatusMapper::class), app('events'));

    Event::assertNothingDispatched();
});
