<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Entities;

use DateTimeImmutable;

/**
 * A company declared on the platform, and whether it can actually be invoiced.
 *
 * Being declared and being reachable are two different states, and only the
 * second one matters: an invoice sent to a company that holds no active
 * directory entry is rejected at the sender with "No route found for given
 * key", never reaching the recipient at all.
 */
final readonly class BusinessEntity
{
    /**
     * @param  list<EntityIdentifier>  $identifiers
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $siren = null,
        public ?string $siret = null,
        public ?string $type = null,
        public ?string $country = null,
        public array $identifiers = [],
    ) {}

    /**
     * Whether an invoice addressed to this company can be routed today.
     */
    public function isReachable(?DateTimeImmutable $moment = null): bool
    {
        return $this->activeRegistration($moment) !== null;
    }

    /**
     * The electronic address invoices are delivered to, e.g. "0225:902695436".
     *
     * This is the value quoted back in a "No route found" rejection.
     */
    public function electronicAddress(?DateTimeImmutable $moment = null): ?string
    {
        return $this->activeRegistration($moment)?->directoryAddress;
    }

    /**
     * Why this company cannot be invoiced, or null when it can.
     *
     * A code rather than a sentence: a library has no business deciding the
     * language its host application speaks to its users in.
     *
     * @return 'no-identifier'|'no-registration'|'registration-not-yet-active'|null
     */
    public function unreachableReason(?DateTimeImmutable $moment = null): ?string
    {
        if ($this->isReachable($moment)) {
            return null;
        }

        if ($this->identifiers === []) {
            return 'no-identifier';
        }

        foreach ($this->identifiers as $identifier) {
            if ($identifier->registrations !== []) {
                // Filed, but its start date has not come yet.
                return 'registration-not-yet-active';
            }
        }

        return 'no-registration';
    }

    private function activeRegistration(?DateTimeImmutable $moment): ?NetworkRegistration
    {
        $moment ??= new DateTimeImmutable;

        foreach ($this->identifiers as $identifier) {
            foreach ($identifier->registrations as $registration) {
                if ($registration->isActiveAt($moment)) {
                    return $registration;
                }
            }
        }

        return null;
    }
}
