<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Facades\Einvoicing;
use Illuminate\Support\Facades\Http;

const ANNUAIRE = 'https://api.example.test';

beforeEach(function (): void {
    config()->set('einvoicing.drivers.iopole.base_url', ANNUAIRE);
    config()->set('einvoicing.drivers.iopole.token_url', ANNUAIRE.'/token');
    config()->set('einvoicing.drivers.iopole.client_id', 'client');
    config()->set('einvoicing.drivers.iopole.client_secret', 'secret');
});

/**
 * Sert un annuaire de $total entrées, page par page, dans l'enveloppe
 * { data, meta } réellement employée par la plateforme.
 */
function fakeAnnuaire(int $total): void
{
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ANNUAIRE.'/v1/directory/french*' => function ($request) use ($total) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $offset = (int) ($query['offset'] ?? 0);
            $limit = (int) ($query['limit'] ?? 50);

            $lignes = [];
            for ($i = $offset; $i < min($offset + $limit, $total); $i++) {
                $lignes[] = ['businessEntityId' => 'be-'.$i, 'name' => 'Entreprise '.$i];
            }

            return Http::response([
                'data' => $lignes,
                'meta' => ['offset' => $offset, 'limit' => $limit, 'count' => $total],
            ]);
        },
    ]);
}

it('parcourt l\'annuaire quel qu\'en soit le volume', function (int $total): void {
    fakeAnnuaire($total);

    $resultats = Einvoicing::directory()->search('BAT')->all();

    expect($resultats)->toHaveCount($total);

    if ($total > 0) {
        expect($resultats[0]['businessEntityId'])->toBe('be-0')
            ->and($resultats[$total - 1]['businessEntityId'])->toBe('be-'.($total - 1));
    }
})->with([
    'aucun résultat' => [0],
    'un seul' => [1],
    'juste avant une page pleine' => [49],
    'une page pleine' => [50],
    'un peu plus d\'une page' => [99],
    'deux pages pleines' => [100],
    'cinq pages' => [250],
]);

it('ne charge pas tout en mémoire pour lire les premiers résultats', function (): void {
    fakeAnnuaire(1000);

    // Trois entrées demandées : une seule page doit partir sur le réseau.
    $trois = Einvoicing::directory()->search('BAT')->take(3)->all();

    expect($trois)->toHaveCount(3);
    Http::assertSentCount(2); // le jeton, puis une seule page
});

it('s\'arrête si la plateforme rend une page vide malgré son total', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        ANNUAIRE.'/v1/directory/french*' => Http::response([
            'data' => [],
            'meta' => ['offset' => 0, 'limit' => 50, 'count' => 9999],
        ]),
    ]);

    // Sans cette garde, un total erroné ferait boucler l'itérateur indéfiniment.
    expect(Einvoicing::directory()->search('BAT')->all())->toBe([]);
});
