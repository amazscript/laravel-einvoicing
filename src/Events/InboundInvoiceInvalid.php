<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Events;

use AmazScript\Einvoicing\Models\WebhookEvent;

/**
 * An inbound invoice was refused by the platform.
 *
 * It will never reach the books: the supplier has to correct and reissue it.
 * The event carries the validation errors so the application can warn whoever
 * needs to know, instead of waiting for an invoice that is not coming.
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
