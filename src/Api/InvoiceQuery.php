<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Api;

use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * A tenant's invoices, as seen from here or as seen by the platform.
 *
 * The distinction is explicit: `local()` queries the package's own database,
 * `remoteNotSeen()` asks the platform. A single method doing sometimes one and
 * sometimes the other would be a costly source of mistakes.
 */
final class InvoiceQuery
{
    public function __construct(
        private readonly ?Tenant $tenant,
        private readonly InvoiceGateway $gateway,
    ) {}

    /**
     * Invoices already received and recorded by the package.
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
     * Invoices the platform considers unacknowledged.
     *
     * @return list<array<string, mixed>>
     */
    public function remoteNotSeen(): array
    {
        return $this->gateway->notSeen();
    }

    /**
     * Statuses unacknowledged on the platform's side.
     *
     * @return list<array<string, mixed>>
     */
    public function remoteStatusesNotSeen(): array
    {
        return $this->gateway->statusesNotSeen();
    }

    /**
     * Searches invoices on the platform.
     *
     * Takes the filter syntax as-is:
     *
     *     search('invoice.direction:"INBOUND" AND invoice.state:"NOT_DELIVERED"')
     *
     * or criteria, joined by "AND":
     *
     *     search(['invoice.direction' => 'INBOUND', 'invoice.state' => 'NOT_DELIVERED'])
     *
     * The walk is lazy: the platform paginates, and nothing justifies loading
     * everything to read only the first few.
     *
     * Each result carries a `metadata` object. To get more in the same response
     * rather than one call per invoice:
     *
     *     search([...], expand: ['businessData', 'lastStatusData'])
     *
     * @param  string|array<string, string>  $criteria
     * @param  list<string>  $expand
     * @return LazyCollection<int, array<mixed>>
     */
    public function search(string|array $criteria, array $expand = []): LazyCollection
    {
        return $this->gateway->searchInvoices(
            is_array($criteria) ? $this->buildQuery($criteria) : $criteria,
            $expand,
        );
    }

    /**
     * Assembles criteria into a query.
     *
     * Quotes and backslashes are **stripped** from values rather than escaped:
     * the search engine's escaping syntax is undocumented, and an escape it did
     * not interpret would let outside input close the clause and rewrite what is
     * being searched. A legitimate value contains none.
     *
     * Anyone needing a finer query passes it as a string.
     *
     * @param  array<string, string>  $criteria
     */
    private function buildQuery(array $criteria): string
    {
        $clauses = [];

        foreach ($criteria as $champ => $valeur) {
            $clauses[] = sprintf('%s:"%s"', $champ, str_replace(['"', '\\'], '', $valeur));
        }

        return implode(' AND ', $clauses);
    }
}
