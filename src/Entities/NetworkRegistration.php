<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Entities;

/**
 * A company identifier's registration on an exchange network.
 *
 * Being registered is not the same as being reachable. An entry can exist with
 * no serving platform attached, in which case the directory knows the company
 * but cannot say where to deliver — the platform then rejects the invoice with
 * "No route found". That distinction is the whole point of this object.
 */
final class NetworkRegistration
{
    public function __construct(
        public readonly string $network,
        public readonly ?string $status,
        public readonly ?string $validFrom,
        public readonly ?string $validTo,
        public readonly ?string $platformName,
        public readonly ?string $directoryId,
    ) {}

    /**
     * Whether an invoice addressed here can actually be delivered.
     *
     * A registration without a serving platform is an address nobody collects
     * from. Observed for real: entries carrying a directoryId and a validFrom,
     * yet no platform, whose invoices were refused.
     */
    public function isReachable(): bool
    {
        return $this->platformName !== null && $this->platformName !== '';
    }
}
