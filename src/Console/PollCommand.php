<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Console;

use AmazScript\Einvoicing\Einvoicing;
use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Models\WebhookEvent;
use AmazScript\Einvoicing\Webhook\InboundEventDispatcher;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Picks up whatever the webhooks missed.
 *
 * A webhook can go missing: a network cut, a stopped application, a closed
 * tunnel. This command asks the platform what it has not seen acknowledged and
 * feeds the difference back through the same path as deliveries, deduplication
 * included — an invoice already received by webhook is not handled twice.
 */
final class PollCommand extends Command
{
    protected $signature = 'einvoicing:poll
                            {--tenant= : SIREN, SIRET ou identifiant d\'un dossier précis}
                            {--dry-run : Montre ce qui serait repris, sans rien écrire}';

    protected $description = 'Récupère les factures et statuts non acquittés (repli sur les webhooks)';

    public function handle(Einvoicing $einvoicing, InboundEventDispatcher $dispatcher): int
    {
        $tenants = $this->tenants();

        if ($tenants->isEmpty()) {
            $this->error('Aucun dossier actif à interroger.');

            return self::FAILURE;
        }

        $reprises = 0;

        foreach ($tenants as $tenant) {
            $passerelle = $einvoicing->for($tenant)->invoices();

            $reprises += $this->ingest(
                $passerelle->remoteNotSeen(),
                'INVOICE_INBOUND',
                $tenant,
                $dispatcher,
            );

            $reprises += $this->ingest(
                $passerelle->remoteStatusesNotSeen(),
                'INVOICE_STATUS',
                $tenant,
                $dispatcher,
            );
        }

        $this->info($this->option('dry-run')
            ? "{$reprises} élément(s) seraient repris."
            : "{$reprises} élément(s) repris.");

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     */
    private function ingest(array $elements, string $type, Tenant $tenant, InboundEventDispatcher $dispatcher): int
    {
        $nouveaux = 0;

        foreach ($elements as $element) {
            $cle = $this->key($element, $type);

            if ($cle === null) {
                continue;
            }

            if ($this->alreadyKnown($cle, $element, $type)) {
                continue;
            }

            if ($this->option('dry-run')) {
                $nouveaux++;

                continue;
            }

            try {
                $evenement = WebhookEvent::query()->create([
                    'event_id' => $cle,
                    'event_type' => $type,
                    'tenant_id' => $tenant->id,
                    'status' => WebhookEventStatus::Received,
                    'payload' => $element,
                    'received_at' => Carbon::now(),
                ]);
            } catch (QueryException) {
                // Already received by webhook: precisely what the key is for.
                continue;
            }

            $dispatcher->dispatch($evenement);
            $nouveaux++;
        }

        return $nouveaux;
    }

    /**
     * Something already received by webhook must not be reintroduced by the poll.
     *
     * The difficulty: a webhook keys itself on the idempotency key the platform
     * supplies, while the poll only knows the business identifier. The two keys
     * differ for the same object, so stored payloads are inspected as well.
     *
     * This check is application-level, unlike the webhook's which relies on the
     * database. That is acceptable here: the poll is a command nobody runs
     * against itself.
     *
     * @param  array<string, mixed>  $element
     */
    private function alreadyKnown(string $cle, array $element, string $type): bool
    {
        if (WebhookEvent::query()->where('event_id', $cle)->exists()) {
            return true;
        }

        $champ = $type === 'INVOICE_STATUS' ? 'statusId' : 'invoiceId';
        $valeur = $element[$champ] ?? null;

        if (! is_string($valeur) || $valeur === '') {
            return false;
        }

        return WebhookEvent::query()->where('payload->'.$champ, $valeur)->exists();
    }

    /**
     * The key must coincide with the one a webhook carrying the same object would
     * produce, otherwise the poll would duplicate what already arrived.
     *
     * @param  array<string, mixed>  $element
     */
    private function key(array $element, string $type): ?string
    {
        $champ = $type === 'INVOICE_STATUS' ? 'statusId' : 'invoiceId';
        $valeur = $element[$champ] ?? null;

        return is_string($valeur) && $valeur !== '' ? strtolower($champ).':'.$valeur : null;
    }

    /**
     * @return Collection<int, Tenant>
     */
    private function tenants(): Collection
    {
        $filtre = $this->option('tenant');

        $query = Tenant::query()->where('active', true);

        if (is_string($filtre) && $filtre !== '') {
            $query->where(function ($q) use ($filtre): void {
                $q->where('id', $filtre)->orWhere('siren', $filtre)->orWhere('siret', $filtre);
            });
        }

        return $query->get();
    }
}
