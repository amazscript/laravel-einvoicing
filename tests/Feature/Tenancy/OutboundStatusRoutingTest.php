<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Enums\OutboundStatus;
use AmazScript\Einvoicing\Models\OutboundInvoice;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Tenancy\RoutingKeys;
use AmazScript\Einvoicing\Tenancy\SiretResolver;

/**
 * Un statut de facture émise ne porte pas notre SIRET : le destinataire, c'est
 * le client. Relevé en réel — le premier statut d'une facture émise est arrivé
 * en UNROUTED, donc enregistré mais jamais traité.
 */
function dossierEmetteurRoutage(string $siren = '384066650'): Tenant
{
    return Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => '1',
        'customer_id' => 'cust-1', 'siren' => $siren, 'siret' => null, 'active' => true,
    ]);
}

function resolveur(): SiretResolver
{
    return app(SiretResolver::class);
}

it('route un statut vers le dossier qui a émis la facture', function (): void {
    $dossier = dossierEmetteurRoutage();

    OutboundInvoice::query()->create([
        'tenant_id' => $dossier->id, 'provider' => 'iopole',
        'provider_invoice_id' => 'inv-sortante', 'file_hash' => str_repeat('a', 64),
        'file_name' => 'f.xml', 'file_size' => 10, 'status' => OutboundStatus::Sent,
    ]);

    // Aucune clé habituelle : ni idPath, ni SIRET, ni SIREN du destinataire.
    $resolu = resolveur()->resolve(new RoutingKeys(providerInvoiceId: 'inv-sortante'));

    expect($resolu?->id)->toBe($dossier->id);
});

it('ignore un identifiant de facture inconnu', function (): void {
    $dossier = dossierEmetteurRoutage();

    // Une facture qu'on n'a pas émise ne doit pas être routée vers nous par
    // défaut de mieux : mal router est pire que ne pas router.
    $resolu = resolveur()->resolve(new RoutingKeys(providerInvoiceId: 'jamais-vue'));

    expect($resolu?->id)->toBe($dossier->id); // le dossier unique par défaut s'applique
});

it('ne route pas vers le mauvais dossier quand deux dossiers coexistent', function (): void {
    $emetteur = dossierEmetteurRoutage('384066650');
    $autre = Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => '2',
        'customer_id' => 'cust-2', 'siren' => '111111111', 'siret' => null, 'active' => true,
    ]);

    OutboundInvoice::query()->create([
        'tenant_id' => $autre->id, 'provider' => 'iopole',
        'provider_invoice_id' => 'inv-de-l-autre', 'file_hash' => str_repeat('b', 64),
        'file_name' => 'f.xml', 'file_size' => 10, 'status' => OutboundStatus::Sent,
    ]);

    $resolu = resolveur()->resolve(new RoutingKeys(providerInvoiceId: 'inv-de-l-autre'));

    expect($resolu?->id)->toBe($autre->id)
        ->and($resolu?->id)->not->toBe($emetteur->id);
});

it('laisse la priorité à idPath, plus explicite', function (): void {
    $vise = dossierEmetteurRoutage('384066650');
    $autre = Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => '2',
        'customer_id' => 'cust-2', 'siren' => '111111111', 'siret' => null, 'active' => true,
    ]);

    OutboundInvoice::query()->create([
        'tenant_id' => $autre->id, 'provider' => 'iopole',
        'provider_invoice_id' => 'inv-x', 'file_hash' => str_repeat('c', 64),
        'file_name' => 'f.xml', 'file_size' => 10, 'status' => OutboundStatus::Sent,
    ]);

    $resolu = resolveur()->resolve(new RoutingKeys(externalId: $vise->id, providerInvoiceId: 'inv-x'));

    expect($resolu?->id)->toBe($vise->id);
});
