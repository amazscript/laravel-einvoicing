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
 * Records an authenticated delivery, once and only once.
 *
 * Delivery is at-least-once: the same thing may arrive several times. Uniqueness
 * is enforced by the database, never by reading beforehand — between a SELECT
 * and an INSERT a second delivery has all the time it needs. A constraint
 * violation therefore means "already received", which is a success and not an
 * error.
 */
final class InboundEventRecorder
{
    public function __construct(
        private readonly PayloadInterpreter $interpreter,
        private readonly TenantResolver $resolver,
    ) {}

    /**
     * Returns the recorded event, or null when the delivery was already known.
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
                // An unrouted event is not lost: it stays replayable.
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
     * The payload is kept in full: it is the only material available to replay
     * an event whose processing failed.
     *
     * @return array<string, mixed>
     */
    private function payloadToStore(InboundRequest $request): array
    {
        if ($request->payload !== []) {
            return $request->payload;
        }

        // Unreadable body: enough is kept to investigate anyway.
        return [
            'raw' => $request->rawBody,
            'multipart' => $request->isMultipart,
        ];
    }

    private function isDuplicate(QueryException $e): bool
    {
        // 23000 and 23505 cover unique constraint violations on MySQL,
        // PostgreSQL and SQLite.
        return in_array($e->getCode(), ['23000', '23505'], true);
    }
}
