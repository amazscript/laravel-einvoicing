<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Api;

use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use AmazScript\Einvoicing\Enums\InvoiceFileKind;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\InvoiceFile;
use AmazScript\Einvoicing\Storage\InvoiceFileStore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Une facture précise, et ce qu'on peut en faire.
 */
final class InvoiceHandle
{
    public function __construct(
        private readonly InboundInvoice $invoice,
        private readonly InvoiceGateway $gateway,
        private readonly InvoiceFileStore $store,
    ) {}

    public function model(): InboundInvoice
    {
        return $this->invoice;
    }

    /**
     * Acquitte la facture auprès de la plateforme : elle sortira des « non vues ».
     *
     * L'horodatage local n'est posé qu'après l'accusé de la plateforme : le noter
     * d'avance ferait croire à un acquittement qui n'a pas eu lieu.
     */
    public function markAsSeen(): self
    {
        $this->gateway->markInvoiceAsSeen($this->invoice->provider_invoice_id);

        $this->invoice->forceFill(['seen_at' => Carbon::now()])->save();

        return $this;
    }

    /**
     * Document d'origine. Le contenu déjà stocké est servi sans appel réseau.
     */
    public function xml(): string
    {
        return $this->contentsOf(InvoiceFileKind::Xml)
            ?? $this->gateway->downloadInvoice($this->invoice->provider_invoice_id);
    }

    public function readablePdf(): string
    {
        return $this->contentsOf(InvoiceFileKind::ReadablePdf)
            ?? $this->gateway->downloadReadable($this->invoice->provider_invoice_id);
    }

    /**
     * Pièces jointes déjà stockées.
     *
     * @return Collection<int, InvoiceFile>
     */
    public function attachments(): Collection
    {
        return InvoiceFile::query()
            ->where('invoice_id', $this->invoice->id)
            ->where('kind', InvoiceFileKind::Attachment->value)
            ->get();
    }

    /**
     * Range le document d'origine sur un disque choisi par l'application.
     */
    public function store(?string $disk = null): InvoiceFile
    {
        return $this->store->store(
            $this->invoice,
            InvoiceFileKind::Xml,
            $this->xml(),
            null,
            null,
            $disk,
        );
    }

    private function contentsOf(InvoiceFileKind $kind): ?string
    {
        $fichier = InvoiceFile::query()
            ->where('invoice_id', $this->invoice->id)
            ->where('kind', $kind->value)
            ->first();

        return $fichier instanceof InvoiceFile ? $this->store->contents($fichier) : null;
    }
}
