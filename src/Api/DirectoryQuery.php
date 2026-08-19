<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Api;

use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use Illuminate\Support\LazyCollection;

/**
 * Recherche dans l'annuaire des entreprises joignables.
 *
 * Le parcours est paresseux : l'annuaire compte des millions d'entrées, les
 * charger toutes n'aurait aucun sens.
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
