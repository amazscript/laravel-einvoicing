<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Webhook\HmacSignatureVerifier;

/**
 * Vecteur issu d'une livraison réellement émise par la plateforme.
 *
 * Les vecteurs synthétiques valident l'algorithme tel que la documentation le
 * décrit ; celui-ci valide ce que la plateforme envoie vraiment. C'est lui qui a
 * révélé que l'horodatage est exprimé en millisecondes, ce qu'aucune ligne de
 * leur documentation ne mentionne.
 */
function livraison(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/iopole-live-status-webhook.json'), true);
}

it('accepte une livraison réelle de la plateforme', function (): void {
    $v = livraison();

    // Tolérance volontairement immense : on éprouve ici la signature, pas la fraîcheur.
    $verificateur = new HmacSignatureVerifier($v['secret'], PHP_INT_MAX);

    expect($verificateur->verify($v['headers'], $v['method'], $v['pathWithQuery'], $v['body']))->toBeTrue();
});

it('transmet un horodatage en millisecondes', function (): void {
    $timestamp = livraison()['headers']['x-timestamp'];

    expect(strlen($timestamp))->toBe(13)
        ->and((int) $timestamp)->toBeGreaterThan(1_000_000_000_000);
});

it('accepte une livraison fraîche horodatée en millisecondes', function (): void {
    $v = livraison();
    $millisecondes = (string) (time() * 1000);

    $checksum = hash('sha256', $v['body']);
    $canonique = $millisecondes."\nPOST\n".$v['pathWithQuery']."\n".$checksum;

    $entetes = [
        'x-timestamp' => $millisecondes,
        'x-signature' => hash_hmac('sha256', $canonique, $v['secret']),
    ];

    // Sans normalisation de l'unité, l'écart calculé se compte en milliers d'années
    // et toute livraison est rejetée.
    $verificateur = new HmacSignatureVerifier($v['secret'], 300);

    expect($verificateur->verify($entetes, 'POST', $v['pathWithQuery'], $v['body']))->toBeTrue();
});

it('rejette une livraison en millisecondes hors tolérance', function (): void {
    $v = livraison();
    $millisecondes = (string) ((time() - 4000) * 1000);

    $checksum = hash('sha256', $v['body']);
    $canonique = $millisecondes."\nPOST\n".$v['pathWithQuery']."\n".$checksum;

    $entetes = [
        'x-timestamp' => $millisecondes,
        'x-signature' => hash_hmac('sha256', $canonique, $v['secret']),
    ];

    expect((new HmacSignatureVerifier($v['secret'], 300))
        ->verify($entetes, 'POST', $v['pathWithQuery'], $v['body']))->toBeFalse();
});

it('accepte toujours un horodatage en secondes', function (): void {
    $v = livraison();
    $secondes = (string) time();

    $checksum = hash('sha256', $v['body']);
    $canonique = $secondes."\nPOST\n".$v['pathWithQuery']."\n".$checksum;

    $entetes = [
        'x-timestamp' => $secondes,
        'x-signature' => hash_hmac('sha256', $canonique, $v['secret']),
    ];

    // L'unité n'est documentée nulle part : les deux doivent passer.
    expect((new HmacSignatureVerifier($v['secret'], 300))
        ->verify($entetes, 'POST', $v['pathWithQuery'], $v['body']))->toBeTrue();
});

it('porte la clé de déduplication dans un en-tête dédié', function (): void {
    $entetes = livraison()['headers'];

    expect($entetes)->toHaveKey('x-idempotency-key')
        ->and($entetes['x-idempotency-key'])->toMatch('/^[0-9a-f-]{36}$/');
});

it('porte l\'adresse électronique du destinataire dans un en-tête', function (): void {
    $entetes = livraison()['headers'];

    expect($entetes['x-target-electronic-address'])->toMatch('/^\d{4}:\d+$/');
});
