<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Tenancy;

/**
 * Clés permettant de retrouver le destinataire d'un événement entrant.
 *
 * Volontairement neutre : c'est le driver de la plateforme qui sait extraire ces
 * valeurs de son propre format de payload. Rien de spécifique à un fournisseur
 * ne doit remonter jusqu'ici, le second driver arrivant en v0.4.
 */
final class RoutingKeys
{
    public function __construct(
        public readonly ?string $externalId = null,
        public readonly ?string $siret = null,
        public readonly ?string $siren = null,
    ) {}

    /**
     * SIRET réduit à ses chiffres. Les payloads réels contiennent espaces et
     * séparateurs ; la base, elle, ne stocke que des chiffres.
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
     * SIREN déduit du SIRET : les neuf premiers chiffres identifient l'entreprise,
     * les cinq suivants l'établissement. Permet de router une facture adressée à
     * un établissement que le package ne connaît pas encore.
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
