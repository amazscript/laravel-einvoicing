<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Events;

use AmazScript\Einvoicing\Models\Status;

/**
 * A lifecycle status has been received and recorded.
 *
 * The invoice it refers to may be unknown to the package: a status sometimes
 * arrives before its invoice, or concerns a document the package never saw. It
 * is then kept unlinked.
 */
final class InvoiceStatusUpdated
{
    public function __construct(
        public readonly Status $status,
    ) {}
}
