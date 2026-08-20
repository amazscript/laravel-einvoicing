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

    /**
     * Declares a legal unit on the platform.
     *
     * Declaring is not registering: the company exists on the platform
     * afterwards, and still receives nothing until an address of its is
     * registered on a network.
     *
     * @param  array<string, mixed>  $payload
     * @return string the identifier the platform assigns the entity
     */
    public function declareLegalUnit(array $payload): string;

    /**
     * Registers an address on a network, which is what makes it reachable.
     *
     * @param  array<string, mixed>  $payload
     */
    public function registerOnNetwork(string $scheme, string $value, string $network, array $payload = []): void;
}
