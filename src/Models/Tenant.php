<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Links a record of the host application to an accredited platform account.
 *
 * SIRET and SIREN are the webhook's routing keys: the platform accepts a single
 * callback URL for the whole estate.
 *
 * @property string $id
 * @property string $tenantable_type
 * @property string $tenantable_id
 * @property string $customer_id
 * @property string $siren
 * @property string|null $siret
 * @property bool $active
 */
class Tenant extends Model
{
    use HasUuids;

    protected $table = 'einvoicing_tenants';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'encrypted',
            'active' => 'boolean',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function tenantable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<InboundInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(InboundInvoice::class, 'tenant_id');
    }
}
