<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Console;

use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Models\WebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Prunes the deduplication table.
 *
 * Removes only what was processed: an unrouted or failed event still holds data
 * to recover, and deleting it would lose an invoice for good.
 */
final class PruneEventsCommand extends Command
{
    protected $signature = 'einvoicing:events:prune
                            {--days= : Ancienneté minimale, en jours}
                            {--dry-run : Compte sans supprimer}';

    protected $description = 'Purge les événements webhook déjà traités';

    public function handle(): int
    {
        $jours = $this->option('days');
        $jours = is_numeric($jours)
            ? (int) $jours
            : (int) config('einvoicing.events.retention_days', 90);

        $limite = Carbon::now()->subDays($jours);

        $query = WebhookEvent::query()
            ->where('status', WebhookEventStatus::Processed)
            ->where('received_at', '<', $limite);

        $nombre = $query->count();

        if ($this->option('dry-run')) {
            $this->info("{$nombre} événement(s) traité(s) antérieur(s) au {$limite->toDateString()} seraient supprimés.");

            return self::SUCCESS;
        }

        $query->delete();

        $this->info("{$nombre} événement(s) supprimé(s), antérieurs au {$limite->toDateString()}.");

        $restants = WebhookEvent::query()
            ->whereIn('status', [WebhookEventStatus::Unrouted, WebhookEventStatus::Failed])
            ->count();

        if ($restants > 0) {
            $this->warn("{$restants} événement(s) non traité(s) conservé(s) : voir einvoicing:events:retry.");
        }

        return self::SUCCESS;
    }
}
