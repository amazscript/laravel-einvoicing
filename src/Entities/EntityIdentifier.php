<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Entities;

/**
 * One identifier of a company — a SIREN, a SIRET, an electronic address —
 * together with the networks it is registered on.
 */
final class EntityIdentifier
{
    /**
     * @param  list<NetworkRegistration>  $registrations
     */
    public function __construct(
        public readonly ?string $id,
        public readonly string $scheme,
        public readonly string $value,
        public readonly ?string $type,
        public readonly array $registrations = [],
    ) {}

    public function isReachable(): bool
    {
        foreach ($this->registrations as $registration) {
            if ($registration->isReachable()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The address an invoice is sent to, shaped scheme:value.
     */
    public function electronicAddress(): string
    {
        return $this->scheme.':'.$this->value;
    }
}
