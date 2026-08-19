<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Webhook;

use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Jobs\ProcessInboundInvoice;
use AmazScript\Einvoicing\Jobs\ProcessStatusUpdate;
use AmazScript\Einvoicing\Models\WebhookEvent;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Met en file le traitement d'un événement encaissé.
 *
 * Le contrôleur ne traite rien lui-même : il doit rendre la main sous quelques
 * dizaines de millisecondes, sans quoi la plateforme considère la livraison en
 * échec et la rejoue.
 */
final class InboundEventDispatcher
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function dispatch(WebhookEvent $event): void
    {
        // Un événement non routé n'est pas traitable : on ignore à qui il
        // appartient. Il reste en base, en attente que le tenant soit créé,
        // puis sera rejoué. Le traiter maintenant produirait une donnée
        // rattachée à personne.
        if ($event->status !== WebhookEventStatus::Received) {
            return;
        }

        $job = match ($event->event_type) {
            'INVOICE_STATUS' => new ProcessStatusUpdate($event->id),
            'INVOICE_INBOUND' => new ProcessInboundInvoice($event->id),
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

        // La mise en file attend la validation de la transaction : sans cela, un
        // rollback laisserait un job courir après une ligne qui n'existe pas.
        $pending->afterCommit();
    }
}
