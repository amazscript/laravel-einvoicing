<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Enums\InvoiceFileKind;
use AmazScript\Einvoicing\Facades\Einvoicing;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Storage\InvoiceFileStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const BASE = 'https://api.example.test';

function tenantDe(string $siren, string $customerId): Tenant
{
    return Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => $siren,
        'customer_id' => $customerId, 'siren' => $siren, 'siret' => null, 'active' => true,
    ]);
}

function factureDe(Tenant $tenant, string $providerInvoiceId): InboundInvoice
{
    return InboundInvoice::query()->create([
        'tenant_id' => $tenant->id, 'provider' => 'iopole',
        'provider_invoice_id' => $providerInvoiceId,
    ]);
}

beforeEach(function (): void {
    config()->set('einvoicing.drivers.iopole.base_url', BASE);
    config()->set('einvoicing.drivers.iopole.token_url', BASE.'/token');
    config()->set('einvoicing.drivers.iopole.client_id', 'client');
    config()->set('einvoicing.drivers.iopole.client_secret', 'secret');
    config()->set('einvoicing.storage.disk', 'einvoicing-test');
    Storage::fake('einvoicing-test');
});

it('agit sous le customer-id du dossier concerné', function (): void {
    Http::fake([
        BASE.'/v1/invoice/notSeen' => Http::response([['invoiceId' => 'inv-1']]),
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
    ]);

    $tenant = tenantDe('111111111', 'cust-du-dossier');
    Einvoicing::for($tenant)->invoices()->remoteNotSeen();

    // Dans un parc multi-tenant, un en-tête erroné lirait les factures d'un autre.
    Http::assertSent(function ($request): bool {
        return ! str_contains($request->url(), '/token')
            && $request->header('customer-id') === ['cust-du-dossier'];
    });
});

it('sépare les factures locales des factures non acquittées', function (): void {
    Http::fake([
        BASE.'/v1/invoice/notSeen' => Http::response([['invoiceId' => 'distante-1'], ['invoiceId' => 'distante-2']]),
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
    ]);

    $tenant = tenantDe('111111111', 'cust-1');
    factureDe($tenant, 'locale-1');

    expect(Einvoicing::for($tenant)->invoices()->local()->count())->toBe(1)
        ->and(Einvoicing::for($tenant)->invoices()->remoteNotSeen())->toHaveCount(2);
});

it('ne montre à un dossier que ses propres factures', function (): void {
    $premier = tenantDe('111111111', 'cust-1');
    $second = tenantDe('222222222', 'cust-2');
    factureDe($premier, 'inv-du-premier');
    factureDe($second, 'inv-du-second');

    expect(Einvoicing::for($premier)->invoices()->local()->pluck('provider_invoice_id')->all())
        ->toBe(['inv-du-premier']);
});

it('refuse de livrer la facture d\'un autre dossier', function (): void {
    $premier = tenantDe('111111111', 'cust-1');
    $second = tenantDe('222222222', 'cust-2');
    $facture = factureDe($second, 'inv-du-second');

    expect(fn () => Einvoicing::for($premier)->invoice($facture->id))
        ->toThrow(RuntimeException::class);
});

it('acquitte une facture auprès de la plateforme avant de le noter', function (): void {
    Http::fake([
        BASE.'/v1/invoice/*/markAsSeen' => Http::response([], 204),
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
    ]);

    $tenant = tenantDe('111111111', 'cust-1');
    $facture = factureDe($tenant, 'inv-1');

    Einvoicing::for($tenant)->invoice($facture->id)->markAsSeen();

    expect($facture->refresh()->seen_at)->not->toBeNull();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/invoice/inv-1/markAsSeen'));
});

it('ne note pas comme vue une facture que la plateforme a refusé d\'acquitter', function (): void {
    Http::fake([
        BASE.'/v1/invoice/*/markAsSeen' => Http::response(['statusMessage' => 'en panne'], 503),
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
    ]);

    $tenant = tenantDe('111111111', 'cust-1');
    $facture = factureDe($tenant, 'inv-1');

    expect(fn () => Einvoicing::for($tenant)->invoice($facture->id)->markAsSeen())->toThrow(Exception::class)
        ->and($facture->refresh()->seen_at)->toBeNull();
});

it('sert un document déjà stocké sans rappeler la plateforme', function (): void {
    Http::fake(['*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300])]);

    $tenant = tenantDe('111111111', 'cust-1');
    $facture = factureDe($tenant, 'inv-1');

    app(InvoiceFileStore::class)
        ->store($facture, InvoiceFileKind::Xml, '<Invoice>déjà là</Invoice>');

    expect(Einvoicing::for($tenant)->invoice($facture->id)->xml())->toBe('<Invoice>déjà là</Invoice>');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/download'));
});

it('va chercher le document quand il n\'est pas encore stocké', function (): void {
    Http::fake([
        BASE.'/v1/invoice/inv-1/download' => Http::response('<Invoice>distant</Invoice>'),
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
    ]);

    $tenant = tenantDe('111111111', 'cust-1');
    $facture = factureDe($tenant, 'inv-1');

    expect(Einvoicing::for($tenant)->invoice($facture->id)->xml())->toBe('<Invoice>distant</Invoice>');
});

it('rend les pièces jointes d\'une facture', function (): void {
    Http::fake(['*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300])]);

    $tenant = tenantDe('111111111', 'cust-1');
    $facture = factureDe($tenant, 'inv-1');
    $magasin = app(InvoiceFileStore::class);

    $magasin->store($facture, InvoiceFileKind::Attachment, 'bon de livraison', 'f-1', 'bl.pdf');
    $magasin->store($facture, InvoiceFileKind::Attachment, 'photo du colis', 'f-2', 'colis.png');
    $magasin->store($facture, InvoiceFileKind::Xml, '<Invoice/>', 'f-3', 'facture.xml');

    $jointes = Einvoicing::for($tenant)->invoice($facture->id)->attachments();

    // Le document d'origine n'est pas une pièce jointe : il ne doit pas s'y glisser.
    expect($jointes)->toHaveCount(2)
        ->and($jointes->pluck('provider_file_id')->sort()->values()->all())->toBe(['f-1', 'f-2']);
});

it('range une pièce sur le disque demandé par l\'application', function (): void {
    Storage::fake('archives');
    Http::fake([
        BASE.'/v1/invoice/inv-1/download' => Http::response('<Invoice>à archiver</Invoice>'),
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
    ]);

    $tenant = tenantDe('111111111', 'cust-1');
    $facture = factureDe($tenant, 'inv-1');

    $fichier = Einvoicing::for($tenant)->invoice($facture->id)->store('archives');

    expect($fichier->disk)->toBe('archives');
    Storage::disk('archives')->assertExists($fichier->path);
});

it('remonte les statuts que la plateforme n\'a pas vus acquittés', function (): void {
    Http::fake([
        BASE.'/v1/invoice/status/notSeen' => Http::response([
            ['statusId' => 'sta-1', 'status' => ['code' => 'RECEIVED']],
            ['statusId' => 'sta-2', 'status' => ['code' => 'MADE_AVAILABLE']],
        ]),
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
    ]);

    $tenant = tenantDe('111111111', 'cust-1');

    expect(Einvoicing::for($tenant)->invoices()->remoteStatusesNotSeen())->toHaveCount(2);
});
