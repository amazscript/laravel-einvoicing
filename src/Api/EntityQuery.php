<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Api;

use AmazScript\Einvoicing\Contracts\BusinessEntityGateway;
use AmazScript\Einvoicing\Entities\BusinessEntity;
use AmazScript\Einvoicing\Enums\EntityScope;
use AmazScript\Einvoicing\Enums\InvoicingNetwork;
use AmazScript\Einvoicing\Enums\VatRegime;
use DateTimeInterface;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;

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

    /**
     * Declares a company on the platform.
     *
     * Declaring is not registering. Afterwards the platform knows the company,
     * and nobody can invoice it yet: reachability comes from register(), and the
     * gap between the two is exactly what makes an invoice bounce with
     * "No route found".
     *
     * @param  string  $siren  nine digits identifying the company
     * @return string the identifier the platform assigns
     */
    public function declareLegalUnit(
        string $name,
        string $siren,
        EntityScope $scope = EntityScope::PrivateTaxPayer,
        ?VatRegime $vatRegime = null,
        string $country = 'FR',
    ): string {
        $chiffres = preg_replace('/\D/', '', $siren) ?? '';

        if (strlen($chiffres) !== 9) {
            throw new InvalidArgumentException("A SIREN has nine digits, got: {$siren}");
        }

        return $this->gateway->declareLegalUnit(array_filter([
            'name' => $name,
            'country' => $country,
            'scope' => $scope->value,
            'vatRegime' => $vatRegime?->value,
            // 0002 is the SIRENE scheme: the legal identifier, not the routing
            // address the network later assigns.
            'identifierScheme' => '0002',
            'identifierValue' => $chiffres,
            'countryIdentifier' => ['siren' => $chiffres],
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * Registers a company's address on a network, making it reachable.
     *
     * This is the step that turns a declared company into one that can actually
     * receive invoices.
     *
     * @param  string  $siren  nine digits, or a fourteen-digit SIRET
     */
    public function register(
        string $siren,
        InvoicingNetwork $network = InvoicingNetwork::DomesticFr,
        ?DateTimeInterface $from = null,
        bool $selfBilling = false,
    ): void {
        $chiffres = preg_replace('/\D/', '', $siren) ?? '';

        $scheme = match (strlen($chiffres)) {
            9, 14 => '0002',
            default => throw new InvalidArgumentException("Expected a SIREN or a SIRET, got: {$siren}"),
        };

        $this->gateway->registerOnNetwork($scheme, $chiffres, $network->value, array_filter([
            'validityStartDate' => $from?->format('Y-m-d'),
            // Always sent: an empty body encodes as `[]` where the endpoint
            // expects an object, and the registration silently does not happen.
            'selfBilling' => $selfBilling,
        ], static fn (mixed $v): bool => $v !== null));
    }
}
