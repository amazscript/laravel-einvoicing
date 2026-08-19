<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing;

use AmazScript\Einvoicing\Api\DirectoryQuery;
use AmazScript\Einvoicing\Api\TenantScope;
use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use AmazScript\Einvoicing\Drivers\Iopole\Client;
use AmazScript\Einvoicing\Drivers\Iopole\IopoleInvoiceGateway;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Storage\InvoiceFileStore;

/**
 * Point d'entrée du package pour l'application hôte.
 *
 * C'est la seule surface publique : tout le reste — jobs, webhook, driver — est
 * de la plomberie interne, susceptible d'évoluer sans préavis.
 */
final class Einvoicing
{
    public function __construct(
        private readonly Client $client,
        private readonly InvoiceGateway $gateway,
        private readonly InvoiceFileStore $store,
    ) {}

    /**
     * Agit au nom d'un dossier, avec son propre customer-id.
     */
    public function for(Tenant $tenant): TenantScope
    {
        return new TenantScope($tenant, $this->gatewayFor($tenant), $this->store);
    }

    /**
     * Agit sans distinction de dossier, avec le customer-id par défaut.
     */
    public function operator(): TenantScope
    {
        return new TenantScope(null, $this->gateway, $this->store);
    }

    public function directory(): DirectoryQuery
    {
        return new DirectoryQuery($this->gateway);
    }

    /**
     * Une passerelle liée au customer-id du dossier : sans cela, tous les appels
     * partiraient sous l'identité de l'opérateur.
     */
    private function gatewayFor(Tenant $tenant): InvoiceGateway
    {
        return new IopoleInvoiceGateway($this->client->forCustomer($tenant->customer_id));
    }
}
