<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Drivers\Iopole;

use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use AmazScript\Einvoicing\Enums\InvoiceFileKind;
use AmazScript\Einvoicing\Exceptions\EinvoicingException;
use Illuminate\Support\LazyCollection;

/**
 * Reads invoices as the Iopole platform exposes them.
 *
 * Response shapes come from its published specification: accounting metadata
 * lives under `businessData`, and amounts are `{ amount, currency }` objects
 * rather than bare numbers.
 */
final class IopoleInvoiceGateway implements InvoiceGateway
{
    public function __construct(
        private readonly Client $client,
    ) {}

    /**
     * @return array{invoice_number: string|null, invoice_date: string|null, sender_name: string|null, sender_siren: string|null, sender_siret: string|null, amount_total: string|null, amount_tax: string|null, currency: string|null, format: string|null}|null
     */
    public function metadata(string $providerInvoiceId): ?array
    {
        try {
            $reponse = $this->client->get(Endpoints::invoice($providerInvoiceId));
        } catch (EinvoicingException) {
            // An invoice that cannot be found, or is briefly unavailable, must
            // not cost us the one already recorded.
            return null;
        }

        // The API answers with a list, not an object, despite its documentation.
        if (array_is_list($reponse)) {
            $premiere = $reponse[0] ?? null;
            $reponse = is_array($premiere) ? $premiere : [];
        }

        $business = $reponse['businessData'] ?? null;

        if (! is_array($business)) {
            return null;
        }

        $monetary = is_array($business['monetary'] ?? null) ? $business['monetary'] : [];
        $seller = is_array($business['seller'] ?? null) ? $business['seller'] : [];

        return [
            'invoice_number' => $this->string($business['invoiceId'] ?? null),
            'invoice_date' => $this->string($business['invoiceDate'] ?? null),
            'sender_name' => $this->string($seller['name'] ?? null),
            'sender_siren' => $this->string($seller['siren'] ?? null),
            'sender_siret' => $this->string($seller['siret'] ?? null),
            // The amount due governs: that is what accounting actually pays.
            'amount_total' => $this->amount($monetary['payableAmount'] ?? null)
                ?? $this->amount($monetary['invoiceAmount'] ?? null),
            'amount_tax' => $this->amount($monetary['taxTotalAmount'] ?? null),
            'currency' => $this->string($monetary['invoiceCurrency'] ?? null),
            // The specification announces FACTURX, the API serves FacturX: the
            // case is normalised rather than losing the format over it.
            'format' => $this->upper($reponse['originalFormat'] ?? null),
        ];
    }

    /**
     * @return list<array{id: string, kind: string, filename: string|null, mime: string|null, size: int|null, checksum: string|null}>
     */
    public function files(string $providerInvoiceId): array
    {
        try {
            $reponse = $this->client->get(Endpoints::invoiceFiles($providerInvoiceId));
        } catch (EinvoicingException) {
            return [];
        }

        $fichiers = [];

        foreach ($reponse as $fichier) {
            if (! is_array($fichier)) {
                continue;
            }

            $id = $this->string($fichier['fileId'] ?? null);

            if ($id === null) {
                continue;
            }

            $fichiers[] = [
                'id' => $id,
                'kind' => $this->kind($fichier)->value,
                'filename' => $this->string($fichier['originalFilename'] ?? null)
                    ?? $this->string($fichier['fileName'] ?? null),
                'mime' => $this->string($fichier['mimeType'] ?? null),
                'size' => is_numeric($fichier['sizeBytes'] ?? null) ? (int) $fichier['sizeBytes'] : null,
                'checksum' => $this->string($fichier['checksum'] ?? null),
            ];
        }

        return $fichiers;
    }

    public function download(string $fileId): string
    {
        return $this->client->raw(Endpoints::downloadFile($fileId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function notSeen(): array
    {
        return $this->listOf(Endpoints::invoicesNotSeen());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function statusesNotSeen(): array
    {
        return $this->listOf(Endpoints::statusesNotSeen());
    }

    public function markInvoiceAsSeen(string $providerInvoiceId): void
    {
        $this->client->put(Endpoints::markInvoiceAsSeen($providerInvoiceId));
    }

    public function markStatusAsSeen(string $providerStatusId): void
    {
        $this->client->put(Endpoints::markStatusAsSeen($providerStatusId));
    }

    public function downloadInvoice(string $providerInvoiceId): string
    {
        return $this->client->raw(Endpoints::downloadInvoice($providerInvoiceId));
    }

    public function downloadReadable(string $providerInvoiceId): string
    {
        return $this->client->raw(Endpoints::downloadReadableInvoice($providerInvoiceId));
    }

    /**
     * @param  list<string>  $expand
     * @return LazyCollection<int, array<mixed>>
     */
    public function searchInvoices(string $query, array $expand = []): LazyCollection
    {
        return $this->client->paginate(Endpoints::searchInvoices(), array_filter([
            'q' => $query,
            'expand' => $expand === [] ? null : implode(',', $expand),
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * @return LazyCollection<int, array<mixed>>
     */
    public function searchDirectory(string $query): LazyCollection
    {
        return $this->client->paginate(Endpoints::directoryFrenchSearch(), ['q' => $query]);
    }

    /**
     * The "not seen" endpoints answer with a bare array, without envelope or
     * pagination — observed on the real API.
     *
     * @return list<array<string, mixed>>
     */
    private function listOf(string $path): array
    {
        $lignes = [];

        foreach ($this->client->get($path) as $ligne) {
            if (is_array($ligne)) {
                $lignes[] = $ligne;
            }
        }

        return $lignes;
    }

    /**
     * The file's nature is not standardised: it is inferred from the announced
     * type, then from the MIME type. A PDF is the readable rendering, an XML the
     * original document; everything else is an attachment.
     *
     * @param  array<string, mixed>  $fichier
     */
    private function kind(array $fichier): InvoiceFileKind
    {
        $type = strtoupper((string) ($this->string($fichier['type'] ?? null) ?? ''));
        $mime = strtolower((string) ($this->string($fichier['mimeType'] ?? null) ?? ''));

        return match (true) {
            str_contains($type, 'XML'), str_contains($mime, 'xml') => InvoiceFileKind::Xml,
            str_contains($type, 'READABLE'), str_contains($type, 'PDF'), str_contains($mime, 'pdf') => InvoiceFileKind::ReadablePdf,
            default => InvoiceFileKind::Attachment,
        };
    }

    /**
     * Amounts arrive as { amount, currency }. They are kept as strings, since
     * money does not survive binary floating point unscathed.
     */
    private function amount(mixed $valeur): ?string
    {
        if (is_array($valeur) && is_numeric($valeur['amount'] ?? null)) {
            return (string) $valeur['amount'];
        }

        return is_numeric($valeur) ? (string) $valeur : null;
    }

    private function string(mixed $valeur): ?string
    {
        return is_string($valeur) && $valeur !== '' ? $valeur : null;
    }

    private function upper(mixed $valeur): ?string
    {
        $texte = $this->string($valeur);

        return $texte === null ? null : strtoupper($texte);
    }
}
