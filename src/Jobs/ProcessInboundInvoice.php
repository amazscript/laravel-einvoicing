<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Jobs;

use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Events\InboundInvoiceReceived;
use AmazScript\Einvoicing\Models\InboundInvoice;
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
 * Consigne une facture fournisseur reçue.
 *
 * La livraison ne porte que le strict nécessaire : un identifiant de facture et
 * le document lui-même. Les métadonnées comptables — numéro, date, montants,
 * émetteur — ne sont pas dans le webhook ; elles se récupèrent auprès de la
 * plateforme, ce que fera le lot suivant. On enregistre donc d'abord l'existence
 * de la facture, sans inventer ce qu'on ne sait pas.
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

    public function handle(Dispatcher $events): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        if ($event === null) {
            return;
        }

        $payload = $event->payload ?? [];
        $providerInvoiceId = $payload['invoiceId'] ?? null;

        if (! is_string($providerInvoiceId) || $providerInvoiceId === '') {
            $this->markFailed($event, 'livraison sans identifiant de facture');

            return;
        }

        // Rejouable sans effet de bord : une seconde exécution met à jour la même
        // ligne. C'est la garantie qu'un retry ne double pas une facture.
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

        $this->attachOrphanStatuses($invoice, $providerInvoiceId);

        $event->forceFill([
            'status' => WebhookEventStatus::Processed,
            'processed_at' => Carbon::now(),
            'failed_reason' => null,
        ])->save();

        $events->dispatch(new InboundInvoiceReceived($invoice));
    }

    /**
     * Un statut arrive parfois avant la facture qu'il concerne : il a alors été
     * conservé sans rattachement. On le raccroche maintenant.
     */
    private function attachOrphanStatuses(InboundInvoice $invoice, string $providerInvoiceId): void
    {
        // where(..., null) plutôt que whereNull : la seconde passe par l'appel
        // magique d'Eloquent, qui fait perdre le type du modèle à l'analyse.
        $orphelins = Status::query()
            ->where('provider', $this->provider)
            ->where('invoice_id', null)
            ->get();

        foreach ($orphelins as $status) {
            $payload = $status->payload ?? [];

            if (($payload['invoiceId'] ?? null) === $providerInvoiceId) {
                $status->forceFill(['invoice_id' => $invoice->id])->save();
            }
        }
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
