<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Entities;

/**
 * A legal identifier of a company, with its directory registrations.
 *
 * The identifier ("0002:449290493", a SIREN) and the electronic address it is
 * reachable at ("0225:449290493") are two different things: the first says who
 * the company is, the second says where invoices are delivered. Only the second
 * routes an invoice.
 */
final readonly class EntityIdentifier
{
    /**
     * @param  list<NetworkRegistration>  $registrations
     */
    public function __construct(
        public ?string $id,
        /** Identifier scheme, e.g. "0002" for a SIREN or "0009" for a SIRET. */
        public ?string $scheme,
        public ?string $value,
        public array $registrations = [],
    ) {}

    /**
     * The legal identifier in "scheme:value" form, or null when incomplete.
     *
     * Not a routing address — see NetworkRegistration::$directoryAddress.
     */
    public function legalAddress(): ?string
    {
        if ($this->scheme === null || $this->value === null) {
            return null;
        }

        return $this->scheme.':'.$this->value;
    }
}
