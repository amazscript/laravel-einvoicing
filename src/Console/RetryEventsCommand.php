<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Console;

use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use AmazScript\Einvoicing\Contracts\PayloadInterpreter;
use AmazScript\Einvoicing\Contracts\TenantResolver;
use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Models\WebhookEvent;
use AmazScript\Einvoicing\Tenancy\RoutingKeys;
use AmazScript\Einvoicing\Webhook\InboundEventDispatcher;
use AmazScript\Einvoicing\Webhook\InboundRequest;
use Illuminate\Console\Command;
use Throwable;

/**
 * Puts aside events back into processing.
 *
 * Without this command, an event left unrouted — the tenant did not exist yet —
 * or whose processing failed would stay invisible forever, while holding a
 * perfectly real invoice.
 */
final class RetryEventsCommand extends Command
{
    protected $signature = 'einvoicing:events:retry
                            {--status=* : UNROUTED, FAILED (les deux par défaut)}
                            {--limit=100 : Nombre maximal d\'événements repris}';

    protected $description = 'Rejoue les événements webhook non routés ou en échec';

    private InvoiceGateway $gateway;

    public function handle(
        InboundEventDispatcher $dispatcher,
        TenantResolver $resolver,
        PayloadInterpreter $interpreter,
        InvoiceGateway $gateway,
    ): int {
        $this->gateway = $gateway;

        $etats = $this->statuses();
        $limite = (int) $this->option('limit');

        // Only identifiers are collected, then reloaded one by one: the batch is
        // bounded by --limit, and the model stays typed throughout.
        $identifiants = WebhookEvent::query()
            ->whereIn('status', array_map(static fn (WebhookEventStatus $s): string => $s->value, $etats))
            ->orderBy('received_at')
            ->take($limite)
            ->pluck('id');

        if ($identifiants->isEmpty()) {
            $this->info('Aucun événement à rejouer.');

            return self::SUCCESS;
        }

        $repris = 0;
        $toujoursOrphelins = 0;

        foreach ($identifiants as $identifiant) {
            // pluck yields untyped values; anything that is not an identifier is
            // discarded, otherwise find() could return a collection.
            if (! is_string($identifiant)) {
                continue;
            }

            $evenement = WebhookEvent::query()->find($identifiant);

            if ($evenement === null) {
                continue;
            }

            // Routing is redone, not merely re-read: the tenant may have been
            // created since, and until now the retry only ever looked at the
            // tenant_id left null by the first attempt — so an unrouted event
            // could never be recovered at all.
            $evenement->forceFill([
                'status' => WebhookEventStatus::Received,
                'failed_reason' => null,
                'tenant_id' => $evenement->tenant_id ?? $this->reroute($evenement, $resolver, $interpreter),
            ])->save();

            $dispatcher->dispatch($evenement);

            $evenement->refresh()->status === WebhookEventStatus::Received
                ? $repris++
                : $toujoursOrphelins++;
        }

        $this->info("{$repris} événement(s) remis en file.");

        if ($toujoursOrphelins > 0) {
            $this->warn("{$toujoursOrphelins} événement(s) toujours sans destinataire connu.");
        }

        $total = WebhookEvent::query()
            ->whereIn('status', [WebhookEventStatus::Unrouted, WebhookEventStatus::Failed])
            ->count();

        if ($total > $limite) {
            $this->comment("{$total} événement(s) restent en attente : relancez avec --limit.");
        }

        return self::SUCCESS;
    }

    /**
     * @return list<WebhookEventStatus>
     */
    private function statuses(): array
    {
        $demandes = $this->option('status');
        $demandes = is_array($demandes) ? array_filter($demandes, 'is_string') : [];

        if ($demandes === []) {
            return [WebhookEventStatus::Unrouted, WebhookEventStatus::Failed];
        }

        $etats = [];

        foreach ($demandes as $demande) {
            $etat = WebhookEventStatus::tryFrom(strtoupper($demande));

            if ($etat !== null) {
                $etats[] = $etat;
            }
        }

        return $etats === [] ? [WebhookEventStatus::Unrouted, WebhookEventStatus::Failed] : $etats;
    }

    /**
     * Finds the tenant for a stored event, from its payload alone.
     *
     * The original headers are gone — only the body was kept — so the routing
     * keys are rebuilt from what remains. Enough for the payload-borne keys:
     * recipients, idPath, and the invoice identifier.
     */
    private function reroute(
        WebhookEvent $evenement,
        TenantResolver $resolver,
        PayloadInterpreter $interpreter,
    ): ?string {
        $payload = $evenement->payload ?? [];

        $tenant = $resolver->resolve(
            $interpreter->routingKeys(InboundRequest::fromStoredPayload($payload))
        );

        if ($tenant instanceof Tenant) {
            return $tenant->id;
        }

        return $this->rerouteFromPlatform($payload, $resolver);
    }

    /**
     * Last resort: ask the platform who the invoice was for.
     *
     * An inbound delivery carries only an identifier in its body — the
     * recipient travels in a header, which a stored event no longer has. So an
     * unrouted invoice could never be recovered, whatever tenant was created
     * afterwards.
     *
     * Deliberately here rather than in the webhook: the controller must answer
     * without calling anything, and a replay is already asynchronous.
     *
     * @param  array<string, mixed>  $payload
     */
    private function rerouteFromPlatform(array $payload, TenantResolver $resolver): ?string
    {
        $invoiceId = $payload['invoiceId'] ?? null;

        if (! is_string($invoiceId) || $invoiceId === '') {
            return null;
        }

        try {
            $metadata = $this->gateway->metadata($invoiceId);
        } catch (Throwable) {
            // A platform that is down must not turn a recoverable event into a
            // failed one: it stays unrouted, and the next run tries again.
            return null;
        }

        if ($metadata === null) {
            return null;
        }

        return $resolver->resolve(new RoutingKeys(
            siret: $metadata['recipient_siret'],
            siren: $metadata['recipient_siren'],
        ))?->id;
    }
}
