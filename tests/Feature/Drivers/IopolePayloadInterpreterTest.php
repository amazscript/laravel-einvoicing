<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Drivers\Iopole\IopolePayloadInterpreter;
use AmazScript\Einvoicing\Webhook\InboundRequest;
use Illuminate\Http\Request;

function requeteAvec(array $entetes = [], array $payload = [], string $corps = ''): InboundRequest
{
    $corps = $corps !== '' ? $corps : json_encode($payload, JSON_THROW_ON_ERROR);
    $serveur = ['CONTENT_TYPE' => 'application/json'];

    foreach ($entetes as $nom => $valeur) {
        $serveur['HTTP_'.strtoupper(str_replace('-', '_', $nom))] = $valeur;
    }

    return InboundRequest::fromRequest(Request::create('/einvoicing/webhook', 'POST', [], [], [], $serveur, $corps));
}

it('reconnaît le schéma des adresses électroniques françaises', function (string $adresse, ?string $siren, ?string $siret): void {
    // Constaté sur des livraisons réelles : la plateforme emploie le schéma 0225,
    // absent de mes premiers tests, avec un SIREN pour valeur.
    $keys = (new IopolePayloadInterpreter)->routingKeys(requeteAvec(['x-target-electronic-address' => $adresse]));

    expect($keys->siren)->toBe($siren)
        ->and($keys->siret)->toBe($siret);
})->with([
    'adresse française, siren' => ['0225:948779160', '948779160', null],
    'adresse française, siret' => ['0225:94877916000018', null, '94877916000018'],
    'sirene explicite' => ['0002:948779160', '948779160', null],
    'siret explicite' => ['0009:94877916000018', null, '94877916000018'],
]);

it('ignore une adresse électronique inexploitable', function (string $adresse): void {
    $keys = (new IopolePayloadInterpreter)->routingKeys(requeteAvec(['x-target-electronic-address' => $adresse]));

    expect($keys->siren)->toBeNull()->and($keys->siret)->toBeNull();
})->with([
    'sans séparateur' => ['948779160'],
    'schéma inconnu' => ['9999:948779160'],
    'valeur vide' => ['0225:'],
    'longueur improbable' => ['0225:12345'],
]);

it('se rabat sur le destinataire du payload sans en-tête de routage', function (): void {
    $keys = (new IopolePayloadInterpreter)->routingKeys(requeteAvec([], [
        'statusId' => 'sta-1',
        'json' => ['recipients' => [['name' => 'UNIBAT34', 'siren' => '948779160', 'siret' => null]]],
    ]));

    expect($keys->siren)->toBe('948779160');
});

it('préfère l\'en-tête de routage au payload', function (): void {
    $keys = (new IopolePayloadInterpreter)->routingKeys(requeteAvec(
        ['x-target-electronic-address' => '0225:111111111'],
        ['json' => ['recipients' => [['siren' => '999999999']]]],
    ));

    expect($keys->siren)->toBe('111111111');
});

it('reconnaît une facture entrante à ses champs de formulaire', function (): void {
    // Champs réellement transmis avec le fichier d'une facture entrante.
    $requete = requeteAvec(['x-idempotency-key' => 'idem-1'], [
        'invoiceId' => '01a01903-f969-7795-b349-fea674bbf0b6',
        'senderAcceptStatus' => 'true',
    ]);

    expect((new IopolePayloadInterpreter)->idempotencyKey($requete))->toBe('idem-1');
});
