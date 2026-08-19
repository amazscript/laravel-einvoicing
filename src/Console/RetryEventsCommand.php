<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Console;

use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Models\WebhookEvent;
use AmazScript\Einvoicing\Webhook\InboundEventDispatcher;
use Illuminate\Console\Command;

/**
 * Remet en traitement les événements restés de côté.
 *
 * Sans cette commande, un événement non routé — le tenant n'existait pas encore —
 * ou dont le traitement a échoué resterait invisible pour toujours, alors qu'il
 * porte une facture bien réelle.
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

        // On ne retient que les identifiants, puis on recharge un par un : le
        // lot est borné par --limit, et le modèle reste typé de bout en bout.
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
            // pluck rend des valeurs non typées ; on écarte tout ce qui n'est pas
            // un identifiant, faute de quoi find() pourrait rendre une collection.
            if (! is_string($identifiant)) {
                continue;
            }

            $evenement = WebhookEvent::query()->find($identifiant);

            if ($evenement === null) {
                continue;
            }

            // Repasser par RECEIVED donne au routage une nouvelle chance : le
            // tenant manquant a pu être créé entre-temps.
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
