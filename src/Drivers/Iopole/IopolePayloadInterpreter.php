<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Drivers\Iopole;

use AmazScript\Einvoicing\Contracts\PayloadInterpreter;
use AmazScript\Einvoicing\Tenancy\RoutingKeys;
use AmazScript\Einvoicing\Webhook\InboundRequest;

/**
 * Conventions de livraison de la plateforme Iopole, relevées sur des livraisons
 * réelles autant que dans sa documentation.
 */
final class IopolePayloadInterpreter implements PayloadInterpreter
{
    /**
     * Ordre de préférence pour identifier une livraison :
     *
     *   1. l'en-tête X-Idempotency-Key, que la plateforme fournit et qui reste
     *      constant d'une nouvelle tentative à l'autre ;
     *   2. à défaut, l'identifiant métier de l'objet livré — statusId pour un
     *      statut, invoiceId pour une facture, eventId pour un événement ;
     *   3. en dernier recours, une empreinte du contenu, pour qu'une même
     *      livraison répétée reste reconnue même sans identifiant.
     *
     * Le préfixe évite qu'un identifiant de statut et une empreinte se
     * ressemblent par accident.
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
     * Le destinataire arrive dans un en-tête dédié, sous la forme
     * `scheme:valeur` — le schéma 0002 désignant un SIREN. Quand il manque, on
     * se rabat sur les destinataires portés par le payload.
     */
    public function routingKeys(InboundRequest $request): RoutingKeys
    {
        $siren = null;
        $siret = null;

        $adresse = $request->headers['x-target-electronic-address'] ?? '';

        if (is_string($adresse) && str_contains($adresse, ':')) {
            [$scheme, $valeur] = explode(':', $adresse, 2);

            match ($scheme) {
                '0002' => $siren = $valeur,
                '0009' => $siret = $valeur,
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
