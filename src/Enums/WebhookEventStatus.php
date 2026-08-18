<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Enums;

/**
 * Cycle de vie d'un événement webhook reçu.
 *
 * Unrouted et Failed sont les deux états rejouables : ils signalent une donnée
 * conservée mais non exploitée, jamais une donnée perdue.
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
