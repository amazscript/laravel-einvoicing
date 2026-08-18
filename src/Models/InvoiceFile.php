<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Models;

use AmazScript\Einvoicing\Enums\InvoiceFileKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceFile extends Model
{
    use HasUuids;

    protected $table = 'einvoicing_invoice_files';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => InvoiceFileKind::class,
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
