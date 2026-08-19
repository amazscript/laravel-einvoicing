<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Events;

use AmazScript\Einvoicing\Tenancy\RoutingKeys;

/**
 * No tenant could be matched to an inbound delivery.
 *
 * Worth watching: an unrouted delivery is data that is kept but unused, so an
 * invoice that will never reach the books until someone intervenes.
 */
final class TenantResolutionFailed
{
    public function __construct(
        public readonly RoutingKeys $keys,
        public readonly string $reason,
    ) {}
}
