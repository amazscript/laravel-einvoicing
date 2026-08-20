<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Jobs;

use AmazScript\Einvoicing\Contracts\StatusMapper;
use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Events\InvoiceStatusUpdated;
use AmazScript\Einvoicing\Events\OutboundInvoiceNotDelivered;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\OutboundInvoice;
use AmazScript\Einvoicing\Models\Status;
use AmazScript\Einvoicing\Models\WebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Turns a banked event into a usable lifecycle status.
 *
 * Replayable without side effects: writing goes through updateOrCreate on the
 * (provider, provider_status_id) pair, so a second run updates the same row
 * instead of adding another.
 */
final class ProcessStatusUpdate implements ShouldQueue
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
     * Delays between attempts, in seconds: the platform recommends exponential
     * backoff, in particular after a 429.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(StatusMapper $mapper, Dispatcher $events): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        if ($event === null) {
            return;
        }

        $attributs = $mapper->map($event->payload ?? []);

        if ($attributs === null) {
            // Nothing usable: say so rather than pretend otherwise.
            $this->markFailed($event, 'payload carries no usable status');

            return;
        }

        $status = Status::query()->updateOrCreate(
            [
                'provider' => $this->provider,
                'provider_status_id' => $attributs['provider_status_id'],
            ],
            [
                'invoice_id' => $this->linkedInvoiceId($attributs),
                'outbound_invoice_id' => $this->linkedOutboundInvoiceId($attributs),
                'code' => $attributs['code'],
                'value' => $attributs['value'],
                'description' => $attributs['description'],
                'dest_type' => $attributs['dest_type'],
                'occurred_at' => $attributs['occurred_at'],
                'payload' => $attributs['payload'],
            ],
        );

        $event->forceFill([
            'status' => WebhookEventStatus::Processed,
            'processed_at' => Carbon::now(),
            'failed_reason' => null,
        ])->save();

        $events->dispatch(new InvoiceStatusUpdated($status));

        $this->announceDeliveryFailure($events, $event, $attributs);
    }

    /**
     * A rejection status means the invoice never reached its recipient.
     *
     * Observed in real conditions: a recipient missing from the directory yields
     * a REJECTED carrying `rejectionDetail.reason = ROUTING_FAILURE`. That is an
     * incident to act on — the invoice is stuck in transit — hence an event
     * distinct from a plain status update.
     *
     * @param  array{provider_status_id: string, provider_invoice_id: string|null, code: string, value: string|null, description: string|null, dest_type: string|null, occurred_at: string|null, payload: array<string, mixed>}  $attributs
     */
    private function announceDeliveryFailure(Dispatcher $events, WebhookEvent $event, array $attributs): void
    {
        if ($attributs['code'] !== 'REJECTED') {
            return;
        }

        $detail = $this->rejectionDetail($attributs['payload']);

        $events->dispatch(new OutboundInvoiceNotDelivered(
            $event,
            $attributs['provider_invoice_id'],
            is_string($detail['reason'] ?? null) ? $detail['reason'] : null,
            is_string($detail['message'] ?? null) ? $detail['message'] : null,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function rejectionDetail(array $payload): array
    {
        $json = $payload['json'] ?? null;
        $responses = is_array($json) ? ($json['responses'] ?? null) : null;

        if (! is_array($responses) || $responses === []) {
            return [];
        }

        $premiere = reset($responses);
        $detail = is_array($premiere) ? ($premiere['rejectionDetail'] ?? null) : null;

        return is_array($detail) ? $detail : [];
    }

    /**
     * Finds the invoice a status concerns.
     *
     * The technical identifier is tried first but does not suffice: the same
     * document carries a different identifier on each side of the chain, the one
     * in a status designating the invoice as issued rather than as received. The
     * fallback is the number the issuer assigned, qualified by their SIREN —
     * without which two suppliers numbering alike would see their statuses mixed,
     * which would be worse than not linking at all.
     *
     * @param  array{provider_invoice_id: string|null, issuer_invoice_number: string|null, issuer_siren: string|null, ...}  $attributs
     */
    private function linkedInvoiceId(array $attributs): ?string
    {
        $providerInvoiceId = $attributs['provider_invoice_id'];

        if ($providerInvoiceId !== null) {
            $invoice = InboundInvoice::query()
                ->where('provider', $this->provider)
                ->where('provider_invoice_id', $providerInvoiceId)
                ->first();

            if ($invoice instanceof InboundInvoice) {
                return $invoice->id;
            }
        }

        return $this->byIssuerReference(
            $attributs['issuer_invoice_number'],
            $attributs['issuer_siren'],
        )?->id;
    }

    /**
     * The sent invoice a status reports on, if it reports on one.
     *
     * One callback URL carries both directions, so a status arriving here may
     * concern an invoice we sent as easily as one we received. The platform's
     * own identifier is what tells them apart; nothing else is guessed at.
     *
     * @param  array{provider_invoice_id: string|null, ...}  $attributs
     */
    private function linkedOutboundInvoiceId(array $attributs): ?string
    {
        $providerInvoiceId = $attributs['provider_invoice_id'];

        if ($providerInvoiceId === null) {
            return null;
        }

        return OutboundInvoice::query()
            ->where('provider', $this->provider)
            ->where('provider_invoice_id', $providerInvoiceId)
            ->first()?->id;
    }

    /**
     * Both criteria are required together: a number alone identifies nothing.
     */
    private function byIssuerReference(?string $numero, ?string $siren): ?InboundInvoice
    {
        if ($numero === null || $siren === null) {
            return null;
        }

        return InboundInvoice::query()
            ->where('provider', $this->provider)
            ->where('invoice_number', $numero)
            ->where('sender_siren', $siren)
            ->first();
    }

    /**
     * Terminal failure: the event stays in the database, marked and replayable.
     * Losing it silently would be worse than the failure itself.
     */
    public function failed(Throwable $e): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        if ($event !== null) {
            $this->markFailed($event, $e::class);
        }
    }

    private function markFailed(WebhookEvent $event, string $raison): void
    {
        // The reason never quotes the payload: it carries company identifiers
        // and amounts.
        $event->forceFill([
            'status' => WebhookEventStatus::Failed,
            'failed_reason' => $raison,
        ])->save();
    }
}
