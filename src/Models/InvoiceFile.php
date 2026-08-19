<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Models;

use AmazScript\Einvoicing\Enums\InvoiceFileKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to a received invoice: original XML, readable PDF or attachment.
 *
 * @property string $id
 * @property string $invoice_id
 * @property string|null $provider_file_id
 * @property InvoiceFileKind $kind
 * @property string $disk
 * @property string $path
 * @property string $checksum
 */
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
