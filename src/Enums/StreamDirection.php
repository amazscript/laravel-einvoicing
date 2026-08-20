<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Enums;

/**
 * Which way invoices travel, from the account's point of view.
 *
 * Used to narrow what an operator relation covers: an entity can be claimed for
 * what it receives, for what it sends, or for both when the filter is omitted.
 */
enum StreamDirection: string
{
    /** Invoices coming in — the entity as a buyer. */
    case Inbound = 'INBOUND';

    /** Invoices going out — the entity as a supplier. */
    case Outbound = 'OUTBOUND';
}
