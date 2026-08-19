<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Events;

use AmazScript\Einvoicing\Models\InboundInvoice;

/**
 * A supplier invoice has arrived and been recorded.
 *
 * This is the event a host application listens to in order to bring the invoice
 * into its own bookkeeping.
 */
final class InboundInvoiceReceived
{
    public function __construct(
        public readonly InboundInvoice $invoice,
    ) {}
}
