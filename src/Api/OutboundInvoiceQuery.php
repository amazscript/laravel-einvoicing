<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Api;

use AmazScript\Einvoicing\Enums\OutboundStatus;
use AmazScript\Einvoicing\Models\OutboundInvoice;
use AmazScript\Einvoicing\Models\Status;
use AmazScript\Einvoicing\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The invoices a tenant has sent, and what became of them.
 *
 * Reads only what is in the database: sending records everything as it happens,
 * so there is nothing to fetch back from the platform.
 */
final class OutboundInvoiceQuery
{
    public function __construct(
        private readonly Tenant $tenant,
    ) {}

    /**
     * @return Collection<int, OutboundInvoice>
     */
    public function get(): Collection
    {
        return $this->base()->with('statuses')->latest()->get();
    }

    /**
     * Those the platform refused outright — nothing left.
     *
     * @return Collection<int, OutboundInvoice>
     */
    public function failed(): Collection
    {
        return $this->base()->where('status', OutboundStatus::Failed->value)->get();
    }

    /**
     * Those it took but never reported delivered.
     *
     * Distinct from a refusal: the document left, and its silence is what makes
     * it worth looking at.
     *
     * @return Collection<int, OutboundInvoice>
     */
    public function awaitingDelivery(): Collection
    {
        return $this->base()
            ->where('status', OutboundStatus::Sent->value)
            ->whereDoesntHave('statuses')
            ->get();
    }

    /**
     * Those the platform reported it will not deliver — bad routing or a
     * document it refused.
     *
     * @return Collection<int, OutboundInvoice>
     */
    public function rejected(): Collection
    {
        $rejetees = Status::query()
            ->whereIn('code', OutboundInvoice::FAILURE_CODES)
            ->whereNotNull('outbound_invoice_id')
            ->pluck('outbound_invoice_id')
            ->all();

        $requete = $this->base()->with('statuses');

        // Appelé sans réassigner : whereIn passe par le query builder, dont la
        // valeur de retour n'est plus typée Eloquent.
        $requete->whereIn('id', $rejetees);

        return $requete->get();
    }

    /**
     * @param  string  $id  the package's own identifier, or the platform's
     */
    public function find(string $id): ?OutboundInvoice
    {
        return $this->base()->where('id', $id)->first()
            ?? $this->base()->where('provider_invoice_id', $id)->first();
    }

    /**
     * @return Builder<OutboundInvoice>
     */
    private function base(): Builder
    {
        // Scoped to the tenant from the outset: an invoice belongs to one, and
        // a tenant never reads another's.
        return OutboundInvoice::query()->where('tenant_id', $this->tenant->id);
    }
}
