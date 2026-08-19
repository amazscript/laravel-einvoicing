<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Events;

use AmazScript\Einvoicing\Models\WebhookEvent;

/**
 * An issued invoice could not be delivered to its recipient.
 *
 * Observed for real: a recipient missing from the directory produces a REJECTED
 * status carrying ROUTING_FAILURE.
 *
 * Issuing invoices belongs to v0.2; the event is exposed now so applications
 * that issue by other means can watch delivery failures without waiting.
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
