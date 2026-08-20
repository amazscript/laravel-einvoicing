<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Enums\OutboundStatus;
use AmazScript\Einvoicing\Events\OutboundInvoiceFailed;
use AmazScript\Einvoicing\Events\OutboundInvoiceSent;
use AmazScript\Einvoicing\Exceptions\EinvoicingServerException;
use AmazScript\Einvoicing\Facades\Einvoicing;
use AmazScript\Einvoicing\Models\OutboundInvoice;
use AmazScript\Einvoicing\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const ENVOI_API = 'https://api.example.test';

function fichierFacture(string $contenu = '<Invoice>facture</Invoice>'): string
{
    $chemin = sys_get_temp_dir().'/facture-'.md5($contenu).'.xml';
    file_put_contents($chemin, $contenu);

    return $chemin;
}

function dossier(): Tenant
{
    return Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => '1',
        'customer_id' => 'cust-1', 'siren' => '948779160', 'siret' => null, 'active' => true,
    ]);
}

beforeEach(function (): void {
    config()->set('einvoicing.drivers.iopole.base_url', ENVOI_API);
    config()->set('einvoicing.drivers.iopole.token_url', ENVOI_API.'/token');
    config()->set('einvoicing.drivers.iopole.client_id', 'client');
    config()->set('einvoicing.drivers.iopole.client_secret', 'secret');
});

it('remet une facture à la plateforme et retient son identifiant', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ENVOI_API.'/v1/invoice' => Http::response(['type' => 'INVOICE', 'id' => 'inv-777'], 201),
    ]);

    $envoi = Einvoicing::for(dossier())->send(fichierFacture());

    expect($envoi->provider_invoice_id)->toBe('inv-777')
        ->and($envoi->status)->toBe(OutboundStatus::Sent)
        ->and($envoi->sent_at)->not->toBeNull()
        ->and($envoi->file_name)->toEndWith('.xml');
});

it('envoie bien le fichier en multipart', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ENVOI_API.'/v1/invoice' => Http::response(['id' => 'inv-1'], 201),
    ]);

    Einvoicing::for(dossier())->send(fichierFacture('<Invoice>contenu précis</Invoice>'));

    Http::assertSent(function ($request): bool {
        if (! str_ends_with($request->url(), '/v1/invoice')) {
            return false;
        }

        // asJson() épinglerait « application/json », qui survivrait à
        // asMultipart() : le corps partirait en multipart sous une fausse
        // étiquette, et la plateforme le refuserait.
        return ($request->header('Content-Type')[0] ?? '') !== 'application/json'
            && ($request->data()[0]['name'] ?? null) === 'file';
    });
});

it('ne facture pas deux fois quand le même fichier est renvoyé', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ENVOI_API.'/v1/invoice' => Http::response(['id' => 'inv-unique'], 201),
    ]);

    $dossier = dossier();
    $fichier = fichierFacture('<Invoice>une seule fois</Invoice>');

    $premier = Einvoicing::for($dossier)->send($fichier);
    $second = Einvoicing::for($dossier)->send($fichier);

    // L'endpoint n'accepte aucune clé d'idempotence : c'est la base qui protège.
    expect($second->id)->toBe($premier->id)
        ->and(OutboundInvoice::query()->count())->toBe(1);

    Http::assertSentCount(2); // le jeton, puis un seul envoi
});

it('distingue deux factures différentes du même dossier', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ENVOI_API.'/v1/invoice' => Http::response(['id' => 'inv-x'], 201),
    ]);

    $dossier = dossier();
    Einvoicing::for($dossier)->send(fichierFacture('<Invoice>numéro 1</Invoice>'));
    Einvoicing::for($dossier)->send(fichierFacture('<Invoice>numéro 2</Invoice>'));

    expect(OutboundInvoice::query()->count())->toBe(2);
});

it('conserve la trace d\'un refus au lieu de l\'effacer', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ENVOI_API.'/v1/invoice' => Http::response([
            'issues' => [['path' => 'file', 'code' => 'custom', 'message' => 'Invalid Factur-X profile']],
        ], 400),
    ]);

    $envoi = null;

    try {
        Einvoicing::for(dossier())->send(fichierFacture('<Invoice>cassée</Invoice>'));
    } catch (Throwable) {
        $envoi = OutboundInvoice::query()->first();
    }

    // Ce qui a été refusé, et pourquoi, est ce qu'on viendra demander.
    expect($envoi)->not->toBeNull()
        ->and($envoi->status)->toBe(OutboundStatus::Failed)
        ->and($envoi->failure_reason)->not->toBeEmpty()
        ->and($envoi->provider_invoice_id)->toBeNull();
});

it('émet un event à l\'envoi et un autre au refus', function (): void {
    Event::fake([OutboundInvoiceSent::class, OutboundInvoiceFailed::class]);
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ENVOI_API.'/v1/invoice' => Http::response(['id' => 'inv-ok'], 201),
    ]);

    Einvoicing::for(dossier())->send(fichierFacture('<Invoice>event</Invoice>'));

    Event::assertDispatched(OutboundInvoiceSent::class);
    Event::assertNotDispatched(OutboundInvoiceFailed::class);
});

it('refuse un chemin qui ne mène à aucun fichier', function (): void {
    Einvoicing::for(dossier())->send('/chemin/inexistant.xml');
})->throws(InvalidArgumentException::class, 'not found or unreadable');

it('refuse une réponse 201 sans identifiant', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ENVOI_API.'/v1/invoice' => Http::response(['type' => 'INVOICE'], 201),
    ]);

    // Une facture acceptée mais innommée ne pourrait plus jamais être suivie.
    Einvoicing::for(dossier())->send(fichierFacture('<Invoice>sans id</Invoice>'));
})->throws(EinvoicingServerException::class);

it('exige un dossier pour émettre', function (): void {
    Einvoicing::operator()->send(fichierFacture());
})->throws(RuntimeException::class, 'requires a tenant');
