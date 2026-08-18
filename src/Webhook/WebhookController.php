<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Webhook;

use AmazScript\Einvoicing\Contracts\SignatureVerifier;
use AmazScript\Einvoicing\Events\WebhookSignatureRejected;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Point d'entrée des livraisons de la plateforme.
 *
 * Ne fait que trois choses : authentifier, encaisser, répondre. Aucun traitement
 * métier ici — il part en file d'attente.
 *
 * Règle absolue sur les codes de retour : une erreur métier ne doit jamais
 * produire un 5xx. La plateforme le prendrait pour une panne et relancerait sa
 * stratégie de retry pour rien. Un payload incompréhensible est donc encaissé et
 * répondu en 2xx ; seule une signature invalide vaut un 401, car là il n'y a
 * rien à conserver.
 */
final class WebhookController
{
    public function __construct(
        private readonly SignatureVerifier $verifier,
        private readonly Dispatcher $events,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request): Response
    {
        $inbound = InboundRequest::fromRequest(
            $request,
            $this->canonicalPath(),
        );

        $authentique = $this->verifier->verify(
            $inbound->headers,
            $inbound->method,
            $inbound->pathWithQuery,
            $inbound->checksumSource,
        );

        if (! $authentique) {
            $this->events->dispatch(new WebhookSignatureRejected(
                'signature absente ou invalide',
                (string) $request->ip(),
            ));

            // Rien n'est persisté : une requête non authentifiée n'est pas une
            // donnée, c'est du bruit, potentiellement hostile.
            return new Response('invalid signature', 401);
        }

        return new Response('', 202);
    }

    private function canonicalPath(): ?string
    {
        $configured = $this->config->get('einvoicing.webhook.canonical_path');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }
}
