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

    public function handle(Dispatcher $events, InvoiceGateway $gateway, InvoiceFileStore $store): void
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

        // Le webhook ne porte aucune métadonnée comptable : on va les chercher.
        // Leur absence n'est pas bloquante, la facture existe déjà.
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
     * Complète la facture avec ce que la plateforme sait d'elle : numéro, date,
     * montants, émetteur, format d'origine.
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
     * Télécharge et range les fichiers. Un fichier déjà stocké, reconnu à son
     * empreinte, n'est pas retéléchargé ; l'échec de l'un n'empêche pas les
     * autres, la facture restant exploitable sans ses pièces.
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
