<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Contracts;

use Illuminate\Support\LazyCollection;

/**
 * Access to the invoice data the platform holds.
 *
 * A webhook carries only an identifier and the document: everything else —
 * number, date, amounts, issuer, attached files — is fetched here.
 */
interface InvoiceGateway
{
    /**
     * Accounting metadata, already translated into model attributes.
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
     * }|null  null when the platform does not know this invoice
     */
    public function metadata(string $providerInvoiceId): ?array;

    /**
     * Files attached to the invoice.
     *
     * @return list<array{id: string, kind: string, filename: string|null, mime: string|null, size: int|null, checksum: string|null}>
     */
    public function files(string $providerInvoiceId): array;

    /**
     * Binary content of a file.
     */
    public function download(string $fileId): string;

    /**
     * Tells the network what the buyer does with an invoice it received.
     *
     * Under the French reform this is not a courtesy: some of these answers are
     * required, and silence is not one of them.
     *
     * @param  array<string, mixed>  $payload  status code and its details
     * @return string the identifier the platform gives this status
     */
    public function postStatus(string $providerInvoiceId, array $payload): string;

    /**
     * Received invoices the package has not acknowledged yet.
     *
     * A safety net for a webhook that went missing. The endpoint does not
     * paginate: it returns every unseen invoice, to be consumed then acknowledged.
     *
     * @return list<array<string, mixed>>
     */
    public function notSeen(): array;

    /**
     * Statuses not yet acknowledged, same principle.
     *
     * @return list<array<string, mixed>>
     */
    public function statusesNotSeen(): array;

    /**
     * Acknowledges an invoice with the platform: it leaves notSeen.
     */
    public function markInvoiceAsSeen(string $providerInvoiceId): void;

    public function markStatusAsSeen(string $providerStatusId): void;

    /**
     * The original document, as the supplier issued it.
     */
    public function downloadInvoice(string $providerInvoiceId): string;

    /**
     * A human-readable rendering.
     */
    public function downloadReadable(string $providerInvoiceId): string;

    /**
     * Invoice search using the platform's filter syntax, for instance:
     * invoice.direction:"INBOUND" AND invoice.state:"NOT_DELIVERED".
     *
     * Each result carries a `metadata` object. `expand` asks for extra sections
     * in the same response, sparing one call per invoice.
     *
     * @param  list<string>  $expand
     * @return LazyCollection<int, array<mixed>>
     */
    public function searchInvoices(string $query, array $expand = []): LazyCollection;

    /**
     * Searches the directory of reachable companies.
     *
     * @return LazyCollection<int, array<mixed>>
     */
    public function searchDirectory(string $query): LazyCollection;
}
