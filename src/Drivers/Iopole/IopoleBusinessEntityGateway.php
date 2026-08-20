<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Drivers\Iopole;

use AmazScript\Einvoicing\Contracts\BusinessEntityGateway;
use AmazScript\Einvoicing\Entities\BusinessEntity;
use AmazScript\Einvoicing\Entities\EntityIdentifier;
use AmazScript\Einvoicing\Entities\NetworkRegistration;
use AmazScript\Einvoicing\Exceptions\EinvoicingException;
use Illuminate\Support\LazyCollection;

/**
 * Reads companies as the Iopole platform exposes them.
 *
 * The shape below is taken from real responses: identifiers carry their network
 * registrations inline, and a registration's platformDetail is null when nobody
 * serves that address — the case that makes an invoice bounce.
 */
final class IopoleBusinessEntityGateway implements BusinessEntityGateway
{
    public function __construct(
        private readonly Client $client,
    ) {}

    /**
     * @return LazyCollection<int, BusinessEntity>
     */
    public function all(?string $query = null): LazyCollection
    {
        $parametres = $query !== null && $query !== '' ? ['q' => $query] : [];

        return $this->client
            ->paginate(Endpoints::businessEntities(), $parametres)
            ->map(fn (array $ligne): BusinessEntity => $this->toEntity($ligne));
    }

    public function find(string $businessEntityId): ?BusinessEntity
    {
        try {
            $reponse = $this->client->get(Endpoints::businessEntity($businessEntityId));
        } catch (EinvoicingException) {
            return null;
        }

        // Some endpoints answer with a list even for a single resource.
        if (array_is_list($reponse)) {
            $premier = $reponse[0] ?? null;
            $reponse = is_array($premier) ? $premier : [];
        }

        return isset($reponse['businessEntityId']) ? $this->toEntity($reponse) : null;
    }

    /**
     * @param  array<string, mixed>  $ligne
     */
    private function toEntity(array $ligne): BusinessEntity
    {
        $pays = is_array($ligne['countryIdentifier'] ?? null) ? $ligne['countryIdentifier'] : [];

        return new BusinessEntity(
            id: (string) ($ligne['businessEntityId'] ?? ''),
            name: (string) ($ligne['name'] ?? ''),
            type: $this->string($ligne['type'] ?? null),
            scope: $this->string($ligne['scope'] ?? null),
            country: $this->string($ligne['country'] ?? null),
            siren: $this->string($pays['siren'] ?? null),
            siret: $this->string($pays['siret'] ?? null),
            identifiers: $this->toIdentifiers($ligne['identifiers'] ?? null),
        );
    }

    /**
     * @return list<EntityIdentifier>
     */
    private function toIdentifiers(mixed $brut): array
    {
        if (! is_array($brut)) {
            return [];
        }

        $identifiants = [];

        foreach ($brut as $item) {
            if (! is_array($item) || ! isset($item['scheme'], $item['value'])) {
                continue;
            }

            $identifiants[] = new EntityIdentifier(
                id: $this->string($item['businessEntityIdentifierId'] ?? null),
                scheme: (string) $item['scheme'],
                value: (string) $item['value'],
                type: $this->string($item['type'] ?? null),
                registrations: $this->toRegistrations($item['networkRegistered'] ?? null),
            );
        }

        return $identifiants;
    }

    /**
     * @return list<NetworkRegistration>
     */
    private function toRegistrations(mixed $brut): array
    {
        if (! is_array($brut)) {
            return [];
        }

        $inscriptions = [];

        foreach ($brut as $item) {
            if (! is_array($item)) {
                continue;
            }

            $plateforme = is_array($item['platformDetail'] ?? null) ? $item['platformDetail'] : [];

            $inscriptions[] = new NetworkRegistration(
                network: (string) ($item['networkIdentifier'] ?? ''),
                status: $this->string($item['status'] ?? null),
                validFrom: $this->string($item['validFrom'] ?? null),
                validTo: $this->string($item['validTo'] ?? null),
                platformName: $this->string($plateforme['name'] ?? null),
                directoryId: $this->string($item['directoryId'] ?? null),
            );
        }

        return $inscriptions;
    }

    private function string(mixed $valeur): ?string
    {
        return is_string($valeur) && $valeur !== '' ? $valeur : null;
    }
}
