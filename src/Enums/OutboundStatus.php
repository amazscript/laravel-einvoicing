<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Enums;

/**
 * Where a sent invoice stands with the platform.
 *
 * Deliberately coarse: the platform's own lifecycle codes are open-ended and
 * already recorded verbatim as statuses. This says only whether the document
 * left, and whether it was taken.
 */
enum OutboundStatus: string
{
    /** Written down, not yet handed to the platform. */
    case Pending = 'PENDING';

    /** Accepted by the platform, which named it. */
    case Sent = 'SENT';

    /** Refused. The reason is kept alongside. */
    case Failed = 'FAILED';

    public function isFinal(): bool
    {
        return $this === self::Sent;
    }
}
