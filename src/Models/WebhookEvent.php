<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Models;

use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace de déduplication d'un événement webhook.
 *
 * La livraison est at-least-once : la contrainte unique sur event_id, portée par
 * la base, est la seule garantie fiable qu'un événement n'est traité qu'une fois.
 */
class WebhookEvent extends Model
{
    use HasUuids;

    protected $table = 'einvoicing_webhook_events';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WebhookEventStatus::class,
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
