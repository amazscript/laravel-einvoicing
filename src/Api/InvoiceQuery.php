<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Api;

use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Les factures d'un dossier, vues d'ici ou vues de la plateforme.
 *
 * La distinction est explicite : `local()` interroge la base du package,
 * `remoteNotSeen()` interroge la plateforme. Une méthode unique qui ferait
 * tantôt l'un tantôt l'autre serait une source d'erreurs coûteuses.
 */
final class InvoiceQuery
{
    public function __construct(
        private readonly ?Tenant $tenant,
        private readonly InvoiceGateway $gateway,
    ) {}

    /**
     * Factures déjà reçues et consignées par le package.
     *
     * @return Builder<InboundInvoice>
     */
    public function local(): Builder
    {
        $query = InboundInvoice::query();

        return $this->tenant instanceof Tenant
            ? $query->where('tenant_id', $this->tenant->id)
            : $query;
    }

    /**
     * Factures que la plateforme considère comme non acquittées.
     *
     * @return list<array<string, mixed>>
     */
    public function remoteNotSeen(): array
    {
        return $this->gateway->notSeen();
    }

    /**
     * Statuts non acquittés côté plateforme.
     *
     * @return list<array<string, mixed>>
     */
    public function remoteStatusesNotSeen(): array
    {
        return $this->gateway->statusesNotSeen();
    }
}
