<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Contracts;

use AmazScript\Einvoicing\Entities\BusinessEntity;
use Illuminate\Support\LazyCollection;

/**
 * Reads the companies declared on the platform and how they can be reached.
 *
 * Read-only. Declaring a company and registering it in the directory commits it
 * to receiving its official invoices somewhere, which is a decision the host
 * application makes explicitly rather than a side effect of a lookup.
 */
interface BusinessEntityGateway
{
    /**
     * Every declared company, walked lazily.
     *
     * @return LazyCollection<int, BusinessEntity>
     */
    public function all(?string $query = null): LazyCollection;

    /**
     * One company, or null when the platform does not know it.
     */
    public function find(string $businessEntityId): ?BusinessEntity;
}
