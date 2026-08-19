<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use AmazScript\Einvoicing\Enums\InvoiceFileKind;
use AmazScript\Einvoicing\Enums\InvoiceFormat;
use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Jobs\ProcessInboundInvoice;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\InvoiceFile;
use AmazScript\Einvoicing\Models\WebhookEvent;
use AmazScript\Einvoicing\Storage\InvoiceFileStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const API = 'https://api.example.test';
const INVOICE_ID = '01a01903-f969-7795-b349-fea674bbf0b6';

/**
 * Réponses calquées sur la spécification publiée : les métadonnées comptables
 * vivent sous businessData, les montants sont des objets { amount, currency }.
 */
function reponseFacture(): array
{
    return [
        'invoiceId' => INVOICE_ID,
        'originalFormat' => 'FACTURX',
        'originalFlavor' => 'EN16931',
        'way' => 'RECEIVED',
        'businessData' => [
            'invoiceId' => 'F-2026-0042',
            'invoiceDate' => '2026-08-19',
            'monetary' => [
                'invoiceCurrency' => 'EUR',
                'invoiceAmount' => ['amount' => 1000.00],
                'taxTotalAmount' => ['amount' => 200.00, 'currency' => 'EUR'],
                'payableAmount' => ['amount' => 1200.00],
            ],
            'seller' => ['name' => 'UNIBAT34', 'siren' => '948779160', 'siret' => null],
        ],
    ];
}

function reponseFichiers(): array
{
    return [
        ['fileId' => 'f-xml', 'type' => 'ORIGINAL', 'mimeType' => 'application/xml',
            'originalFilename' => 'facture.xml', 'sizeBytes' => 42, 'checksum' => 'abc'],
        ['fileId' => 'f-pdf', 'type' => 'READABLE', 'mimeType' => 'application/pdf',
            'originalFilename' => 'facture.pdf', 'sizeBytes' => 99, 'checksum' => 'def'],
        ['fileId' => 'f-piece', 'type' => 'ATTACHMENT', 'mimeType' => 'image/png',
            'originalFilename' => 'bon-de-livraison.png', 'sizeBytes' => 12, 'checksum' => 'ghi'],
    ];
}

function fakeApiFacture(): void
{
    Http::fake([
        API.'/v1/invoice/'.INVOICE_ID.'/files' => Http::response(reponseFichiers()),
        API.'/v1/invoice/file/f-xml/download' => Http::response('<Invoice/>'),
        API.'/v1/invoice/file/f-pdf/download' => Http::response('%PDF-1.4'),
        API.'/v1/invoice/file/f-piece/download' => Http::response('PNG'),
        API.'/v1/invoice/'.INVOICE_ID => Http::response(reponseFacture()),
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
    ]);
}

function traiterFactureRecue(): InboundInvoice
{
    $event = WebhookEvent::query()->create([
        'event_id' => 'evt-'.bin2hex(random_bytes(4)),
        'event_type' => 'INVOICE_INBOUND',
        'status' => WebhookEventStatus::Received,
        'payload' => ['invoiceId' => INVOICE_ID, 'senderAcceptStatus' => 'true'],
        'received_at' => now(),
    ]);

    app(ProcessInboundInvoice::class, ['webhookEventId' => $event->id])
        ->handle(app('events'), app(InvoiceGateway::class), app(InvoiceFileStore::class));

    return InboundInvoice::query()->firstOrFail();
}

beforeEach(function (): void {
    config()->set('einvoicing.drivers.iopole.base_url', API);
    config()->set('einvoicing.drivers.iopole.token_url', API.'/token');
    config()->set('einvoicing.drivers.iopole.client_id', 'client');
    config()->set('einvoicing.drivers.iopole.client_secret', 'secret');
    config()->set('einvoicing.storage.disk', 'einvoicing-test');
    Storage::fake('einvoicing-test');
});

it('complète la facture avec les métadonnées comptables', function (): void {
    fakeApiFacture();

    $facture = traiterFactureRecue();

    expect($facture->invoice_number)->toBe('F-2026-0042')
        ->and($facture->invoice_date?->format('Y-m-d'))->toBe('2026-08-19')
        ->and($facture->amount_total)->toBe('1200.00')
        ->and($facture->amount_tax)->toBe('200.00')
        ->and($facture->currency)->toBe('EUR')
        ->and($facture->sender_name)->toBe('UNIBAT34')
        ->and($facture->sender_siren)->toBe('948779160')
        ->and($facture->format)->toBe(InvoiceFormat::Facturx);
});

it('range chaque fichier selon sa nature', function (): void {
    fakeApiFacture();

    $facture = traiterFactureRecue();

    $natures = InvoiceFile::query()->pluck('kind')->map(fn ($k) => $k->value)->sort()->values()->all();

    expect(InvoiceFile::query()->count())->toBe(3)
        ->and($natures)->toBe(['ATTACHMENT', 'READABLE_PDF', 'XML'])
        ->and($facture->files)->toHaveCount(3);
});

it('écrit les fichiers sur le disque configuré et nulle part ailleurs', function (): void {
    fakeApiFacture();

    traiterFactureRecue();

    foreach (InvoiceFile::query()->get() as $fichier) {
        expect($fichier->disk)->toBe('einvoicing-test');
        Storage::disk('einvoicing-test')->assertExists($fichier->path);
    }
});

it('empreinte chaque fichier', function (): void {
    fakeApiFacture();

    traiterFactureRecue();

    $xml = InvoiceFile::query()->where('kind', InvoiceFileKind::Xml)->firstOrFail();

    expect($xml->checksum)->toBe(hash('sha256', '<Invoice/>'))
        ->and($xml->path)->toEndWith('.xml');
});

it('ne retélécharge pas ce qui est déjà stocké', function (): void {
    fakeApiFacture();

    traiterFactureRecue();
    traiterFactureRecue();
    traiterFactureRecue();

    // Trois passages, trois fichiers : le rejeu ne duplique rien.
    expect(InvoiceFile::query()->count())->toBe(3)
        ->and(InboundInvoice::query()->count())->toBe(1);
});

it('ne construit pas le chemin à partir du nom transmis', function (): void {
    Http::fake([
        API.'/v1/invoice/'.INVOICE_ID.'/files' => Http::response([[
            'fileId' => 'f-hostile', 'type' => 'ATTACHMENT', 'mimeType' => 'application/octet-stream',
            'originalFilename' => '../../../../etc/passwd', 'checksum' => 'x',
        ]]),
        API.'/v1/invoice/file/f-hostile/download' => Http::response('contenu'),
        API.'/v1/invoice/'.INVOICE_ID => Http::response(reponseFacture()),
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
    ]);

    traiterFactureRecue();

    // Le nom vient de l'extérieur : il ne doit jamais servir à composer un chemin.
    expect(InvoiceFile::query()->first()->path)->not->toContain('..')
        ->and(InvoiceFile::query()->first()->path)->toStartWith('einvoicing/');
});

it('conserve la facture même si la plateforme ne répond pas', function (): void {
    Http::fake([
        API.'/v1/invoice/*' => Http::response(['statusMessage' => 'indisponible'], 503),
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
    ]);

    $facture = traiterFactureRecue();

    // Sans métadonnées ni fichiers, la facture existe et reste complétable plus tard.
    expect($facture->provider_invoice_id)->toBe(INVOICE_ID)
        ->and($facture->invoice_number)->toBeNull()
        ->and(InvoiceFile::query()->count())->toBe(0);
});

it('lit une facture renvoyée dans un tableau', function (): void {
    // Forme réellement servie par l'API : une liste, pas un objet.
    Http::fake([
        API.'/v1/invoice/'.INVOICE_ID => Http::response([reponseFacture()]),
        API.'/v1/invoice/'.INVOICE_ID.'/files' => Http::response([]),
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
    ]);

    expect(traiterFactureRecue()->invoice_number)->toBe('F-2026-0042');
});

it('reconnaît le format quelle qu\'en soit la casse', function (string $annonce): void {
    // La spécification annonce FACTURX ; l'API sert FacturX.
    $facture = reponseFacture();
    $facture['originalFormat'] = $annonce;

    Http::fake([
        API.'/v1/invoice/'.INVOICE_ID => Http::response([$facture]),
        API.'/v1/invoice/'.INVOICE_ID.'/files' => Http::response([]),
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
    ]);

    expect(traiterFactureRecue()->format)->toBe(InvoiceFormat::Facturx);
})->with(['tel que documenté' => ['FACTURX'], 'tel que servi' => ['FacturX'], 'minuscules' => ['facturx']]);
