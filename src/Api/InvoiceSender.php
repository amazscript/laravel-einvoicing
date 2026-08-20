<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Api;

use AmazScript\Einvoicing\Contracts\OutboundInvoiceGateway;
use AmazScript\Einvoicing\Enums\OutboundStatus;
use AmazScript\Einvoicing\Events\OutboundInvoiceFailed;
use AmazScript\Einvoicing\Events\OutboundInvoiceSent;
use AmazScript\Einvoicing\Exceptions\EinvoicingException;
use AmazScript\Einvoicing\Models\OutboundInvoice;
use AmazScript\Einvoicing\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * Hands a document to the platform, once and only once.
 *
 * The send endpoint accepts no idempotency key, so a retry after a timeout could
 * bill a customer twice — the kind of mistake an accounting department finds
 * months later. Uniqueness therefore rests on (tenant, file checksum) in the
 * database, and a violation of it is read as "already sent", not as an error.
 */
final class InvoiceSender
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly OutboundInvoiceGateway $gateway,
        private readonly string $provider = 'iopole',
    ) {}

    /**
     * Sends an invoice document already produced by the application.
     *
     * The package builds no Factur-X, UBL or CII: that is the platform's trade,
     * and a malformed one is a tax problem, not a bug.
     *
     * Returns the row, sent or already sent. Re-sending the same file returns
     * the first send untouched rather than issuing a second invoice.
     */
    public function send(string $filePath): OutboundInvoice
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw new InvalidArgumentException("Invoice file not found or unreadable: {$filePath}");
        }

        $empreinte = hash_file('sha256', $filePath);

        if ($empreinte === false) {
            throw new InvalidArgumentException("Unable to checksum the invoice file: {$filePath}");
        }

        $deja = OutboundInvoice::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('file_hash', $empreinte)
            ->first();

        if ($deja instanceof OutboundInvoice) {
            return $deja;
        }

        $ligne = $this->claim($filePath, $empreinte);

        // The claim lost the race: another process is sending this very file.
        if ($ligne === null) {
            $gagnant = OutboundInvoice::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('file_hash', $empreinte)
                ->first();

            if ($gagnant instanceof OutboundInvoice) {
                return $gagnant;
            }

            throw new InvalidArgumentException("Concurrent send left no trace for: {$filePath}");
        }

        return $this->handOver($ligne, $filePath);
    }

    /**
     * Books the send before making it.
     *
     * Written first so that two concurrent calls cannot both reach the platform:
     * the second loses on the unique constraint and reads the first one's row.
     */
    private function claim(string $filePath, string $empreinte): ?OutboundInvoice
    {
        try {
            return OutboundInvoice::query()->create([
                'tenant_id' => $this->tenant->id,
                'provider' => $this->provider,
                'file_hash' => $empreinte,
                'file_name' => basename($filePath),
                'file_size' => (int) (filesize($filePath) ?: 0),
                'status' => OutboundStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function handOver(OutboundInvoice $ligne, string $filePath): OutboundInvoice
    {
        try {
            $identifiant = $this->gateway->send($filePath, $ligne->file_name);
        } catch (EinvoicingException $e) {
            // Kept, not deleted: what was refused and why is what anyone will
            // ask for, and a vanished row answers nothing.
            $ligne->forceFill([
                'status' => OutboundStatus::Failed,
                'failure_reason' => $e->getMessage(),
            ])->save();

            Event::dispatch(new OutboundInvoiceFailed($ligne));

            throw $e;
        }

        $ligne->forceFill([
            'provider_invoice_id' => $identifiant,
            'status' => OutboundStatus::Sent,
            'sent_at' => now(),
            'failure_reason' => null,
        ])->save();

        Event::dispatch(new OutboundInvoiceSent($ligne));

        return $ligne;
    }
}
