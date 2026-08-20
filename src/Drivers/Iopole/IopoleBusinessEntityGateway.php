<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Drivers\Iopole;

use AmazScript\Einvoicing\Contracts\BusinessEntityGateway;
use AmazScript\Einvoicing\Entities\BusinessEntity;
use AmazScript\Einvoicing\Entities\EntityIdentifier;
use AmazScript\Einvoicing\Entities\NetworkRegistration;
use AmazScript\Einvoicing\Exceptions\EinvoicingException;
use DateTimeImmutable;
use Illuminate\Support\LazyCollection;

/**
 * Reads companies as the Iopole platform exposes them.
 *
 * The shape below is taken from real responses: identifiers carry their network
 * registrations inline, and each registration exposes the directory address the
 * company is actually reachable at — which uses a different scheme (0225) from
 * the legal identifier (0002) sitting next to it.
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

    public function declareLegalUnit(array $payload): string
    {
        $reponse = $this->client->post(Endpoints::declareLegalUnit(), $payload);

        if (array_is_list($reponse)) {
            $premier = $reponse[0] ?? null;
            $reponse = is_array($premier) ? $premier : [];
        }

        $id = $reponse['id'] ?? $reponse['businessEntityId'] ?? null;

        // No exception on a missing identifier: the entity was created either
        // way, and reporting failure would invite a retry that duplicates it.
        // An empty string says "created, unnamed" — the caller can find it back
        // by SIREN.
        return is_string($id) ? $id : '';
    }

    public function registerOnNetwork(string $scheme, string $value, string $network, array $payload = []): void
    {
        // An empty PHP array encodes as `[]`, and the endpoint wants an object:
        // it answers "Expected object, received array" and registers nothing.
        $this->client->post(
            Endpoints::registerOnNetwork($scheme, $value, $network),
            $payload === [] ? ['selfBilling' => false] : $payload,
        );
    }

    public function configureVatRegime(string $businessEntityId, string $vatRegime): void
    {
        $this->client->post(Endpoints::configureEntity($businessEntityId), ['vatRegime' => $vatRegime]);
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
            // Without a directory address the entry routes nothing, so it is
            // not an entry as far as reachability is concerned.
            if (! is_array($item) || ! isset($item['directoryAddress'])) {
                continue;
            }

            $inscriptions[] = new NetworkRegistration(
                directoryId: (string) ($item['directoryId'] ?? ''),
                directoryAddress: (string) $item['directoryAddress'],
                networkIdentifier: $this->string($item['networkIdentifier'] ?? null),
                validFrom: $this->date($item['validFrom'] ?? null),
                isSelfBilling: ($item['isSelfBilling'] ?? false) === true,
            );
        }

        return $inscriptions;
    }

    /**
     * The platform sends plain dates ("2026-08-19"); anything else is ignored
     * rather than guessed at.
     */
    private function date(mixed $valeur): ?DateTimeImmutable
    {
        if (! is_string($valeur) || $valeur === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);

        return $date === false ? null : $date;
    }

    private function string(mixed $valeur): ?string
    {
        return is_string($valeur) && $valeur !== '' ? $valeur : null;
    }
}
