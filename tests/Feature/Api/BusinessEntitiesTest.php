<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Facades\Einvoicing;
use Illuminate\Support\Facades\Http;

const ENTITES_API = 'https://api.example.test';

/**
 * Forme relevée sur une réponse réelle de la plateforme, à la lettre.
 *
 * Deux pièges y sont visibles : l'adresse joignable (`directoryAddress`, en
 * 0225) n'est pas l'identifiant légal (`scheme:value`, en 0002) posé juste à
 * côté, et une inscription ne porte ni statut ni plateforme desservante.
 */
function entiteBrute(string $nom, string $siren, ?string $inscriteDepuis = '2026-08-01'): array
{
    return [
        'businessEntityId' => 'be-'.$siren,
        'name' => $nom,
        'type' => 'LEGAL_UNIT',
        'scope' => 'PRIVATE_TAX_PAYER',
        'country' => 'FR',
        'identifierScheme' => '0002',
        'identifierValue' => $siren,
        'countryIdentifier' => ['siren' => $siren],
        'identifiers' => [[
            'businessEntityIdentifierId' => 'id-'.$siren,
            'type' => 'LEGAL_IDENTIFIER',
            'scheme' => '0002',
            'value' => $siren,
            'networkRegistered' => $inscriteDepuis === null ? [] : [[
                'directoryId' => 'dir-'.$siren,
                'networkId' => 'net-1',
                'directoryAddress' => '0225:'.$siren,
                'networkIdentifier' => 'DOMESTIC_FR',
                'isSelfBilling' => false,
                'validFrom' => $inscriteDepuis,
            ]],
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
    fakeEntites([entiteBrute('UNIBAT34', '948779160')]);

    $entites = Einvoicing::entities()->all()->all();

    expect($entites)->toHaveCount(1)
        ->and($entites[0]->name)->toBe('UNIBAT34')
        ->and($entites[0]->siren)->toBe('948779160');
});

it('distingue l\'adresse joignable de l\'identifiant légal', function (): void {
    fakeEntites([entiteBrute('UNIBAT34', '948779160')]);

    $entite = Einvoicing::entities()->all()->first();

    // C'est la première, en 0225, que cite un rejet « No route found ».
    expect($entite->electronicAddress())->toBe('0225:948779160')
        ->and($entite->identifiers[0]->legalAddress())->toBe('0002:948779160');
});

it('tient pour joignable une entreprise inscrite à l\'annuaire', function (): void {
    fakeEntites([entiteBrute('JOIGNABLE', '111111111')]);

    $entite = Einvoicing::entities()->all()->first();

    expect($entite->isReachable())->toBeTrue()
        ->and($entite->unreachableReason())->toBeNull();
});

it('refuse une entreprise sans inscription à l\'annuaire', function (): void {
    fakeEntites([entiteBrute('DECLAREE SEULEMENT', '222222222', inscriteDepuis: null)]);

    $entite = Einvoicing::entities()->all()->first();

    expect($entite->isReachable())->toBeFalse()
        ->and($entite->unreachableReason())->toBe('no-registration')
        ->and($entite->electronicAddress())->toBeNull();
});

it('ne tient pas pour joignable une inscription qui ne prend effet que demain', function (): void {
    fakeEntites([entiteBrute('BIENTOT', '333333333', inscriteDepuis: '2026-12-31')]);

    $entite = Einvoicing::entities()->all()->first();

    // Déposée mais pas encore en vigueur : une facture émise aujourd'hui rebondit.
    expect($entite->isReachable(new DateTimeImmutable('2026-08-20')))->toBeFalse()
        ->and($entite->unreachableReason(new DateTimeImmutable('2026-08-20')))
        ->toBe('registration-not-yet-active')
        ->and($entite->isReachable(new DateTimeImmutable('2027-01-02')))->toBeTrue();
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

it('ignore une inscription dépourvue d\'adresse d\'annuaire', function (): void {
    $brute = entiteBrute('BANCALE', '444444444');
    unset($brute['identifiers'][0]['networkRegistered'][0]['directoryAddress']);
    fakeEntites([$brute]);

    // Sans adresse, l'entrée ne route rien : elle ne compte pas comme inscription.
    expect(Einvoicing::entities()->all()->first()->unreachableReason())->toBe('no-registration');
});

it('trie les entreprises joignables des autres', function (): void {
    fakeEntites([
        entiteBrute('JOIGNABLE', '111111111'),
        entiteBrute('DECLAREE SEULEMENT', '222222222', inscriteDepuis: null),
    ]);

    expect(Einvoicing::entities()->reachable()->pluck('name')->all())->toBe(['JOIGNABLE'])
        ->and(Einvoicing::entities()->unreachable()->pluck('name')->all())->toBe(['DECLAREE SEULEMENT']);
});

it('ne charge pas tout l\'annuaire pour lire les premières entreprises', function (): void {
    fakeEntites(array_map(
        fn (int $i): array => entiteBrute('SOCIETE '.$i, str_pad((string) $i, 9, '0', STR_PAD_LEFT)),
        range(1, 50)
    ));

    expect(Einvoicing::entities()->all()->take(3)->all())->toHaveCount(3);
    Http::assertSentCount(2); // le jeton, puis une seule page
});

it('déballe une entreprise rendue sous forme de liste', function (): void {
    // Comme /v1/invoice/{id}, l'endpoint unitaire répond par une liste d'un élément.
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ENTITES_API.'/v1/config/business/entity/*' => Http::response([entiteBrute('UNIBAT34', '948779160')]),
    ]);

    expect(Einvoicing::entities()->find('be-948779160')?->name)->toBe('UNIBAT34');
});

it('rend null pour une entreprise inconnue', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ENTITES_API.'/v1/config/business/entity/*' => Http::response(['statusMessage' => 'not found'], 404),
    ]);

    expect(Einvoicing::entities()->find('inconnue'))->toBeNull();
});
