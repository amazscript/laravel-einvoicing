<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Drivers\Iopole\Endpoints;

/**
 * Test de contrat : chaque chemin construit par le package doit exister dans la
 * spécification publiée par la plateforme. Une rupture d'API se voit ici, et non
 * en production sur un 404.
 */
function specPaths(): array
{
    $spec = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/iopole-endpoints.json'), true);

    return array_map(fn (array $e): string => $e['path'], $spec['endpoints']);
}

it('ne construit que des chemins connus de la plateforme', function (string $built, string $expected): void {
    expect($built)->toBe($expected)
        ->and(specPaths())->toContain($expected);
})->with([
    'customer id' => [Endpoints::customerId(), '/v1/config/customer/id'],
    'liste des webhooks' => [Endpoints::webhooks(), '/v1/config/webhook'],
    'stratégie de retry' => [Endpoints::retryStrategy(), '/v1/config/retry/strategy'],
    'factures non vues' => [Endpoints::invoicesNotSeen(), '/v1/invoice/notSeen'],
    'statuts non vus' => [Endpoints::statusesNotSeen(), '/v1/invoice/status/notSeen'],
    'annuaire français' => [Endpoints::directoryFrenchSearch(), '/v1/directory/french'],
]);

it('substitue les identifiants dans les chemins paramétrés', function (string $built, string $template): void {
    expect(specPaths())->toContain($template);
    expect($built)->not->toContain('{');
})->with([
    'webhook' => [Endpoints::webhook('wh-1'), '/v1/config/webhook/{webhookId}'],
    'facture' => [Endpoints::invoice('inv-1'), '/v1/invoice/{invoiceId}'],
    'marquer facture vue' => [Endpoints::markInvoiceAsSeen('inv-1'), '/v1/invoice/{invoiceId}/markAsSeen'],
    'marquer statut vu' => [Endpoints::markStatusAsSeen('sta-1'), '/v1/invoice/status/{statusId}/markAsSeen'],
    'téléchargement' => [Endpoints::downloadInvoice('inv-1'), '/v1/invoice/{invoiceId}/download'],
    'lisible' => [Endpoints::downloadReadableInvoice('inv-1'), '/v1/invoice/{invoiceId}/download/readable'],
    'fichiers' => [Endpoints::invoiceFiles('inv-1'), '/v1/invoice/{invoiceId}/files'],
    'pièces jointes' => [Endpoints::invoiceAttachments('inv-1'), '/v1/invoice/{invoiceId}/files/attachments'],
    'fichier' => [Endpoints::downloadFile('f-1'), '/v1/invoice/file/{fileId}/download'],
]);

it('échappe un identifiant hostile au lieu de le concaténer tel quel', function (): void {
    expect(Endpoints::invoice('../../v1/config/customer/id'))
        ->toBe('/v1/invoice/..%2F..%2Fv1%2Fconfig%2Fcustomer%2Fid');
});
