<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Events;

use AmazScript\Einvoicing\Models\OutboundInvoice;

/**
 * The platform has taken an invoice and named it.
 *
 * Taken is not delivered: what happens next arrives as lifecycle statuses. This
 * is the point at which the document stops being the application's problem.
 */
final class OutboundInvoiceSent
{
    public function __construct(
        public readonly OutboundInvoice $invoice,
    ) {}
}
