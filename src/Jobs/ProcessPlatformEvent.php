<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Jobs;

use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Events\InboundInvoiceInvalid;
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
 * Traite les événements de la plateforme qui ne sont ni une facture ni un statut.
 *
 * Aujourd'hui : le refus d'une facture entrante. Ces événements suivent un
 * format générique — un identifiant, un type, un horodatage, une charge utile —
 * distinct de celui des factures et des statuts.
 */
final class ProcessPlatformEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly string $webhookEventId,
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
        $donnees = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];

        $events->dispatch(new InboundInvoiceInvalid(
            $event,
            $this->string($donnees['invoiceId'] ?? null),
            $this->string($donnees['invoiceNumber'] ?? null),
            $this->validationErrors($donnees),
        ));

        $event->forceFill([
            'status' => WebhookEventStatus::Processed,
            'processed_at' => Carbon::now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @return list<array{code: string|null, message: string|null}>
     */
    private function validationErrors(array $donnees): array
    {
        $erreurs = $donnees['validationErrors'] ?? null;

        if (! is_array($erreurs)) {
            return [];
        }

        $liste = [];

        foreach ($erreurs as $erreur) {
            if (! is_array($erreur)) {
                continue;
            }

            $liste[] = [
                'code' => $this->string($erreur['code'] ?? null),
                'message' => $this->string($erreur['message'] ?? null),
            ];
        }

        return $liste;
    }

    public function failed(Throwable $e): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        $event?->forceFill([
            'status' => WebhookEventStatus::Failed,
            'failed_reason' => $e::class,
        ])->save();
    }

    private function string(mixed $valeur): ?string
    {
        return is_string($valeur) && $valeur !== '' ? $valeur : null;
    }
}
