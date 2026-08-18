<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Webhook;

use AmazScript\Einvoicing\Contracts\PayloadInterpreter;
use AmazScript\Einvoicing\Contracts\TenantResolver;
use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Consigne une livraison authentifiée, une fois et une seule.
 *
 * La livraison est garantie « au moins une fois » : la même chose peut arriver
 * plusieurs fois. L'unicité est portée par la base, jamais par une lecture
 * préalable — entre le SELECT et l'INSERT, une seconde livraison a le temps de
 * passer. Une violation de contrainte signifie donc « déjà reçu », ce qui est un
 * succès et non une erreur.
 */
final class InboundEventRecorder
{
    public function __construct(
        private readonly PayloadInterpreter $interpreter,
        private readonly TenantResolver $resolver,
    ) {}

    /**
     * Retourne l'événement consigné, ou null si cette livraison était déjà connue.
     */
    public function record(InboundRequest $request): ?WebhookEvent
    {
        $cle = $this->interpreter->idempotencyKey($request);
        $tenant = $this->resolver->resolve($this->interpreter->routingKeys($request));

        try {
            return WebhookEvent::query()->create([
                'event_id' => $cle,
                'event_type' => $this->interpreter->eventType($request),
                'tenant_id' => $tenant?->id,
                // Un événement non routé n'est pas perdu : il reste rejouable.
                'status' => $tenant === null ? WebhookEventStatus::Unrouted : WebhookEventStatus::Received,
                'payload' => $this->payloadToStore($request),
                'received_at' => Carbon::now(),
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Le payload est conservé intégralement : c'est la seule matière disponible
     * pour rejouer un événement dont le traitement a échoué.
     *
     * @return array<string, mixed>
     */
    private function payloadToStore(InboundRequest $request): array
    {
        if ($request->payload !== []) {
            return $request->payload;
        }

        // Corps illisible : on garde tout de même de quoi enquêter.
        return [
            'raw' => $request->rawBody,
            'multipart' => $request->isMultipart,
        ];
    }

    private function isDuplicate(QueryException $e): bool
    {
        // 23000 et 23505 couvrent la violation de contrainte unique sur MySQL,
        // PostgreSQL et SQLite.
        return in_array($e->getCode(), ['23000', '23505'], true);
    }
}
