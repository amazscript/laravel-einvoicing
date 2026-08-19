<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A lifecycle status issued by the accredited platform.
 *
 * The codes are not modelled as an enum: they belong to the platform and change
 * without notice. Enumerating them would freeze a list that is not ours.
 *
 * @property string $id
 * @property string|null $invoice_id
 * @property string $provider
 * @property string $provider_status_id
 * @property string $code
 * @property string $value
 * @property string|null $description
 * @property string|null $dest_type
 * @property Carbon|null $occurred_at
 * @property array<string, mixed>|null $payload
 */
class Status extends Model
{
    use HasUuids;

    protected $table = 'einvoicing_statuses';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<InboundInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(InboundInvoice::class, 'invoice_id');
    }
}
