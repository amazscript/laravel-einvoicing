<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Events;

use AmazScript\Einvoicing\Models\WebhookEvent;

/**
 * Une facture émise n'a pas pu être remise à son destinataire.
 *
 * Le cas a été observé en conditions réelles : un destinataire absent de
 * l'annuaire produit un statut REJECTED portant la raison ROUTING_FAILURE.
 *
 * L'émission relève de la v0.2 ; l'événement est exposé dès maintenant pour que
 * les applications qui émettent par ailleurs puissent surveiller les échecs de
 * remise sans attendre.
 */
final class OutboundInvoiceNotDelivered
{
    public function __construct(
        public readonly WebhookEvent $event,
        public readonly ?string $providerInvoiceId,
        public readonly ?string $reason,
        public readonly ?string $message,
    ) {}
}
