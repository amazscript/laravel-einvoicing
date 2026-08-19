<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Facades\Einvoicing;
use AmazScript\Einvoicing\Models\Tenant;
use Illuminate\Support\Facades\Http;

const RECHERCHE_API = 'https://api.example.test';

function tenantRecherche(): Tenant
{
    return Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => '1',
        'customer_id' => 'cust-1', 'siren' => '111111111', 'siret' => null, 'active' => true,
    ]);
}

/**
 * Sert un jeu de résultats paginé, dans l'enveloppe { data, meta } employée par
 * la plateforme.
 */
function fakeRecherche(int $total): void
{
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        RECHERCHE_API.'/v1.1/invoice/search*' => function ($request) use ($total) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $offset = (int) ($query['offset'] ?? 0);
            $limit = (int) ($query['limit'] ?? 50);

            $lignes = [];
            for ($i = $offset; $i < min($offset + $limit, $total); $i++) {
                $lignes[] = ['invoiceId' => 'inv-'.$i, 'way' => 'RECEIVED'];
            }

            return Http::response(['data' => $lignes, 'meta' => ['offset' => $offset, 'limit' => $limit, 'count' => $total]]);
        },
    ]);
}

beforeEach(function (): void {
    config()->set('einvoicing.drivers.iopole.base_url', RECHERCHE_API);
    config()->set('einvoicing.drivers.iopole.token_url', RECHERCHE_API.'/token');
    config()->set('einvoicing.drivers.iopole.client_id', 'client');
    config()->set('einvoicing.drivers.iopole.client_secret', 'secret');
});

it('parcourt les résultats sans tout charger', function (int $total): void {
    fakeRecherche($total);

    expect(Einvoicing::for(tenantRecherche())->invoices()->search('invoice.direction:"INBOUND"')->all())
        ->toHaveCount($total);
})->with([
    'aucun' => [0],
    'un seul' => [1],
    'une page' => [50],
    'plusieurs pages' => [230],
]);

it('construit la requête à partir de critères', function (): void {
    fakeRecherche(1);

    Einvoicing::for(tenantRecherche())->invoices()->search([
        'invoice.direction' => 'INBOUND',
        'invoice.state' => 'NOT_DELIVERED',
    ])->all();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/v1.1/invoice/search')) {
            return false;
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['q'] ?? '') === 'invoice.direction:"INBOUND" AND invoice.state:"NOT_DELIVERED"';
    });
});

it('neutralise un guillemet glissé dans un critère', function (): void {
    fakeRecherche(0);

    // Une valeur venue de l'extérieur ne doit pas pouvoir refermer la requête
    // et en gouverner le sens.
    Einvoicing::for(tenantRecherche())->invoices()->search(['invoice.state' => 'A" OR invoice.state:"B'])->all();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/search')) {
            return false;
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ! str_contains((string) ($query['q'] ?? ''), '" OR ');
    });
});

it('ne demande qu\'une page pour les premiers résultats', function (): void {
    fakeRecherche(5000);

    $trois = Einvoicing::for(tenantRecherche())->invoices()->search('invoice.direction:"INBOUND"')->take(3)->all();

    expect($trois)->toHaveCount(3);
    Http::assertSentCount(2); // le jeton, puis une seule page
});

it('agit sous le customer-id du dossier', function (): void {
    fakeRecherche(1);

    Einvoicing::for(tenantRecherche())->invoices()->search('invoice.direction:"INBOUND"')->all();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/token')
        || $request->header('customer-id') === ['cust-1']);
});
