<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Entities;

/**
 * A company declared on the platform.
 *
 * Declared is not the same as reachable: an entity can exist in the account
 * while none of its identifiers is served by a platform, in which case nobody
 * can invoice it.
 */
final class BusinessEntity
{
    /**
     * @param  list<EntityIdentifier>  $identifiers
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $type,
        public readonly ?string $scope,
        public readonly ?string $country,
        public readonly ?string $siren,
        public readonly ?string $siret,
        public readonly array $identifiers = [],
    ) {}

    public function isReachable(): bool
    {
        foreach ($this->identifiers as $identifier) {
            if ($identifier->isReachable()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why this company cannot be invoiced, or null when it can.
     *
     * A code rather than a sentence: a library has no business deciding the
     * language its host application speaks to its users in.
     *
     * @return 'no-identifier'|'no-registration'|'no-serving-platform'|null
     */
    public function unreachableReason(): ?string
    {
        if ($this->isReachable()) {
            return null;
        }

        if ($this->identifiers === []) {
            return 'no-identifier';
        }

        foreach ($this->identifiers as $identifier) {
            if ($identifier->registrations !== []) {
                // The directory knows the address but nobody collects from it.
                return 'no-serving-platform';
            }
        }

        return 'no-registration';
    }
}
