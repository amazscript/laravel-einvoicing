<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Drivers\Iopole\ResponseMappers;

use AmazScript\Einvoicing\Contracts\StatusMapper;

/**
 * Lecture d'un statut de cycle de vie tel que la plateforme l'envoie.
 *
 * Structure observée sur une livraison réelle :
 *
 *     { invoiceId, statusId, date, destType, status: { code, value?, desc? },
 *       xml, json: { identification, responses[], recipients[], … } }
 *
 * Seul `status.code` s'est révélé systématiquement présent. Le code réseau
 * arrive sous `networkCode` et non sous `value` comme l'annonce la
 * documentation, et `desc` manque souvent : rien de tout cela n'est exigé.
 */
final class IopoleStatusMapper implements StatusMapper
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{provider_status_id: string, provider_invoice_id: string|null, code: string, value: string|null, description: string|null, dest_type: string|null, occurred_at: string|null, issuer_invoice_number: string|null, issuer_siren: string|null, payload: array<string, mixed>}|null
     */
    public function map(array $payload): ?array
    {
        $statusId = $this->string($payload['statusId'] ?? null);
        $status = $payload['status'] ?? null;
        $status = is_array($status) ? $status : [];

        $code = $this->string($status['code'] ?? null);

        // Sans identifiant ni code, il n'y a pas de statut à consigner.
        if ($statusId === null || $code === null) {
            return null;
        }

        return [
            'provider_status_id' => $statusId,
            'provider_invoice_id' => $this->string($payload['invoiceId'] ?? null),
            'code' => $code,
            // Le code réseau — « 202 » pour RECEIVED — arrive sous networkCode.
            // La documentation le nomme value ; les livraisons réelles, non.
            'value' => $this->string($status['networkCode'] ?? null)
                ?? $this->string($status['value'] ?? null),
            'description' => $this->string($status['desc'] ?? null) ?? $this->rejectionMessage($payload),
            'dest_type' => $this->string($payload['destType'] ?? null),
            'occurred_at' => $this->string($payload['date'] ?? null),
            // Référence commune avec la facture reçue : le numéro que l'émetteur
            // lui a donné, qualifié par son SIREN.
            'issuer_invoice_number' => $this->string($this->documentReference($payload)['issuerAssignedId'] ?? null),
            'issuer_siren' => $this->issuerSiren($payload),
            // JSON et XML bruts conservés : c'est la pièce justificative du statut.
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function documentReference(array $payload): array
    {
        $json = $payload['json'] ?? null;
        $responses = is_array($json) ? ($json['responses'] ?? null) : null;

        if (! is_array($responses) || $responses === []) {
            return [];
        }

        $premiere = reset($responses);
        $reference = is_array($premiere) ? ($premiere['documentReference'] ?? null) : null;

        return is_array($reference) ? $reference : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function issuerSiren(array $payload): ?string
    {
        $issuer = $this->documentReference($payload)['issuer'] ?? null;

        return is_array($issuer) ? $this->string($issuer['siren'] ?? null) : null;
    }

    /**
     * Un rejet porte sa raison dans la réponse ; elle vaut mieux qu'une
     * description vide pour comprendre ce qui s'est passé.
     *
     * @param  array<string, mixed>  $payload
     */
    private function rejectionMessage(array $payload): ?string
    {
        $json = $payload['json'] ?? null;
        $responses = is_array($json) ? ($json['responses'] ?? null) : null;

        if (! is_array($responses) || $responses === []) {
            return null;
        }

        $premiere = reset($responses);
        $detail = is_array($premiere) ? ($premiere['rejectionDetail'] ?? null) : null;

        return is_array($detail) ? $this->string($detail['message'] ?? null) : null;
    }

    private function string(mixed $valeur): ?string
    {
        return is_string($valeur) && $valeur !== '' ? $valeur : null;
    }
}
