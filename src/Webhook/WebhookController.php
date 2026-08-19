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
 * Entry point for the platform's deliveries.
 *
 * Does three things only: authenticate, bank, answer. No business processing
 * here — that goes to the queue.
 *
 * An absolute rule about return codes: a business error must never produce a
 * 5xx. The platform would read it as an outage and start retrying for nothing.
 * An unintelligible payload is therefore banked and answered in the 2xx range;
 * only an invalid signature earns a 401, since there is nothing worth keeping.
 */
final class WebhookController
{
    public function __construct(
        private readonly SignatureVerifier $verifier,
        private readonly InboundEventRecorder $recorder,
        private readonly InboundEventDispatcher $dispatcher,
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

            // Nothing is persisted: an unauthenticated request is not data but
            // noise, potentially hostile.
            return new Response('invalid signature', 401);
        }

        // Already received: delivery is at-least-once, so a replay is normal.
        // We answer as for a success so the platform stops retrying.
        $event = $this->recorder->record($inbound);

        if ($event !== null) {
            $this->dispatcher->dispatch($event);
        }

        return new Response('', 202);
    }

    private function canonicalPath(): ?string
    {
        $configured = $this->config->get('einvoicing.webhook.canonical_path');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }
}
