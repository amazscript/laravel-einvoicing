<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Contracts;

use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Tenancy\RoutingKeys;

/**
 * Retrouve le dossier destinataire d'un événement entrant.
 *
 * La plateforme n'accepte qu'une seule URL de rappel pour tout le parc : le
 * routage est donc entièrement à la charge du package.
 *
 * Retourner null n'est pas une erreur, c'est un échec de routage : l'appelant
 * doit conserver l'événement plutôt que de le perdre ou de renvoyer un 5xx.
 */
interface TenantResolver
{
    public function resolve(RoutingKeys $keys): ?Tenant;
}
