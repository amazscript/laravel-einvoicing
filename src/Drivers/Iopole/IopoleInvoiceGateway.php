<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Drivers\Iopole;

use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use AmazScript\Einvoicing\Enums\InvoiceFileKind;
use AmazScript\Einvoicing\Exceptions\EinvoicingException;

/**
 * Lecture des factures telles que la plateforme Iopole les expose.
 *
 * La forme des réponses est relevée sur sa spécification publiée : les
 * métadonnées comptables vivent sous `businessData`, et les montants sont des
 * objets `{ amount, currency }` plutôt que des nombres nus.
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
            // Une facture introuvable ou momentanément indisponible ne doit pas
            // faire perdre celle qu'on a déjà consignée.
            return null;
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
            // Le net à payer fait foi : c'est le montant que la comptabilité règle.
            'amount_total' => $this->amount($monetary['payableAmount'] ?? null)
                ?? $this->amount($monetary['invoiceAmount'] ?? null),
            'amount_tax' => $this->amount($monetary['taxTotalAmount'] ?? null),
            'currency' => $this->string($monetary['invoiceCurrency'] ?? null),
            'format' => $this->string($reponse['originalFormat'] ?? null),
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
     * La nature du fichier n'est pas normalisée : on la déduit du type annoncé
     * puis, à défaut, du type MIME. Un PDF est le document lisible, un XML le
     * document d'origine ; tout le reste est une pièce jointe.
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
     * Les montants arrivent sous la forme { amount, currency }. On les conserve
     * en chaîne pour ne pas perdre de précision en passant par un flottant.
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
}
