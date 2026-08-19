<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Events;

use AmazScript\Einvoicing\Models\WebhookEvent;

/**
 * Une facture entrante a été refusée par la plateforme.
 *
 * Elle n'entrera jamais en comptabilité : c'est au fournisseur de la corriger et
 * de la réémettre. L'événement porte les erreurs de validation pour que
 * l'application puisse alerter qui de droit plutôt que d'attendre une facture
 * qui ne viendra pas.
 */
final class InboundInvoiceInvalid
{
    /**
     * @param  list<array{code: string|null, message: string|null}>  $validationErrors
     */
    public function __construct(
        public readonly WebhookEvent $event,
        public readonly ?string $providerInvoiceId,
        public readonly ?string $invoiceNumber,
        public readonly array $validationErrors,
    ) {}
}
