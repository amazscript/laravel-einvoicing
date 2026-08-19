<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Webhook;

use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Jobs\ProcessInboundInvoice;
use AmazScript\Einvoicing\Jobs\ProcessPlatformEvent;
use AmazScript\Einvoicing\Jobs\ProcessStatusUpdate;
use AmazScript\Einvoicing\Models\WebhookEvent;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Queues the processing of a banked event.
 *
 * The controller processes nothing itself: it has to return within a few tens of
 * milliseconds, otherwise the platform treats the delivery as failed and
 * replays it.
 */
final class InboundEventDispatcher
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function dispatch(WebhookEvent $event): void
    {
        // An unrouted event cannot be processed: we do not know whose it is. It
        // stays in the database until the tenant exists, then gets replayed.
        // Processing it now would produce data attached to nobody.
        if ($event->status !== WebhookEventStatus::Received) {
            return;
        }

        // An event requeued without a tenant falls back to UNROUTED: the retry
        // must observe that rather than believe it handled it.
        if ($event->tenant_id === null) {
            $event->forceFill(['status' => WebhookEventStatus::Unrouted])->save();

            return;
        }

        $job = match ($event->event_type) {
            'INVOICE_STATUS' => new ProcessStatusUpdate($event->id),
            'INVOICE_INBOUND' => new ProcessInboundInvoice($event->id),
            'INVOICE_INBOUND_INVALID' => new ProcessPlatformEvent($event->id),
            default => null,
        };

        if ($job === null) {
            return;
        }

        $connexion = $this->config->get('einvoicing.queue.connection');
        $file = $this->config->get('einvoicing.queue.name');

        $pending = dispatch($job)
            ->onConnection(is_string($connexion) && $connexion !== '' ? $connexion : null)
            ->onQueue(is_string($file) && $file !== '' ? $file : null);

        // Queueing waits for the transaction to commit: otherwise a rollback
        // would leave a job chasing a row that does not exist.
        $pending->afterCommit();
    }
}
