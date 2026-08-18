<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Events;

use AmazScript\Einvoicing\Tenancy\RoutingKeys;

/**
 * Aucun dossier n'a pu être associé à un événement entrant.
 *
 * À surveiller : un événement non routé est une donnée conservée mais non
 * exploitée, donc une facture qui n'arrivera jamais dans la comptabilité tant
 * que personne n'intervient.
 */
final class TenantResolutionFailed
{
    public function __construct(
        public readonly RoutingKeys $keys,
        public readonly string $reason,
    ) {}
}
