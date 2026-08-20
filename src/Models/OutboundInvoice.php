<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Models;

use AmazScript\Einvoicing\Enums\OutboundStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
     * The lifecycle the platform reports for this invoice.
     *
     * Sent is not delivered: what follows arrives as statuses, and a REJECTED
     * among them means the document never reached its recipient.
     *
     * Declared without an order — chaining one here costs the generic type and
     * every caller its completion.
     *
     * @return HasMany<Status, $this>
     */
    public function statuses(): HasMany
    {
        return $this->hasMany(Status::class, 'outbound_invoice_id');
    }

    /**
     * The last thing the platform said about it, if it said anything.
     *
     * Ordered by identifier as a tie-break: the platform stamps to the second,
     * and two lifecycle steps often share one.
     */
    public function lastStatus(): ?Status
    {
        $dernier = Status::query()
            ->where('outbound_invoice_id', $this->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('provider_status_id')
            ->first();

        // Explicit narrowing: ordering goes through the query builder, which
        // loses the model type on the way back.
        return $dernier instanceof Status ? $dernier : null;
    }

    /**
     * Lifecycle codes seen to mean the invoice will not be delivered.
     *
     * Deliberately not an enum: the platform's codes are open-ended, and a
     * closed list would silently drop a genuine one. This is what has actually
     * been observed, and it is meant to grow.
     *
     * REJECTED  — routing failed, no recipient could be reached.
     * UNACCEPTABLE — the document itself was refused (observed with
     *                UNKNOWN_INVOICE_FLAVOR on a file that was not valid Factur-X).
     */
    public const FAILURE_CODES = ['REJECTED', 'UNACCEPTABLE'];

    /**
     * Whether the platform reported that this invoice will not arrive.
     *
     * Only what it actually reported: silence is not success, and is not
     * treated as such — see OutboundInvoiceQuery::awaitingDelivery().
     */
    public function deliveryFailed(): bool
    {
        return Status::query()
            ->where('outbound_invoice_id', $this->id)
            ->whereIn('code', self::FAILURE_CODES)
            ->count() > 0;
    }

    /**
     * Why it will not arrive, in the platform's own words, or null.
     */
    public function failureCode(): ?string
    {
        $echec = Status::query()
            ->where('outbound_invoice_id', $this->id)
            ->whereIn('code', self::FAILURE_CODES)
            ->orderByDesc('occurred_at')
            ->first();

        return $echec instanceof Status ? ($echec->value ?? $echec->code) : null;
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
