<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Api;

use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Storage\InvoiceFileStore;
use RuntimeException;

/**
 * Everything done on behalf of one tenant.
 *
 * Every call to the platform carries that tenant's customer-id: in a
 * multi-tenant estate, the wrong header reads someone else's invoices.
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
     * @param  string  $id  the package's own identifier, or the platform's
     */
    public function invoice(string $id): InvoiceHandle
    {
        $invoice = InboundInvoice::query()->find($id)
            ?? InboundInvoice::query()->where('provider_invoice_id', $id)->first();

        if (! $invoice instanceof InboundInvoice) {
            throw new RuntimeException("Unknown invoice: {$id}");
        }

        if ($this->tenant instanceof Tenant && $invoice->tenant_id !== $this->tenant->id) {
            // Isolation barrier: an invoice belongs to one tenant, and a tenant
            // never reads another's.
            throw new RuntimeException("Invoice {$id} does not belong to this tenant.");
        }

        return new InvoiceHandle($invoice, $this->gateway, $this->store);
    }
}
