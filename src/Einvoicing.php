<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing;

use AmazScript\Einvoicing\Api\DirectoryQuery;
use AmazScript\Einvoicing\Api\EntityQuery;
use AmazScript\Einvoicing\Api\TenantScope;
use AmazScript\Einvoicing\Contracts\BusinessEntityGateway;
use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use AmazScript\Einvoicing\Drivers\Iopole\Client;
use AmazScript\Einvoicing\Drivers\Iopole\IopoleInvoiceGateway;
use AmazScript\Einvoicing\Drivers\Iopole\IopoleOutboundInvoiceGateway;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Storage\InvoiceFileStore;

/**
 * The package's entry point for the host application.
 *
 * This is the only public surface: everything else — jobs, webhook, driver — is
 * internal plumbing, liable to change without notice.
 */
final class Einvoicing
{
    public function __construct(
        private readonly Client $client,
        private readonly InvoiceGateway $gateway,
        private readonly BusinessEntityGateway $entities,
        private readonly InvoiceFileStore $store,
    ) {}

    /**
     * Acts on behalf of one tenant, under its own customer-id.
     */
    public function for(Tenant $tenant): TenantScope
    {
        return new TenantScope($tenant, $this->gatewayFor($tenant), $this->store, $this->senderFor($tenant));
    }

    /**
     * Acts without tenant distinction, under the default customer-id.
     */
    public function operator(): TenantScope
    {
        return new TenantScope(null, $this->gateway, $this->store, new IopoleOutboundInvoiceGateway($this->client));
    }

    public function directory(): DirectoryQuery
    {
        return new DirectoryQuery($this->gateway);
    }

    /**
     * The companies declared on the platform, and whether they are reachable.
     */
    public function entities(): EntityQuery
    {
        return new EntityQuery($this->entities);
    }

    /**
     * A gateway bound to the tenant's customer-id: without it, every call would
     * go out under the operator's identity.
     */
    private function gatewayFor(Tenant $tenant): InvoiceGateway
    {
        return new IopoleInvoiceGateway($this->client->forCustomer($tenant->customer_id));
    }

    /**
     * Same reasoning for sending: an invoice issued under the wrong customer-id
     * leaves in someone else's name.
     */
    private function senderFor(Tenant $tenant): IopoleOutboundInvoiceGateway
    {
        return new IopoleOutboundInvoiceGateway($this->client->forCustomer($tenant->customer_id));
    }
}
