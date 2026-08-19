<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Drivers\Iopole;

use AmazScript\Einvoicing\Contracts\PayloadInterpreter;
use AmazScript\Einvoicing\Tenancy\RoutingKeys;
use AmazScript\Einvoicing\Webhook\InboundRequest;

/**
 * The Iopole platform's delivery conventions, taken from real deliveries as much
 * as from its documentation.
 */
final class IopolePayloadInterpreter implements PayloadInterpreter
{
    /**
     * Order of preference for identifying a delivery:
     *
     *   1. the X-Idempotency-Key header, supplied by the platform and constant
     *      across retries;
     *   2. failing that, the business identifier of whatever was delivered —
     *      statusId for a status, invoiceId for an invoice, eventId for an event;
     *   3. as a last resort, a digest of the content, so that the same delivery
     *      repeated stays recognisable even without an identifier.
     *
     * The prefix keeps a status identifier and a digest from resembling each
     * other by accident.
     */
    public function idempotencyKey(InboundRequest $request): string
    {
        $entete = $request->headers['x-idempotency-key'] ?? '';

        if (is_string($entete) && $entete !== '') {
            return $entete;
        }

        foreach (['eventId', 'statusId', 'invoiceId', 'documentId'] as $champ) {
            $valeur = $request->payload[$champ] ?? null;

            if (is_string($valeur) && $valeur !== '') {
                return strtolower($champ).':'.$valeur;
            }
        }

        return 'sha256:'.hash('sha256', $request->checksumSource);
    }

    public function eventType(InboundRequest $request): string
    {
        // Generic events announce their own type; invoices and statuses do not,
        // and are recognised by their shape.
        $declare = $request->payload['eventType'] ?? null;

        if (is_string($declare) && $declare !== '') {
            return $declare;
        }

        if (isset($request->payload['statusId'])) {
            return 'INVOICE_STATUS';
        }

        return $request->isMultipart ? 'INVOICE_INBOUND' : 'UNKNOWN';
    }

    /**
     * The recipient arrives in a dedicated header, shaped `scheme:value`. When
     * it is missing, the recipients carried by the payload are used instead.
     */
    public function routingKeys(InboundRequest $request): RoutingKeys
    {
        $siren = null;
        $siret = null;

        $adresse = $request->headers['x-target-electronic-address'] ?? '';

        if (is_string($adresse) && str_contains($adresse, ':')) {
            [$scheme, $valeur] = explode(':', $adresse, 2);
            $chiffres = preg_replace('/\D/', '', $valeur) ?? '';

            // 0002 is the SIRENE registry, 0009 the SIRET, and 0225 French
            // electronic addresses — the one the platform actually uses. That
            // last one takes both lengths; only the value tells a company from
            // an establishment.
            match (true) {
                $scheme === '0002' && strlen($chiffres) === 9 => $siren = $chiffres,
                $scheme === '0009' && strlen($chiffres) === 14 => $siret = $chiffres,
                $scheme === '0225' && strlen($chiffres) === 9 => $siren = $chiffres,
                $scheme === '0225' && strlen($chiffres) === 14 => $siret = $chiffres,
                default => null,
            };
        }

        $destinataire = $this->firstRecipient($request);

        return new RoutingKeys(
            externalId: $this->stringOrNull($request->payload['idPath'] ?? null),
            siret: $siret ?? $this->stringOrNull($destinataire['siret'] ?? null),
            siren: $siren ?? $this->stringOrNull($destinataire['siren'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function firstRecipient(InboundRequest $request): array
    {
        $json = $request->payload['json'] ?? null;
        $recipients = is_array($json) ? ($json['recipients'] ?? null) : ($request->payload['recipients'] ?? null);

        if (! is_array($recipients) || $recipients === []) {
            return [];
        }

        $premier = reset($recipients);

        return is_array($premier) ? $premier : [];
    }

    private function stringOrNull(mixed $valeur): ?string
    {
        return is_string($valeur) && $valeur !== '' ? $valeur : null;
    }
}
