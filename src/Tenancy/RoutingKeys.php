<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Tenancy;

/**
 * The keys used to find the recipient of an inbound delivery.
 *
 * Deliberately vendor-neutral: extracting these values from a payload is the
 * platform driver's job. Nothing provider-specific may reach this far, since a
 * second driver lands in v0.4.
 */
final class RoutingKeys
{
    public function __construct(
        public readonly ?string $externalId = null,
        public readonly ?string $siret = null,
        public readonly ?string $siren = null,
    ) {}

    /**
     * The SIRET reduced to its digits. Real payloads carry spaces and
     * separators, whereas the database stores digits only.
     */
    public function normalizedSiret(): ?string
    {
        return self::digits($this->siret, 14);
    }

    public function normalizedSiren(): ?string
    {
        return self::digits($this->siren, 9);
    }

    /**
     * The SIREN derived from a SIRET: the first nine digits identify the
     * company, the next five its establishment. This routes an invoice
     * addressed to an establishment the package does not know yet.
     */
    public function sirenFromSiret(): ?string
    {
        $siret = $this->normalizedSiret();

        return $siret === null ? null : substr($siret, 0, 9);
    }

    private static function digits(?string $value, int $expectedLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';

        return strlen($digits) === $expectedLength ? $digits : null;
    }
}
