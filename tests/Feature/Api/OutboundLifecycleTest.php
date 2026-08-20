<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Contracts\StatusMapper;
use AmazScript\Einvoicing\Enums\OutboundStatus;
use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Facades\Einvoicing;
use AmazScript\Einvoicing\Jobs\ProcessStatusUpdate;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\OutboundInvoice;
use AmazScript\Einvoicing\Models\Status;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Models\WebhookEvent;
use Illuminate\Contracts\Events\Dispatcher;

function dossierEmetteur(): Tenant
{
    return Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => '1',
        'customer_id' => 'cust-1', 'siren' => '384066650', 'siret' => null, 'active' => true,
    ]);
}

function factureEmise(Tenant $dossier, string $providerId): OutboundInvoice
{
    return OutboundInvoice::query()->create([
        'tenant_id' => $dossier->id,
        'provider' => 'iopole',
        'provider_invoice_id' => $providerId,
        'file_hash' => hash('sha256', $providerId),
        'file_name' => 'facture.xml',
        'file_size' => 42,
        'status' => OutboundStatus::Sent,
        'sent_at' => now(),
    ]);
}

/** Livre un statut par le circuit réel du package : event puis job. */
function livrerStatut(string $providerInvoiceId, string $code, string $statusId): void
{
    $event = WebhookEvent::query()->create([
        'event_id' => 'evt-'.$statusId,
        'event_type' => 'INVOICE_STATUS',
        'status' => WebhookEventStatus::Received,
        'received_at' => now(),
        // Forme copiée d'un payload réel : statusId, date, status.code.
        'payload' => [
            'invoiceId' => $providerInvoiceId,
            'statusId' => $statusId,
            'date' => '2026-08-20T10:00:00.000Z',
            'destType' => 'OPERATOR',
            'status' => ['code' => $code],
        ],
    ]);

    (new ProcessStatusUpdate($event->id, 'iopole'))->handle(
        app(StatusMapper::class),
        app(Dispatcher::class),
    );
}

it('rattache un statut à la facture émise qu\'il concerne', function (): void {
    $dossier = dossierEmetteur();
    $emise = factureEmise($dossier, 'inv-emise-1');

    livrerStatut('inv-emise-1', 'SUBMITTED', 'st-1');

    $status = Status::query()->where('provider_status_id', 'st-1')->first();

    expect($status->outbound_invoice_id)->toBe($emise->id)
        ->and($status->invoice_id)->toBeNull()
        ->and($status->isOutbound())->toBeTrue();
});

it('ne confond pas une facture reçue avec une facture émise', function (): void {
    $dossier = dossierEmetteur();
    factureEmise($dossier, 'inv-emise-2');

    // Même circuit, mais l'identifiant désigne une facture reçue.
    $recue = InboundInvoice::query()->create([
        'tenant_id' => $dossier->id, 'provider' => 'iopole', 'provider_invoice_id' => 'inv-recue-1',
    ]);

    livrerStatut('inv-recue-1', 'RECEIVED', 'st-2');

    $status = Status::query()->where('provider_status_id', 'st-2')->first();

    expect($status->invoice_id)->toBe($recue->id)
        ->and($status->outbound_invoice_id)->toBeNull()
        ->and($status->isOutbound())->toBeFalse();
});

it('expose le cycle de vie d\'une facture émise', function (): void {
    $dossier = dossierEmetteur();
    $emise = factureEmise($dossier, 'inv-emise-3');

    livrerStatut('inv-emise-3', 'SUBMITTED', 'st-3');
    livrerStatut('inv-emise-3', 'RECEIVED', 'st-4');

    expect($emise->statuses()->count())->toBe(2)
        ->and($emise->lastStatus()->code)->toBe('RECEIVED')
        ->and($emise->deliveryFailed())->toBeFalse();
});

it('signale une facture émise que la plateforme n\'a pas pu livrer', function (): void {
    $dossier = dossierEmetteur();
    $emise = factureEmise($dossier, 'inv-emise-4');

    livrerStatut('inv-emise-4', 'REJECTED', 'st-5');

    expect($emise->deliveryFailed())->toBeTrue()
        ->and(Einvoicing::for($dossier)->sent()->rejected()->pluck('id')->all())->toBe([$emise->id]);
});

it('distingue ce qui est refusé, en attente, ou parti sans nouvelle', function (): void {
    $dossier = dossierEmetteur();

    $partie = factureEmise($dossier, 'inv-partie');
    livrerStatut('inv-partie', 'SUBMITTED', 'st-6');

    $muette = factureEmise($dossier, 'inv-muette');

    $refusee = factureEmise($dossier, 'inv-refusee');
    $refusee->forceFill(['status' => OutboundStatus::Failed, 'failure_reason' => 'Invalid profile'])->save();

    $envois = Einvoicing::for($dossier)->sent();

    // Sans nouvelle n'est pas refusée : le silence n'est pas un verdict.
    expect($envois->awaitingDelivery()->pluck('id')->all())->toBe([$muette->id])
        ->and($envois->failed()->pluck('id')->all())->toBe([$refusee->id])
        ->and($envois->get())->toHaveCount(3);
});

it('retrouve une facture émise par l\'identifiant de la plateforme', function (): void {
    $dossier = dossierEmetteur();
    $emise = factureEmise($dossier, 'inv-emise-7');

    expect(Einvoicing::for($dossier)->sent()->find('inv-emise-7')?->id)->toBe($emise->id)
        ->and(Einvoicing::for($dossier)->sent()->find($emise->id)?->id)->toBe($emise->id)
        ->and(Einvoicing::for($dossier)->sent()->find('inconnue'))->toBeNull();
});

it('ne laisse pas un dossier lire les envois d\'un autre', function (): void {
    $mien = dossierEmetteur();
    $autre = Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => '2',
        'customer_id' => 'cust-2', 'siren' => '999999999', 'siret' => null, 'active' => true,
    ]);

    factureEmise($autre, 'inv-autre');

    expect(Einvoicing::for($mien)->sent()->get())->toHaveCount(0)
        ->and(Einvoicing::for($mien)->sent()->find('inv-autre'))->toBeNull();
});

it('tient un document refusé pour une non-livraison, pas seulement un rejet de routage', function (): void {
    $dossier = dossierEmetteur();
    $emise = factureEmise($dossier, 'inv-emise-8');

    // Relevé en réel : un fichier qui n'est pas un Factur-X valide revient en
    // UNACCEPTABLE / UNKNOWN_INVOICE_FLAVOR, jamais en REJECTED.
    livrerStatut('inv-emise-8', 'UNACCEPTABLE', 'st-9');

    expect($emise->deliveryFailed())->toBeTrue()
        ->and(Einvoicing::for($dossier)->sent()->rejected()->pluck('id')->all())->toBe([$emise->id]);
});
