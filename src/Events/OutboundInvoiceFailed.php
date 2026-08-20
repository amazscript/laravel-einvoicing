<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Events;

use AmazScript\Einvoicing\Models\OutboundInvoice;

/**
 * The platform refused an invoice outright.
 *
 * Distinct from a rejection later in the lifecycle: nothing left. The reason is
 * on the row, and the document has to be fixed before another attempt — resending
 * the same bytes returns this same refusal.
 */
final class OutboundInvoiceFailed
{
    public function __construct(
        public readonly OutboundInvoice $invoice,
    ) {}
}
