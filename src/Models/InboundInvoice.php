<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Models;

use AmazScript\Einvoicing\Enums\InvoiceFormat;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Facture fournisseur reçue via la Plateforme Agréée.
 *
 * L'unicité porte sur le couple (provider, provider_invoice_id) : c'est la
 * garantie qu'un rejeu du webhook met à jour la ligne au lieu de la dupliquer.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $provider
 * @property string $provider_invoice_id
 * @property string|null $invoice_number
 * @property Carbon|null $invoice_date
 * @property string|null $sender_siren
 * @property string|null $sender_siret
 * @property string|null $sender_name
 * @property string|null $amount_total
 * @property string|null $amount_tax
 * @property string|null $currency
 * @property InvoiceFormat|null $format
 * @property Carbon|null $seen_at
 * @property array<string, mixed>|null $raw_metadata
 */
class InboundInvoice extends Model
{
    use HasUuids;

    protected $table = 'einvoicing_inbound_invoices';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'seen_at' => 'datetime',
            'amount_total' => 'decimal:2',
            'amount_tax' => 'decimal:2',
            'format' => InvoiceFormat::class,
            'raw_metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return HasMany<InvoiceFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(InvoiceFile::class, 'invoice_id');
    }

    /**
     * @return HasMany<Status, $this>
     */
    public function statuses(): HasMany
    {
        return $this->hasMany(Status::class, 'invoice_id');
    }
}
