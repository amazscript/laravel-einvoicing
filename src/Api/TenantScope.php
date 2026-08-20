<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Api;

use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use AmazScript\Einvoicing\Contracts\OutboundInvoiceGateway;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\OutboundInvoice;
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
        private readonly ?OutboundInvoiceGateway $sender = null,
    ) {}

    /**
     * The invoices this tenant has sent, and what became of them.
     */
    public function sent(): OutboundInvoiceQuery
    {
        if (! $this->tenant instanceof Tenant) {
            throw new RuntimeException('Reading sent invoices requires a tenant: use Einvoicing::for($tenant)->sent().');
        }

        return new OutboundInvoiceQuery($this->tenant);
    }

    /**
     * Sends an invoice document the application has already produced.
     *
     * The package never builds the document itself — see InvoiceSender. Sending
     * the same file twice returns the first send rather than issuing a second
     * invoice, which the platform gives no idempotency key to prevent.
     */
    public function send(string $filePath): OutboundInvoice
    {
        if (! $this->tenant instanceof Tenant) {
            // An invoice leaves in someone's name; there is no default sender.
            throw new RuntimeException('Sending an invoice requires a tenant: use Einvoicing::for($tenant)->send(...).');
        }

        if (! $this->sender instanceof OutboundInvoiceGateway) {
            throw new RuntimeException('No outbound gateway is configured for this driver.');
        }

        return (new InvoiceSender($this->tenant, $this->sender))->send($filePath);
    }

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
