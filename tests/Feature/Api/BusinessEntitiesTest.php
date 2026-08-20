<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Facades\Einvoicing;
use Illuminate\Support\Facades\Http;

const ENTITES_API = 'https://api.example.test';

/**
 * Forme relevée sur des réponses réelles : les identifiants portent leurs
 * inscriptions réseau, et platformDetail vaut null quand personne ne dessert
 * l'adresse — le cas qui fait rebondir une facture.
 */
function entiteBrute(string $nom, string $siren, ?array $plateforme = null, bool $inscrite = true): array
{
    return [
        'businessEntityId' => 'be-'.$siren,
        'name' => $nom,
        'type' => 'LEGAL_UNIT',
        'scope' => 'PRIVATE_TAX_PAYER',
        'country' => 'FR',
        'countryIdentifier' => ['siren' => $siren, 'siret' => null],
        'identifiers' => [[
            'businessEntityIdentifierId' => 'id-'.$siren,
            'type' => 'LEGAL_IDENTIFIER',
            'scheme' => '0002',
            'value' => $siren,
            'networkRegistered' => $inscrite ? [[
                'networkIdentifier' => 'DOMESTIC_FR',
                'status' => $plateforme ? 'ACTIVE' : null,
                'validFrom' => '2026-08-18',
                'validTo' => null,
                'directoryId' => 'dir-'.$siren,
                'platformDetail' => $plateforme,
            ]] : [],
        ]],
    ];
}

function fakeEntites(array $entites): void
{
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ENTITES_API.'/v1/config/business/entity*' => Http::response([
            'data' => $entites,
            'meta' => ['offset' => 0, 'limit' => 50, 'count' => count($entites)],
        ]),
    ]);
}

beforeEach(function (): void {
    config()->set('einvoicing.drivers.iopole.base_url', ENTITES_API);
    config()->set('einvoicing.drivers.iopole.token_url', ENTITES_API.'/token');
    config()->set('einvoicing.drivers.iopole.client_id', 'client');
    config()->set('einvoicing.drivers.iopole.client_secret', 'secret');
});

it('lit les entreprises déclarées', function (): void {
    fakeEntites([entiteBrute('UNIBAT34', '948779160', ['name' => 'IOPOLE'])]);

    $entites = Einvoicing::entities()->all()->all();

    expect($entites)->toHaveCount(1)
        ->and($entites[0]->name)->toBe('UNIBAT34')
        ->and($entites[0]->siren)->toBe('948779160')
        ->and($entites[0]->identifiers[0]->electronicAddress())->toBe('0002:948779160');
});

it('distingue une entreprise joignable d\'une entreprise seulement déclarée', function (): void {
    fakeEntites([
        entiteBrute('JOIGNABLE', '111111111', ['name' => 'IOPOLE']),
        entiteBrute('SANS PLATEFORME', '222222222', null),
        entiteBrute('SANS INSCRIPTION', '333333333', null, inscrite: false),
    ]);

    expect(Einvoicing::entities()->reachable()->pluck('name')->all())->toBe(['JOIGNABLE'])
        ->and(Einvoicing::entities()->unreachable()->pluck('name')->all())
        ->toBe(['SANS PLATEFORME', 'SANS INSCRIPTION']);
});

it('explique pourquoi une entreprise ne peut pas être facturée', function (): void {
    fakeEntites([
        entiteBrute('SANS PLATEFORME', '222222222', null),
        entiteBrute('SANS INSCRIPTION', '333333333', null, inscrite: false),
    ]);

    $raisons = Einvoicing::entities()->all()->map->unreachableReason()->all();

    // Un code, pas une phrase : c'est à l'application hôte de choisir sa langue.
    expect($raisons[0])->toBe('no-serving-platform')
        ->and($raisons[1])->toBe('no-registration');
});

it('ne donne aucune raison à une entreprise joignable', function (): void {
    fakeEntites([entiteBrute('JOIGNABLE', '111111111', ['name' => 'IOPOLE'])]);

    expect(Einvoicing::entities()->all()->first()->unreachableReason())->toBeNull();
});

it('supporte une entreprise sans identifiant', function (): void {
    fakeEntites([[
        'businessEntityId' => 'be-vide', 'name' => 'SANS IDENTIFIANT',
        'countryIdentifier' => [], 'identifiers' => [],
    ]]);

    $entite = Einvoicing::entities()->all()->first();

    expect($entite->isReachable())->toBeFalse()
        ->and($entite->unreachableReason())->toBe('no-identifier');
});

it('ne charge pas tout l\'annuaire pour lire les premières entreprises', function (): void {
    fakeEntites(array_map(fn (int $i): array => entiteBrute('SOCIETE '.$i, str_pad((string) $i, 9, '0', STR_PAD_LEFT), null), range(1, 50)));

    expect(Einvoicing::entities()->all()->take(3)->all())->toHaveCount(3);
    Http::assertSentCount(2); // le jeton, puis une seule page
});

it('rend null pour une entreprise inconnue', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ENTITES_API.'/v1/config/business/entity/*' => Http::response(['statusMessage' => 'not found'], 404),
    ]);

    expect(Einvoicing::entities()->find('inconnue'))->toBeNull();
});
