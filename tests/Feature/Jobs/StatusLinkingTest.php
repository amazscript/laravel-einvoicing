<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use AmazScript\Einvoicing\Contracts\StatusMapper;
use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Jobs\ProcessInboundInvoice;
use AmazScript\Einvoicing\Jobs\ProcessStatusUpdate;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\Status;
use AmazScript\Einvoicing\Models\WebhookEvent;
use AmazScript\Einvoicing\Storage\InvoiceFileStore;
use Illuminate\Support\Facades\Http;

/**
 * Un même document porte un identifiant différent de chaque côté de la chaîne :
 * le statut désigne la facture émise, la livraison désigne la facture reçue.
 * Le rapprochement passe donc par le numéro attribué par l'émetteur, qualifié
 * par son SIREN — deux fournisseurs pouvant numéroter pareil.
 */
function statutSurFactureEmise(string $numero = 'IOPOLE-D1tzMVtAPJZ', string $siren = '948779160'): array
{
    return [
        'invoiceId' => 'identifiant-cote-emission',
        'statusId' => 'sta-'.bin2hex(random_bytes(3)),
        'date' => '2026-08-19T07:54:46.000Z',
        'destType' => 'OPERATOR',
        'status' => ['code' => 'RECEIVED', 'networkCode' => '202'],
        'json' => [
            'responses' => [[
                'documentStatus' => ['code' => 'RECEIVED'],
                'documentReference' => [
                    'issuerAssignedId' => $numero,
                    'typeCode' => 380,
                    'issuer' => ['name' => 'UNIBAT34', 'siren' => $siren],
                ],
            ]],
        ],
    ];
}

function factureRecue(string $numero = 'IOPOLE-D1tzMVtAPJZ', string $siren = '948779160'): InboundInvoice
{
    return InboundInvoice::query()->create([
        'provider' => 'iopole',
        'provider_invoice_id' => 'identifiant-cote-reception',
        'invoice_number' => $numero,
        'sender_siren' => $siren,
    ]);
}

function traiterStatut(array $payload): void
{
    $event = WebhookEvent::query()->create([
        'event_id' => 'evt-'.bin2hex(random_bytes(4)),
        'event_type' => 'INVOICE_STATUS',
        'status' => WebhookEventStatus::Received,
        'payload' => $payload,
        'received_at' => now(),
    ]);

    (new ProcessStatusUpdate($event->id))->handle(app(StatusMapper::class), app('events'));
}

it('rattache un statut à la facture reçue malgré des identifiants différents', function (): void {
    $facture = factureRecue();

    traiterStatut(statutSurFactureEmise());

    expect(Status::query()->first()->invoice_id)->toBe($facture->id);
});

it('ne rattache pas au même numéro venu d\'un autre émetteur', function (): void {
    factureRecue(numero: 'F-2026-001', siren: '111111111');

    // Deux fournisseurs peuvent numéroter identiquement : le SIREN tranche.
    traiterStatut(statutSurFactureEmise(numero: 'F-2026-001', siren: '222222222'));

    expect(Status::query()->first()->invoice_id)->toBeNull();
});

it('ne rattache rien quand le numéro de l\'émetteur est absent', function (): void {
    factureRecue();

    $payload = statutSurFactureEmise();
    unset($payload['json']['responses'][0]['documentReference']['issuerAssignedId']);

    traiterStatut($payload);

    expect(Status::query()->first()->invoice_id)->toBeNull();
});

it('privilégie l\'identifiant direct quand il correspond', function (): void {
    $facture = InboundInvoice::query()->create([
        'provider' => 'iopole',
        'provider_invoice_id' => 'identifiant-cote-emission',
        'invoice_number' => 'AUTRE-NUMERO',
        'sender_siren' => '999999999',
    ]);

    traiterStatut(statutSurFactureEmise());

    expect(Status::query()->first()->invoice_id)->toBe($facture->id);
});

it('rattache les statuts déjà reçus quand la facture arrive ensuite', function (): void {
    // Cas réel : les statuts de cycle de vie précèdent la facture.
    traiterStatut(statutSurFactureEmise());
    traiterStatut(statutSurFactureEmise());

    expect(Status::query()->whereNotNull('invoice_id')->count())->toBe(0);

    // Sans identifiants, l'obtention du jeton échoue et les métadonnées restent
    // nulles : la facture n'aurait alors aucun numéro à rapprocher.
    config()->set('einvoicing.drivers.iopole.base_url', 'https://api.example.test');
    config()->set('einvoicing.drivers.iopole.token_url', 'https://api.example.test/token');
    config()->set('einvoicing.drivers.iopole.client_id', 'client');
    config()->set('einvoicing.drivers.iopole.client_secret', 'secret');

    Http::fake([
        '*/v1/invoice/*/files' => Http::response([]),
        '*/v1/invoice/*' => Http::response([[
            'invoiceId' => 'identifiant-cote-reception',
            'originalFormat' => 'FacturX',
            'businessData' => [
                'invoiceId' => 'IOPOLE-D1tzMVtAPJZ',
                'seller' => ['name' => 'UNIBAT34', 'siren' => '948779160'],
            ],
        ]]),
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
    ]);

    $event = WebhookEvent::query()->create([
        'event_id' => 'evt-facture', 'event_type' => 'INVOICE_INBOUND',
        'status' => WebhookEventStatus::Received,
        'payload' => ['invoiceId' => 'identifiant-cote-reception'],
        'received_at' => now(),
    ]);

    app(ProcessInboundInvoice::class, ['webhookEventId' => $event->id])->handle(
        app('events'),
        app(InvoiceGateway::class),
        app(InvoiceFileStore::class),
    );

    expect(Status::query()->whereNotNull('invoice_id')->count())->toBe(2);
});
