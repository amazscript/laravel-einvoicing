<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Console;

use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Models\WebhookEvent;
use AmazScript\Einvoicing\Webhook\InboundEventDispatcher;
use Illuminate\Console\Command;

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

    public function handle(InboundEventDispatcher $dispatcher): int
    {
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

            // Going back through RECEIVED gives routing another chance: the
            // missing tenant may have been created since.
            $evenement->forceFill([
                'status' => WebhookEventStatus::Received,
                'failed_reason' => null,
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
}
