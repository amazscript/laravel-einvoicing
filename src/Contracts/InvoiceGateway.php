<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Contracts;

/**
 * Accès aux données d'une facture détenues par la plateforme.
 *
 * Le webhook ne transporte qu'un identifiant et le document : tout le reste —
 * numéro, date, montants, émetteur, fichiers annexes — se récupère ici.
 */
interface InvoiceGateway
{
    /**
     * Métadonnées comptables, déjà traduites en attributs du modèle.
     *
     * @return array{
     *     invoice_number: string|null,
     *     invoice_date: string|null,
     *     sender_name: string|null,
     *     sender_siren: string|null,
     *     sender_siret: string|null,
     *     amount_total: string|null,
     *     amount_tax: string|null,
     *     currency: string|null,
     *     format: string|null
     * }|null  null si la plateforme ne connaît pas cette facture
     */
    public function metadata(string $providerInvoiceId): ?array;

    /**
     * Fichiers attachés à la facture.
     *
     * @return list<array{id: string, kind: string, filename: string|null, mime: string|null, size: int|null, checksum: string|null}>
     */
    public function files(string $providerInvoiceId): array;

    /**
     * Contenu binaire d'un fichier.
     */
    public function download(string $fileId): string;
}
