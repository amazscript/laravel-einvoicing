<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Events;

/**
 * Une requête s'est présentée sur l'URL de rappel avec une signature invalide.
 *
 * À surveiller de près : en régime normal cet événement ne se produit jamais.
 * Une série soudaine signale soit une rotation de secret mal propagée, soit une
 * tentative d'injection de fausses factures.
 */
final class WebhookSignatureRejected
{
    public function __construct(
        public readonly string $reason,
        public readonly string $ip,
    ) {}
}
