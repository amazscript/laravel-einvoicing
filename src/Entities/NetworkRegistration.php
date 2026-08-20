<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Entities;

use DateTimeImmutable;

/**
 * A directory entry making an identifier addressable on an invoicing network.
 *
 * This is what makes a company reachable: without it, an invoice addressed to
 * the company is rejected at the sender with "No route found for given key".
 *
 * Fields mirror what the platform actually returns — nothing is assumed.
 */
final readonly class NetworkRegistration
{
    public function __construct(
        public string $directoryId,
        /** Network-level electronic address, e.g. "0225:902695436". */
        public string $directoryAddress,
        /** Network this entry belongs to, e.g. "DOMESTIC_FR". */
        public ?string $networkIdentifier,
        public ?DateTimeImmutable $validFrom,
        /** True when the company invoices itself through this entry. */
        public bool $isSelfBilling = false,
    ) {}

    /**
     * Whether the entry is in force at the given moment.
     *
     * A registration filed today for next month is real but not yet usable, so
     * a future start date does not make the company reachable.
     */
    public function isActiveAt(DateTimeImmutable $moment): bool
    {
        if ($this->directoryAddress === '') {
            return false;
        }

        return $this->validFrom === null || $this->validFrom <= $moment;
    }
}
