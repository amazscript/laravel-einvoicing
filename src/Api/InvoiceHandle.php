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
 * One specific invoice, and what can be done with it.
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
     * Acknowledges the invoice with the platform: it leaves the "not seen" list.
     *
     * The local timestamp is stamped only after the platform confirms. Stamping
     * first would claim an acknowledgement that never happened.
     */
    public function markAsSeen(): self
    {
        $this->gateway->markInvoiceAsSeen($this->invoice->provider_invoice_id);

        $this->invoice->forceFill(['seen_at' => Carbon::now()])->save();

        return $this;
    }

    /**
     * The original document. Content already stored is served without a network
     * call.
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
     * Attachments already stored.
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
     * Stores the original document on a disk chosen by the application.
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
