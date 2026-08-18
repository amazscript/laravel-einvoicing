<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Contracts;

/**
 * Traduit le payload de statut d'une plateforme en attributs du modèle Status.
 *
 * Le job de traitement ne connaît donc aucune structure propre à un fournisseur.
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
     *     payload: array<string, mixed>
     * }|null  null si le payload ne décrit pas un statut exploitable
     */
    public function map(array $payload): ?array;
}
