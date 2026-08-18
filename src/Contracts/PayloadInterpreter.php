<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Contracts;

use AmazScript\Einvoicing\Tenancy\RoutingKeys;
use AmazScript\Einvoicing\Webhook\InboundRequest;

/**
 * Lit une livraison entrante selon les conventions de la plateforme qui l'émet.
 *
 * Chaque plateforme nomme ses en-têtes et structure ses payloads à sa façon.
 * Tout ce qui relève de ces conventions vit derrière ce contrat, pour que le
 * webhook, la tenancy et les modèles n'aient jamais à les connaître.
 */
interface PayloadInterpreter
{
    /**
     * Clé identifiant la livraison de façon unique et stable.
     *
     * Deux livraisons de la même chose doivent donner la même clé, sans quoi la
     * déduplication ne sert à rien.
     */
    public function idempotencyKey(InboundRequest $request): string;

    /**
     * Nature de la livraison, telle qu'elle sera consignée.
     */
    public function eventType(InboundRequest $request): string;

    /**
     * Clés permettant de retrouver le destinataire.
     */
    public function routingKeys(InboundRequest $request): RoutingKeys;
}
