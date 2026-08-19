<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Events;

/**
 * A request reached the callback endpoint with an invalid signature.
 *
 * Worth watching closely: under normal operation this never happens. A sudden
 * run of them means either a secret rotation that did not propagate, or an
 * attempt to inject forged invoices.
 */
final class WebhookSignatureRejected
{
    public function __construct(
        public readonly string $reason,
        public readonly string $ip,
    ) {}
}
