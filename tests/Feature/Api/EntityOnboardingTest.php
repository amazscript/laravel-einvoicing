<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Enums\EntityScope;
use AmazScript\Einvoicing\Enums\InvoicingNetwork;
use AmazScript\Einvoicing\Enums\VatRegime;
use AmazScript\Einvoicing\Exceptions\EinvoicingServerException;
use AmazScript\Einvoicing\Facades\Einvoicing;
use Illuminate\Support\Facades\Http;

const ONBOARDING_API = 'https://api.example.test';

/** @return array{url: string, corps: array<string, mixed>} */
function dernierAppel(string $fragment): array
{
    $vu = ['url' => '', 'corps' => []];

    Http::assertSent(function ($request) use (&$vu, $fragment): bool {
        if (str_contains($request->url(), $fragment)) {
            $vu = ['url' => $request->url(), 'corps' => $request->data()];
        }

        return true;
    });

    return $vu;
}

beforeEach(function (): void {
    config()->set('einvoicing.drivers.iopole.base_url', ONBOARDING_API);
    config()->set('einvoicing.drivers.iopole.token_url', ONBOARDING_API.'/token');
    config()->set('einvoicing.drivers.iopole.client_id', 'client');
    config()->set('einvoicing.drivers.iopole.client_secret', 'secret');
});

it('déclare une unité légale', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ONBOARDING_API.'/v1/config/business/entity/legalunit' => Http::response(
            ['type' => 'BUSINESS_ENTITY', 'id' => 'be-neuve'], 201
        ),
    ]);

    $id = Einvoicing::entities()->declareLegalUnit('UNIBAT34', '948779160');

    expect($id)->toBe('be-neuve');

    $corps = dernierAppel('legalunit')['corps'];

    expect($corps)->toMatchArray([
        'name' => 'UNIBAT34',
        'country' => 'FR',
        'scope' => 'PRIVATE_TAX_PAYER',
        'identifierScheme' => '0002',
        'identifierValue' => '948779160',
    ])->and($corps['countryIdentifier']['siren'])->toBe('948779160');
});

it('accepte un SIREN écrit avec des espaces', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ONBOARDING_API.'/v1/config/business/entity/legalunit' => Http::response(['id' => 'be-1'], 201),
    ]);

    Einvoicing::entities()->declareLegalUnit('SOCIETE', '948 779 160');

    expect(dernierAppel('legalunit')['corps']['identifierValue'])->toBe('948779160');
});

it('refuse un SIREN qui n\'en est pas un', function (): void {
    // Mieux vaut échouer ici que créer une entité inutilisable sur la plateforme.
    Einvoicing::entities()->declareLegalUnit('SOCIETE', '12345');
})->throws(InvalidArgumentException::class, 'nine digits');

it('transmet le régime de TVA quand il est donné', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ONBOARDING_API.'/v1/config/business/entity/legalunit' => Http::response(['id' => 'be-1'], 201),
    ]);

    Einvoicing::entities()->declareLegalUnit(
        'MAIRIE', '948779160', EntityScope::Public, VatRegime::RealMonthly
    );

    expect(dernierAppel('legalunit')['corps'])->toMatchArray([
        'scope' => 'PUBLIC',
        'vatRegime' => 'REAL_MONTHLY_TAX_REGIME',
    ]);
});

it('omet le régime de TVA plutôt que d\'envoyer un vide', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ONBOARDING_API.'/v1/config/business/entity/legalunit' => Http::response(['id' => 'be-1'], 201),
    ]);

    Einvoicing::entities()->declareLegalUnit('SOCIETE', '948779160');

    expect(dernierAppel('legalunit')['corps'])->not->toHaveKey('vatRegime');
});

it('refuse une création dont la plateforme ne rend pas l\'identifiant', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ONBOARDING_API.'/v1/config/business/entity/legalunit' => Http::response(['type' => 'BUSINESS_ENTITY'], 201),
    ]);

    // Une entité créée mais innommée ne pourrait plus jamais être retrouvée.
    Einvoicing::entities()->declareLegalUnit('SOCIETE', '948779160');
})->throws(EinvoicingServerException::class);

it('inscrit une entreprise sur le réseau français', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ONBOARDING_API.'/v1/config/business/entity/identifier/*' => Http::response(['id' => 'dir-1'], 201),
    ]);

    Einvoicing::entities()->register('948779160');

    expect(dernierAppel('/identifier/')['url'])
        ->toContain('/scheme/0002/value/948779160/network/DOMESTIC_FR');
});

it('inscrit sur Peppol à une date d\'effet donnée', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ONBOARDING_API.'/v1/config/business/entity/identifier/*' => Http::response(['id' => 'dir-1'], 201),
    ]);

    Einvoicing::entities()->register(
        '948779160', InvoicingNetwork::PeppolInternational, new DateTimeImmutable('2026-09-01')
    );

    $appel = dernierAppel('/identifier/');

    expect($appel['url'])->toContain('/network/PEPPOL_INTERNATIONAL')
        ->and($appel['corps']['validityStartDate'])->toBe('2026-09-01');
});

it('refuse un identifiant qui n\'est ni SIREN ni SIRET', function (): void {
    Einvoicing::entities()->register('42');
})->throws(InvalidArgumentException::class, 'SIREN or a SIRET');
