<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Statut de cycle de vie émis par la Plateforme Agréée.
 *
 * Les codes ne sont pas modélisés en enum : ils dépendent de la plateforme et
 * évoluent sans préavis. Les inventer figerait une liste qui n'est pas la nôtre.
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
