<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Models;

use AmazScript\Einvoicing\Enums\OutboundStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invoice handed to the platform for delivery.
 *
 * The send endpoint accepts no idempotency key, so uniqueness rests on
 * (tenant_id, file_hash): sending the same document twice is a retry, and the
 * database is what says so — never a read followed by a write.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $provider
 * @property string|null $provider_invoice_id
 * @property string $file_hash
 * @property string $file_name
 * @property int $file_size
 * @property OutboundStatus $status
 * @property string|null $failure_reason
 * @property Carbon|null $sent_at
 */
class OutboundInvoice extends Model
{
    use HasUuids;

    protected $table = 'einvoicing_outbound_invoices';

    protected $guarded = [];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OutboundStatus::class,
            'sent_at' => 'datetime',
            'file_size' => 'integer',
        ];
    }
}
