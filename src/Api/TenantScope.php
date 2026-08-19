<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Api;

use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Storage\InvoiceFileStore;
use RuntimeException;

/**
 * Tout ce qui se fait au nom d'un dossier.
 *
 * Chaque appel à la plateforme porte le customer-id du tenant : dans un parc
 * multi-tenant, se tromper d'en-tête reviendrait à lire les factures d'un autre.
 */
final class TenantScope
{
    public function __construct(
        private readonly ?Tenant $tenant,
        private readonly InvoiceGateway $gateway,
        private readonly InvoiceFileStore $store,
    ) {}

    public function invoices(): InvoiceQuery
    {
        return new InvoiceQuery($this->tenant, $this->gateway);
    }

    /**
     * @param  string  $id  identifiant du package, ou identifiant côté plateforme
     */
    public function invoice(string $id): InvoiceHandle
    {
        $invoice = InboundInvoice::query()->find($id)
            ?? InboundInvoice::query()->where('provider_invoice_id', $id)->first();

        if (! $invoice instanceof InboundInvoice) {
            throw new RuntimeException("Facture inconnue : {$id}");
        }

        if ($this->tenant instanceof Tenant && $invoice->tenant_id !== $this->tenant->id) {
            // Barrière de cloisonnement : une facture appartient à un dossier,
            // et un dossier ne lit jamais celles d'un autre.
            throw new RuntimeException("La facture {$id} n'appartient pas à ce tenant.");
        }

        return new InvoiceHandle($invoice, $this->gateway, $this->store);
    }
}
