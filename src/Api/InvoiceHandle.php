<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Api;

use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use AmazScript\Einvoicing\Enums\BuyerStatus;
use AmazScript\Einvoicing\Enums\InvoiceFileKind;
use AmazScript\Einvoicing\Enums\RejectionReason;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\InvoiceFile;
use AmazScript\Einvoicing\Storage\InvoiceFileStore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

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
    /**
     * Acknowledges the invoice: taken into the buyer's system.
     *
     * The first answer a supplier expects, and the one that stops it wondering
     * whether the invoice arrived at all.
     */
    public function acknowledge(?string $message = null): self
    {
        return $this->answer(BuyerStatus::InHand, $message);
    }

    /**
     * Approves the invoice for payment.
     */
    public function approve(?string $message = null): self
    {
        return $this->answer(BuyerStatus::Approved, $message);
    }

    /**
     * Refuses the invoice, with the reason the supplier needs to fix it.
     *
     * The reason is required: "refused" alone leaves a supplier guessing, and
     * the platform rejects the call without one.
     */
    public function refuse(RejectionReason|string $reason, ?string $message = null): self
    {
        $code = $reason instanceof RejectionReason ? $reason->value : $reason;

        return $this->answer(BuyerStatus::Refused, $message, [
            'rejectionDetail' => array_filter([
                'reason' => $code,
                'message' => $message,
            ], static fn (mixed $v): bool => $v !== null),
        ]);
    }

    /**
     * Disputes the invoice without refusing it outright.
     */
    public function dispute(?string $message = null): self
    {
        return $this->answer(BuyerStatus::Disputed, $message);
    }

    /**
     * Reports a payment against the invoice.
     *
     * @param  float  $amount  in the invoice's own currency
     */
    public function reportPayment(float $amount, string $currency = 'EUR', ?string $message = null): self
    {
        return $this->answer(BuyerStatus::PaymentSent, $message, [
            'payment' => [['amount' => $amount, 'currency' => strtoupper($currency)]],
        ]);
    }

    /**
     * Sends any buyer status, for the cases the shorthands above do not cover.
     *
     * @param  array<string, mixed>  $extra
     */
    public function answer(BuyerStatus $status, ?string $message = null, array $extra = []): self
    {
        if ($status->needsReason() && ! isset($extra['rejectionDetail'])) {
            throw new InvalidArgumentException('A refusal requires a reason: use refuse($reason).');
        }

        if ($status->needsPayment() && ! isset($extra['payment'])) {
            throw new InvalidArgumentException('A payment status requires an amount: use reportPayment($amount).');
        }

        $payload = array_merge(
            array_filter(['code' => $status->value, 'message' => $message], static fn (mixed $v): bool => $v !== null),
            $extra,
        );

        $this->gateway->postStatus($this->invoice->provider_invoice_id, $payload);

        return $this;
    }

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
