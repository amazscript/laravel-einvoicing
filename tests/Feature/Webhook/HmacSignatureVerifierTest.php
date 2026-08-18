<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Webhook\HmacSignatureVerifier;

/**
 * Validation croisée : les vecteurs sont produits par le code Node.js publié par
 * la plateforme (tests/Fixtures/hmac-vectors.generate.js). Si cette
 * implémentation PHP les accepte, c'est qu'elle applique le même algorithme, et
 * non ma lecture de la documentation.
 */
function vectors(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/hmac-vectors.json'), true);
}

function vectorCase(string $name): array
{
    foreach (vectors()['cases'] as $case) {
        if ($case['name'] === $name) {
            return $case;
        }
    }

    throw new RuntimeException("vecteur inconnu : {$name}");
}

function verifier(?int $tolerance = null): HmacSignatureVerifier
{
    return new HmacSignatureVerifier(vectors()['secret'], $tolerance ?? PHP_INT_MAX);
}

function headersFor(array $case, array $overrides = []): array
{
    return array_merge([
        'x-timestamp' => vectors()['timestamp'],
        'x-signature' => $case['signature'],
    ], $overrides);
}

function verify(array $case, array $headers, ?string $source = null): bool
{
    return verifier()->verify(
        $headers,
        vectors()['method'],
        vectors()['pathWithQuery'],
        $source ?? ($case['name'] === 'json' ? $case['body'] : $case['fileContent']),
    );
}

it('reproduit exactement le checksum et la signature calculés par la plateforme', function (string $name): void {
    $case = vectorCase($name);
    $source = $name === 'json' ? $case['body'] : $case['fileContent'];

    $checksum = hash('sha256', $source);
    $canonical = vectors()['timestamp']."\n".vectors()['method']."\n".vectors()['pathWithQuery']."\n".$checksum;

    expect($checksum)->toBe($case['checksum'])
        ->and($canonical)->toBe($case['canonical'])
        ->and(hash_hmac('sha256', $canonical, vectors()['secret']))->toBe($case['signature']);
})->with(['json', 'multipart']);

it('accepte une signature valide en application/json', function (): void {
    $case = vectorCase('json');

    expect(verify($case, headersFor($case)))->toBeTrue();
});

it('accepte une signature valide en multipart malgré les champs annexes', function (): void {
    $case = vectorCase('multipart');

    // Le corps transmis contient invoiceId, format et direction en plus du
    // fichier : ils ne doivent entrer ni dans le checksum ni dans la signature.
    expect($case['extraFields'])->not->toBeEmpty()
        ->and(verify($case, headersFor($case)))->toBeTrue();
});

it('rejette une signature multipart calculée sur le corps entier', function (): void {
    // Le piège de cette intégration : signer tout le corps multipart au lieu du
    // seul contenu du fichier. Ce test échouerait si on s'y laissait prendre.
    $case = vectorCase('multipart');
    $piege = vectors()['multipartSignedOverWholeBody'];

    expect($piege)->not->toBe($case['signature'])
        ->and(verify($case, headersFor($case, ['x-signature' => $piege])))->toBeFalse();
});

it('vérifie le checksum transmis avant la signature', function (): void {
    $case = vectorCase('json');

    expect(verify($case, headersFor($case, ['x-checksum' => $case['checksum']])))->toBeTrue()
        ->and(verify($case, headersFor($case, ['x-checksum' => str_repeat('0', 64)])))->toBeFalse();
});

it('rejette un corps altéré d\'un seul octet', function (): void {
    $case = vectorCase('json');

    expect(verify($case, headersFor($case), $case['body'].' '))->toBeFalse();
});

it('rejette une signature absente, vide ou tronquée', function (?string $signature): void {
    $case = vectorCase('json');
    $headers = headersFor($case);
    $signature === null ? $headers = ['x-timestamp' => vectors()['timestamp']] : $headers['x-signature'] = $signature;

    expect(verify($case, $headers))->toBeFalse();
})->with([
    'absente' => [null],
    'vide' => [''],
    'tronquée' => ['372a6a4be9c21b553c7cb4426c3b5eb6'],
    'majuscules' => ['372A6A4BE9C21B553C7CB4426C3B5EB61F45FB9993CB1DD9579220E231F66024'],
]);

it('rejette une requête sans horodatage', function (): void {
    $case = vectorCase('json');

    expect(verify($case, ['x-signature' => $case['signature']]))->toBeFalse();
});

it('refuse tout lorsque le secret n\'est pas configuré', function (): void {
    $case = vectorCase('json');

    $sansSecret = new HmacSignatureVerifier('', PHP_INT_MAX);

    // Un secret absent ne vaut jamais absence de contrôle.
    expect($sansSecret->verify(headersFor($case), vectors()['method'], vectors()['pathWithQuery'], $case['body']))
        ->toBeFalse();
});

it('rejette une méthode ou un chemin différents de ceux signés', function (string $method, string $path): void {
    $case = vectorCase('json');

    expect(verifier()->verify(headersFor($case), $method, $path, $case['body']))->toBeFalse();
})->with([
    'méthode' => ['PUT', '/einvoicing/webhook?type=invoice'],
    'chemin' => ['POST', '/einvoicing/webhook'],
    'query réordonnée' => ['POST', '/einvoicing/webhook?type=status'],
]);

it('accepte la méthode en minuscules, la chaîne canonique la met en majuscules', function (): void {
    $case = vectorCase('json');

    expect(verifier()->verify(headersFor($case), 'post', vectors()['pathWithQuery'], $case['body']))->toBeTrue();
});

// ------------------------------------------------------------------ anti-rejeu

/**
 * Signe une requête à un instant donné, pour éprouver la fenêtre de tolérance
 * indépendamment des vecteurs figés.
 */
function signedAt(int $timestamp, string $body = '{"ping":true}'): array
{
    $checksum = hash('sha256', $body);
    $canonical = $timestamp."\nPOST\n/einvoicing/webhook\n".$checksum;

    return [
        'headers' => [
            'x-timestamp' => (string) $timestamp,
            'x-signature' => hash_hmac('sha256', $canonical, vectors()['secret']),
        ],
        'body' => $body,
    ];
}

it('accepte une requête dans la fenêtre de tolérance', function (int $decalage): void {
    $requete = signedAt(time() + $decalage);

    expect((new HmacSignatureVerifier(vectors()['secret'], 300))
        ->verify($requete['headers'], 'POST', '/einvoicing/webhook', $requete['body']))->toBeTrue();
})->with([
    'à l\'instant' => [0],
    'reçue avec 4 minutes de retard' => [-240],
    'horloge locale en léger retard' => [120],
]);

it('rejette une requête hors de la fenêtre de tolérance', function (int $decalage): void {
    $requete = signedAt(time() + $decalage);

    expect((new HmacSignatureVerifier(vectors()['secret'], 300))
        ->verify($requete['headers'], 'POST', '/einvoicing/webhook', $requete['body']))->toBeFalse();
})->with([
    'rejeu d\'une heure' => [-3600],
    'juste au-delà de la fenêtre' => [-301],
    'horodatage dans le futur' => [3600],
]);

it('rejette un horodatage non numérique', function (string $timestamp): void {
    $requete = signedAt(time());
    $requete['headers']['x-timestamp'] = $timestamp;

    expect((new HmacSignatureVerifier(vectors()['secret'], 300))
        ->verify($requete['headers'], 'POST', '/einvoicing/webhook', $requete['body']))->toBeFalse();
})->with([
    'texte' => ['maintenant'],
    'vide' => [''],
    'injection' => ['1755525600\nPOST'],
]);
