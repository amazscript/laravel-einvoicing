<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Contracts;

use Illuminate\Support\LazyCollection;

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

    /**
     * Factures reçues que le package n'a pas encore acquittées.
     *
     * Sert de filet quand un webhook s'est perdu. L'endpoint ne pagine pas : il
     * rend l'ensemble des factures non vues, que l'on consomme puis acquitte.
     *
     * @return list<array<string, mixed>>
     */
    public function notSeen(): array;

    /**
     * Statuts non encore acquittés, même principe.
     *
     * @return list<array<string, mixed>>
     */
    public function statusesNotSeen(): array;

    /**
     * Acquitte une facture auprès de la plateforme : elle sortira de notSeen.
     */
    public function markInvoiceAsSeen(string $providerInvoiceId): void;

    public function markStatusAsSeen(string $providerStatusId): void;

    /**
     * Document d'origine, tel qu'émis par le fournisseur.
     */
    public function downloadInvoice(string $providerInvoiceId): string;

    /**
     * Version lisible par un humain.
     */
    public function downloadReadable(string $providerInvoiceId): string;

    /**
     * Recherche de factures selon la syntaxe de filtres de la plateforme,
     * par exemple : invoice.direction:"INBOUND" AND invoice.state:"NOT_DELIVERED".
     *
     * Chaque résultat porte un objet `metadata`. `expand` demande des sections
     * supplémentaires dans la même réponse, évitant un appel par facture.
     *
     * @param  list<string>  $expand
     * @return LazyCollection<int, array<mixed>>
     */
    public function searchInvoices(string $query, array $expand = []): LazyCollection;

    /**
     * Recherche dans l'annuaire des entreprises joignables.
     *
     * @return LazyCollection<int, array<mixed>>
     */
    public function searchDirectory(string $query): LazyCollection;
}
