<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Contracts;

use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Tenancy\RoutingKeys;

/**
 * Finds the tenant an inbound delivery belongs to.
 *
 * The platform accepts a single callback URL for the whole estate, so routing
 * is entirely up to the package.
 *
 * Returning null is not an error but a routing failure: the caller must keep the
 * delivery rather than drop it or answer 5xx.
 */
interface TenantResolver
{
    public function resolve(RoutingKeys $keys): ?Tenant;
}
