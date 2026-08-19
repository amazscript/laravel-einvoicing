<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Api;

use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use Illuminate\Support\LazyCollection;

/**
 * Searches the directory of reachable companies.
 *
 * The walk is lazy: the directory holds millions of entries, and loading them
 * all would make no sense.
 */
final class DirectoryQuery
{
    public function __construct(
        private readonly InvoiceGateway $gateway,
    ) {}

    /**
     * @return LazyCollection<int, array<mixed>>
     */
    public function search(string $query): LazyCollection
    {
        return $this->gateway->searchDirectory($query);
    }
}
