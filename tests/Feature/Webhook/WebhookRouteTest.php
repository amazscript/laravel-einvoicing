<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Events\WebhookSignatureRejected;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;

const WEBHOOK_SECRET = 'ac4f8b1e9d2c7a6b5e3f0d8c1a9b7e6d4c2f1a0b9e8d7c6b5a4f3e2d1c0b9a88';
const WEBHOOK_PATH = 'einvoicing/webhook';

beforeEach(function (): void {
    config()->set('einvoicing.webhook.secret', WEBHOOK_SECRET);
    config()->set('einvoicing.webhook.tolerance', 300);
});

/**
 * Signe comme le fait la plateforme : checksum sur la source fournie, puis HMAC
 * sur la chaîne canonique.
 *
 * @return array<string, string>
 */
function signedHeaders(string $checksumSource, string $pathWithQuery, ?int $timestamp = null): array
{
    $timestamp ??= time();
    $checksum = hash('sha256', $checksumSource);
    $canonical = $timestamp."\nPOST\n".$pathWithQuery."\n".$checksum;

    return [
        'X-Timestamp' => (string) $timestamp,
        'X-Signature' => hash_hmac('sha256', $canonical, WEBHOOK_SECRET),
        'X-Checksum' => $checksum,
    ];
}

it('expose la route de rappel déclarée dans la configuration', function (): void {
    expect(route('einvoicing.webhook', absolute: false))->toBe('/'.WEBHOOK_PATH);
});

it('accepte un statut json correctement signé', function (): void {
    $corps = json_encode(['invoiceId' => 'inv-1', 'statusId' => 'sta-1']);
    $entetes = signedHeaders($corps, '/'.WEBHOOK_PATH);

    $reponse = $this->call('POST', '/'.WEBHOOK_PATH, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TIMESTAMP' => $entetes['X-Timestamp'],
        'HTTP_X_SIGNATURE' => $entetes['X-Signature'],
        'HTTP_X_CHECKSUM' => $entetes['X-Checksum'],
    ], $corps);

    expect($reponse->status())->toBeGreaterThanOrEqual(200)->toBeLessThan(300);
});

it('accepte une facture multipart signée sur le seul contenu du fichier', function (): void {
    $contenu = '<?xml version="1.0"?><Invoice><ID>F-2026-1</ID></Invoice>';
    $fichier = UploadedFile::fake()->createWithContent('invoice.xml', $contenu);

    // Les champs annexes accompagnent le fichier mais n'entrent pas dans la
    // signature : c'est le piège central de cette intégration.
    $entetes = signedHeaders($contenu, '/'.WEBHOOK_PATH);

    $reponse = $this->call(
        'POST',
        '/'.WEBHOOK_PATH,
        ['invoiceId' => 'inv-1', 'format' => 'FACTURX', 'direction' => 'INBOUND'],
        [],
        ['file' => $fichier],
        [
            'HTTP_X_TIMESTAMP' => $entetes['X-Timestamp'],
            'HTTP_X_SIGNATURE' => $entetes['X-Signature'],
        ],
    );

    expect($reponse->status())->toBeGreaterThanOrEqual(200)->toBeLessThan(300);
});

it('rejette un multipart signé sur le corps entier plutôt que sur le fichier', function (): void {
    $contenu = '<?xml version="1.0"?><Invoice><ID>F-2026-2</ID></Invoice>';
    $fichier = UploadedFile::fake()->createWithContent('invoice.xml', $contenu);

    $entetes = signedHeaders('invoiceId=inv-2'.$contenu, '/'.WEBHOOK_PATH);

    $reponse = $this->call('POST', '/'.WEBHOOK_PATH, ['invoiceId' => 'inv-2'], [], ['file' => $fichier], [
        'HTTP_X_TIMESTAMP' => $entetes['X-Timestamp'],
        'HTTP_X_SIGNATURE' => $entetes['X-Signature'],
    ]);

    expect($reponse->status())->toBe(401);
});

it('répond 401 et n\'écrit rien quand la signature est invalide', function (): void {
    Event::fake([WebhookSignatureRejected::class]);

    $reponse = $this->call('POST', '/'.WEBHOOK_PATH, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TIMESTAMP' => (string) time(),
        'HTTP_X_SIGNATURE' => str_repeat('0', 64),
    ], '{"invoiceId":"inv-3"}');

    expect($reponse->status())->toBe(401)
        ->and(DB::table('einvoicing_webhook_events')->count())->toBe(0)
        ->and(DB::table('einvoicing_inbound_invoices')->count())->toBe(0);

    Event::assertDispatched(WebhookSignatureRejected::class);
});

it('répond 401 quand aucune signature n\'est présentée', function (): void {
    $reponse = $this->call('POST', '/'.WEBHOOK_PATH, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], '{"invoiceId":"inv-4"}');

    expect($reponse->status())->toBe(401);
});

it('rejette une requête rejouée hors de la fenêtre de tolérance', function (): void {
    $corps = '{"invoiceId":"inv-5"}';
    $entetes = signedHeaders($corps, '/'.WEBHOOK_PATH, time() - 4000);

    $reponse = $this->call('POST', '/'.WEBHOOK_PATH, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TIMESTAMP' => $entetes['X-Timestamp'],
        'HTTP_X_SIGNATURE' => $entetes['X-Signature'],
    ], $corps);

    expect($reponse->status())->toBe(401);
});

it('refuse tout lorsque le secret n\'est pas configuré', function (): void {
    config()->set('einvoicing.webhook.secret', null);

    $corps = '{"invoiceId":"inv-6"}';
    $entetes = signedHeaders($corps, '/'.WEBHOOK_PATH);

    $reponse = $this->call('POST', '/'.WEBHOOK_PATH, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TIMESTAMP' => $entetes['X-Timestamp'],
        'HTTP_X_SIGNATURE' => $entetes['X-Signature'],
    ], $corps);

    expect($reponse->status())->toBe(401);
});

it('utilise le chemin canonique configuré quand un proxy réécrit l\'uri', function (): void {
    config()->set('einvoicing.webhook.canonical_path', '/chemin/public/webhook');

    $corps = '{"invoiceId":"inv-7"}';
    // La plateforme a signé le chemin public, pas celui vu par l'application.
    $entetes = signedHeaders($corps, '/chemin/public/webhook');

    $reponse = $this->call('POST', '/'.WEBHOOK_PATH, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TIMESTAMP' => $entetes['X-Timestamp'],
        'HTTP_X_SIGNATURE' => $entetes['X-Signature'],
    ], $corps);

    expect($reponse->status())->toBeGreaterThanOrEqual(200)->toBeLessThan(300);
});

it('signe la query string en plus du chemin', function (): void {
    $corps = '{"invoiceId":"inv-8"}';
    $entetes = signedHeaders($corps, '/'.WEBHOOK_PATH.'?type=status');

    $reponse = $this->call('POST', '/'.WEBHOOK_PATH.'?type=status', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TIMESTAMP' => $entetes['X-Timestamp'],
        'HTTP_X_SIGNATURE' => $entetes['X-Signature'],
    ], $corps);

    expect($reponse->status())->toBeGreaterThanOrEqual(200)->toBeLessThan(300);
});

it('ne renvoie jamais 5xx sur un payload incompréhensible', function (): void {
    $corps = 'ceci n\'est pas du json';
    $entetes = signedHeaders($corps, '/'.WEBHOOK_PATH);

    $reponse = $this->call('POST', '/'.WEBHOOK_PATH, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TIMESTAMP' => $entetes['X-Timestamp'],
        'HTTP_X_SIGNATURE' => $entetes['X-Signature'],
    ], $corps);

    // La plateforme relancerait indéfiniment sur un 5xx : on encaisse.
    expect($reponse->status())->toBeLessThan(500);
});
