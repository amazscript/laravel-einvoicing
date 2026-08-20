<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Api;

use AmazScript\Einvoicing\Contracts\BusinessEntityGateway;
use AmazScript\Einvoicing\Entities\BusinessEntity;
use AmazScript\Einvoicing\Enums\EntityScope;
use AmazScript\Einvoicing\Enums\InvoicingNetwork;
use AmazScript\Einvoicing\Enums\StreamDirection;
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

    /**
     * Sets a company's VAT regime.
     *
     * Needed before any e-reporting: without it the platform answers "The
     * business entity does not have a VAT regime specified". Passing the regime
     * when declaring the entity is not enough — it takes this separate call.
     */
    public function setVatRegime(string $businessEntityId, VatRegime $regime): void
    {
        $this->gateway->configureVatRegime($businessEntityId, $regime->value);
    }

    /**
     * Claims a company for your operator account.
     *
     * This is what makes its invoices arrive on your callback URL rather than
     * somewhere else: without the link, the platform knows the company but does
     * not hand you its traffic.
     *
     * @param  StreamDirection|null  $direction  narrows the claim to one way; both when omitted
     * @param  array<string, string>  $headers  added to every webhook call for this entity
     */
    public function claim(
        string $businessEntityId,
        ?StreamDirection $direction = null,
        array $headers = [],
    ): void {
        $this->gateway->claimEntity($businessEntityId, $this->claimPayload($direction, $headers));
    }

    /**
     * Changes an existing claim — its direction, or the headers it carries.
     *
     * **Replaces, does not merge.** Whatever headers the relation already
     * carried are dropped unless passed again, and those headers are how the
     * platform tags webhook calls for this entity: losing them breaks delivery.
     * Read the current relation first, or pass the full set.
     *
     * @param  array<string, string>  $headers
     */
    public function updateClaim(
        string $businessEntityId,
        ?StreamDirection $direction = null,
        array $headers = [],
    ): void {
        $this->gateway->claimEntity($businessEntityId, $this->claimPayload($direction, $headers), update: true);
    }

    /**
     * Releases a company from your account.
     *
     * Its invoices stop reaching you. Nothing is deleted on the platform — the
     * company stays declared and reachable, it simply is no longer yours to
     * handle.
     */
    public function release(string $businessEntityId): void
    {
        $this->gateway->releaseEntity($businessEntityId);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function claimPayload(?StreamDirection $direction, array $headers): array
    {
        $payload = [];

        if ($headers !== []) {
            $payload['data'] = ['header' => array_map(
                static fn (string $cle, string $valeur): array => ['key' => $cle, 'value' => $valeur],
                array_keys($headers),
                array_values($headers),
            )];
        }

        if ($direction instanceof StreamDirection) {
            $payload['direction'] = $direction->value;
        }

        return $payload;
    }
}
