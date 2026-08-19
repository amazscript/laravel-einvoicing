<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Jobs;

use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use AmazScript\Einvoicing\Enums\InvoiceFileKind;
use AmazScript\Einvoicing\Enums\InvoiceFormat;
use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Events\InboundInvoiceReceived;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\InvoiceFile;
use AmazScript\Einvoicing\Models\Status;
use AmazScript\Einvoicing\Models\WebhookEvent;
use AmazScript\Einvoicing\Storage\InvoiceFileStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Records a received supplier invoice.
 *
 * A delivery carries the bare minimum: an invoice identifier and the document
 * itself. Accounting metadata — number, date, amounts, issuer — is not in the
 * webhook; it is fetched from the platform right after. So the invoice's
 * existence is recorded first, without inventing what is not known.
 */
final class ProcessInboundInvoice implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly string $webhookEventId,
        public readonly string $provider = 'iopole',
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(Dispatcher $events, InvoiceGateway $gateway, InvoiceFileStore $store): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        if ($event === null) {
            return;
        }

        $payload = $event->payload ?? [];
        $providerInvoiceId = $payload['invoiceId'] ?? null;

        if (! is_string($providerInvoiceId) || $providerInvoiceId === '') {
            $this->markFailed($event, 'delivery carries no invoice identifier');

            return;
        }

        // Replayable without side effects: a second run updates the same row.
        // That is what keeps a retry from duplicating an invoice.
        $invoice = InboundInvoice::query()->updateOrCreate(
            [
                'provider' => $this->provider,
                'provider_invoice_id' => $providerInvoiceId,
            ],
            [
                'tenant_id' => $event->tenant_id,
                'raw_metadata' => $payload,
            ],
        );

        // The webhook carries no accounting metadata, so it is fetched. Its
        // absence is not blocking: the invoice already exists.
        $this->completeFrom($gateway, $invoice, $providerInvoiceId);
        $this->downloadFiles($gateway, $store, $invoice, $providerInvoiceId);

        $this->attachOrphanStatuses($invoice, $providerInvoiceId);

        $event->forceFill([
            'status' => WebhookEventStatus::Processed,
            'processed_at' => Carbon::now(),
            'failed_reason' => null,
        ])->save();

        $events->dispatch(new InboundInvoiceReceived($invoice));
    }

    /**
     * Completes the invoice with what the platform knows of it: number, date,
     * amounts, issuer, original format.
     */
    private function completeFrom(InvoiceGateway $gateway, InboundInvoice $invoice, string $providerInvoiceId): void
    {
        $metadonnees = $gateway->metadata($providerInvoiceId);

        if ($metadonnees === null) {
            return;
        }

        $format = $metadonnees['format'];
        $metadonnees['format'] = is_string($format) ? InvoiceFormat::tryFrom($format) : null;

        $invoice->forceFill(array_filter(
            $metadonnees,
            static fn (mixed $valeur): bool => $valeur !== null,
        ))->save();
    }

    /**
     * Downloads and stores the files. A file already on record, recognised by its
     * digest, is not fetched again; one failing does not stop the others, the
     * invoice remaining usable without its attachments.
     */
    private function downloadFiles(
        InvoiceGateway $gateway,
        InvoiceFileStore $store,
        InboundInvoice $invoice,
        string $providerInvoiceId,
    ): void {
        foreach ($gateway->files($providerInvoiceId) as $descripteur) {
            $kind = InvoiceFileKind::tryFrom($descripteur['kind']) ?? InvoiceFileKind::Attachment;

            $dejaStocke = $descripteur['checksum'] !== null && InvoiceFile::query()
                ->where('invoice_id', $invoice->id)
                ->where('provider_file_id', $descripteur['id'])
                ->exists();

            if ($dejaStocke) {
                continue;
            }

            $store->store(
                $invoice,
                $kind,
                $gateway->download($descripteur['id']),
                $descripteur['id'],
                $descripteur['filename'],
            );
        }
    }

    /**
     * A status often arrives before the invoice it concerns, and was then kept
     * unlinked. It is attached here.
     *
     * Matching uses the number the issuer assigned, qualified by their SIREN. The
     * status's technical identifier will not do: it designates the invoice as
     * issued, not as received.
     */
    private function attachOrphanStatuses(InboundInvoice $invoice, string $providerInvoiceId): void
    {
        // where(..., null) rather than whereNull: the latter goes through
        // Eloquent's magic call, which loses the model type for static analysis.
        $orphelins = Status::query()
            ->where('provider', $this->provider)
            ->where('invoice_id', null)
            ->get();

        foreach ($orphelins as $status) {
            if ($this->concerns($status, $invoice, $providerInvoiceId)) {
                $status->forceFill(['invoice_id' => $invoice->id])->save();
            }
        }
    }

    /**
     * The number alone would not do: two suppliers may share a numbering scheme.
     */
    private function concerns(Status $status, InboundInvoice $invoice, string $providerInvoiceId): bool
    {
        $payload = $status->payload ?? [];

        if (($payload['invoiceId'] ?? null) === $providerInvoiceId) {
            return true;
        }

        if ($invoice->invoice_number === null || $invoice->sender_siren === null) {
            return false;
        }

        $reference = $this->documentReference($payload);

        $issuer = $reference['issuer'] ?? null;
        $siren = is_array($issuer) ? ($issuer['siren'] ?? null) : null;

        return ($reference['issuerAssignedId'] ?? null) === $invoice->invoice_number
            && $siren === $invoice->sender_siren;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function documentReference(array $payload): array
    {
        $json = $payload['json'] ?? null;
        $responses = is_array($json) ? ($json['responses'] ?? null) : null;

        if (! is_array($responses) || $responses === []) {
            return [];
        }

        $premiere = reset($responses);
        $reference = is_array($premiere) ? ($premiere['documentReference'] ?? null) : null;

        return is_array($reference) ? $reference : [];
    }

    public function failed(Throwable $e): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        if ($event !== null) {
            $this->markFailed($event, $e::class);
        }
    }

    private function markFailed(WebhookEvent $event, string $raison): void
    {
        // La raison ne reprend jamais le payload : il porte des identifiants
        // d'entreprise et des montants.
        $event->forceFill([
            'status' => WebhookEventStatus::Failed,
            'failed_reason' => $raison,
        ])->save();
    }
}
