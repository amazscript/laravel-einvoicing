<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Contracts;

use AmazScript\Einvoicing\Tenancy\RoutingKeys;
use AmazScript\Einvoicing\Webhook\InboundRequest;

/**
 * Reads an inbound delivery according to the conventions of the platform that
 * sent it.
 *
 * Every platform names its headers and shapes its payloads differently. All of
 * that lives behind this contract, so the webhook, the tenancy and the models
 * never have to know about any of it.
 */
interface PayloadInterpreter
{
    /**
     * A key identifying the delivery uniquely and stably.
     *
     * Two deliveries of the same thing must yield the same key, otherwise
     * deduplication is pointless.
     */
    public function idempotencyKey(InboundRequest $request): string;

    /**
     * What kind of delivery this is, as it will be recorded.
     */
    public function eventType(InboundRequest $request): string;

    /**
     * The keys allowing the recipient tenant to be found.
     */
    public function routingKeys(InboundRequest $request): RoutingKeys;
}
