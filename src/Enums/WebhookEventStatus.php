<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Enums;

/**
 * Lifecycle of a received webhook delivery.
 *
 * Unrouted and Failed are the two replayable states: they mark data that was
 * kept but never acted upon — never data that was lost.
 */
enum WebhookEventStatus: string
{
    case Received = 'RECEIVED';
    case Processed = 'PROCESSED';
    case Unrouted = 'UNROUTED';
    case Failed = 'FAILED';

    public function isRetryable(): bool
    {
        return $this === self::Unrouted || $this === self::Failed;
    }
}
