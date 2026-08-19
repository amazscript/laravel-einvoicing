<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Drivers\Iopole\ResponseMappers;

use AmazScript\Einvoicing\Contracts\StatusMapper;

/**
 * Reads a lifecycle status as the platform actually sends it.
 *
 * Shape observed on a real delivery:
 *
 *     { invoiceId, statusId, date, destType, status: { code, value?, desc? },
 *       xml, json: { identification, responses[], recipients[], … } }
 *
 * Only `status.code` proved to be always present. The network code arrives under
 * `networkCode` rather than `value` as the documentation announces, and `desc`
 * is often missing: none of it is required here.
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

        // Without an identifier and a code there is no status to record.
        if ($statusId === null || $code === null) {
            return null;
        }

        return [
            'provider_status_id' => $statusId,
            'provider_invoice_id' => $this->string($payload['invoiceId'] ?? null),
            'code' => $code,
            // The network code — "202" for RECEIVED — arrives under networkCode.
            // The documentation calls it value; real deliveries do not.
            'value' => $this->string($status['networkCode'] ?? null)
                ?? $this->string($status['value'] ?? null),
            'description' => $this->string($status['desc'] ?? null) ?? $this->rejectionMessage($payload),
            'dest_type' => $this->string($payload['destType'] ?? null),
            'occurred_at' => $this->string($payload['date'] ?? null),
            // Shared reference with the received invoice: the number its issuer
            // gave it, qualified by their SIREN.
            'issuer_invoice_number' => $this->string($this->documentReference($payload)['issuerAssignedId'] ?? null),
            'issuer_siren' => $this->issuerSiren($payload),
            // Raw JSON and XML kept: they are the status's supporting evidence.
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
     * A rejection carries its reason in the response; that beats an empty
     * description when trying to understand what happened.
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
