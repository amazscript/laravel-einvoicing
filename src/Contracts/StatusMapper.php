<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Contracts;

/**
 * Translates a platform's status payload into Status model attributes.
 *
 * The processing job therefore knows no vendor-specific structure.
 */
interface StatusMapper
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     provider_status_id: string,
     *     provider_invoice_id: string|null,
     *     code: string,
     *     value: string|null,
     *     description: string|null,
     *     dest_type: string|null,
     *     occurred_at: string|null,
     *     issuer_invoice_number: string|null,
     *     issuer_siren: string|null,
     *     payload: array<string, mixed>
     * }|null  null when the payload describes no usable status
     *
     * issuer_invoice_number and issuer_siren identify the invoice as its issuer
     * numbered it. That is the only reference shared between a status and the
     * received invoice, whose technical identifiers differ.
     */
    public function map(array $payload): ?array;
}
