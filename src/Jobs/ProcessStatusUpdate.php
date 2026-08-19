<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Jobs;

use AmazScript\Einvoicing\Contracts\StatusMapper;
use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Events\InvoiceStatusUpdated;
use AmazScript\Einvoicing\Events\OutboundInvoiceNotDelivered;
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
 * Transforme un événement encaissé en statut exploitable.
 *
 * Rejouable sans effet de bord : l'écriture passe par updateOrCreate sur le
 * couple (provider, provider_status_id), si bien qu'une seconde exécution met à
 * jour la même ligne au lieu d'en créer une seconde.
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
     * Délais entre tentatives, en secondes : la plateforme recommande un recul
     * exponentiel, notamment après un 429.
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
            // Rien d'exploitable : on le dit plutôt que de faire semblant.
            $this->markFailed($event, 'payload sans statut exploitable');

            return;
        }

        $status = Status::query()->updateOrCreate(
            [
                'provider' => $this->provider,
                'provider_status_id' => $attributs['provider_status_id'],
            ],
            [
                'invoice_id' => $this->linkedInvoiceId($attributs),
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
     * Un statut de rejet signale que la facture n'a pas atteint son destinataire.
     *
     * Observé en conditions réelles : un destinataire absent de l'annuaire produit
     * un REJECTED portant `rejectionDetail.reason = ROUTING_FAILURE`. C'est un
     * incident à traiter — la facture est restée en chemin — d'où un événement
     * distinct de la simple mise à jour de statut.
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
     * Retrouve la facture concernée par un statut.
     *
     * L'identifiant technique est tenté d'abord, mais il ne suffit pas : un même
     * document porte un identifiant distinct de chaque côté de la chaîne, celui
     * du statut désignant la facture émise et non celle qui a été reçue. On se
     * rabat donc sur le numéro attribué par l'émetteur, qualifié par son SIREN —
     * sans lui, deux fournisseurs numérotant pareil verraient leurs statuts
     * mélangés, ce qui vaudrait mieux ne pas rattacher du tout.
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
     * Les deux critères sont exigés ensemble : un numéro seul n'identifie rien.
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
     * Échec définitif : l'événement reste en base, marqué et rejouable. Le perdre
     * silencieusement serait pire que l'échec lui-même.
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
        // La raison ne reprend jamais le contenu du payload : il porte des
        // identifiants d'entreprise et des montants.
        $event->forceFill([
            'status' => WebhookEventStatus::Failed,
            'failed_reason' => $raison,
        ])->save();
    }
}
