<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Api;

use AmazScript\Einvoicing\Contracts\BusinessEntityGateway;
use AmazScript\Einvoicing\Entities\BusinessEntity;
use Illuminate\Support\LazyCollection;

/**
 * The companies declared on the platform, and whether they can be invoiced.
 */
final class EntityQuery
{
    public function __construct(
        private readonly BusinessEntityGateway $gateway,
    ) {}

    /**
     * @return LazyCollection<int, BusinessEntity>
     */
    public function all(?string $query = null): LazyCollection
    {
        return $this->gateway->all($query);
    }

    public function find(string $businessEntityId): ?BusinessEntity
    {
        return $this->gateway->find($businessEntityId);
    }

    /**
     * Companies an invoice can actually reach.
     *
     * @return LazyCollection<int, BusinessEntity>
     */
    public function reachable(): LazyCollection
    {
        return $this->all()->filter(static fn (BusinessEntity $e): bool => $e->isReachable());
    }

    /**
     * Companies declared but not reachable — the ones worth looking at, since
     * an invoice addressed to them bounces with "No route found".
     *
     * @return LazyCollection<int, BusinessEntity>
     */
    public function unreachable(): LazyCollection
    {
        return $this->all()->reject(static fn (BusinessEntity $e): bool => $e->isReachable());
    }
}
