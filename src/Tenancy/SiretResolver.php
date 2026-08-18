<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Tenancy;

use AmazScript\Einvoicing\Contracts\TenantResolver;
use AmazScript\Einvoicing\Events\TenantResolutionFailed;
use AmazScript\Einvoicing\Models\Tenant;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;

/**
 * Résolution du destinataire, par ordre de fiabilité décroissante.
 *
 *   1. identifiant externe renvoyé par la plateforme (idPath)
 *   2. SIRET du destinataire — désigne un établissement précis
 *   3. SIREN du destinataire — désigne l'entreprise
 *   4. tenant par défaut, si le parc n'en compte qu'un seul actif
 *
 * Principe : mal router est pire que ne pas router. Dès qu'une étape laisse
 * plusieurs candidats possibles, on renonce plutôt que de trancher au hasard —
 * l'événement part alors en UNROUTED, où il reste rejouable.
 */
final class SiretResolver implements TenantResolver
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly LoggerInterface $logger,
    ) {}

    public function resolve(RoutingKeys $keys): ?Tenant
    {
        $tenant = $this->byExternalId($keys)
            ?? $this->bySiret($keys)
            ?? $this->bySiren($keys);

        if ($tenant instanceof Tenant) {
            return $tenant;
        }

        if ($this->isAmbiguousSiren($keys)) {
            return $this->fail($keys, 'plusieurs tenants actifs partagent ce SIREN');
        }

        return $this->defaultTenant($keys) ?? $this->fail($keys, 'aucune clé de routage exploitable');
    }

    private function byExternalId(RoutingKeys $keys): ?Tenant
    {
        if ($keys->externalId === null || $keys->externalId === '') {
            return null;
        }

        return $this->activeTenants()->whereKey($keys->externalId)->first();
    }

    private function bySiret(RoutingKeys $keys): ?Tenant
    {
        $siret = $keys->normalizedSiret();

        return $siret === null ? null : $this->activeTenants()->where('siret', $siret)->first();
    }

    /**
     * Le SIREN transmis prime ; à défaut, celui déduit du SIRET permet de router
     * une facture adressée à un établissement inconnu du package.
     */
    private function bySiren(RoutingKeys $keys): ?Tenant
    {
        $siren = $keys->normalizedSiren() ?? $keys->sirenFromSiret();

        if ($siren === null) {
            return null;
        }

        $candidats = $this->activeTenants()->where('siren', $siren);

        // Un seul candidat, sinon on renonce : voir isAmbiguousSiren().
        return $candidats->count() === 1 ? $candidats->first() : null;
    }

    private function isAmbiguousSiren(RoutingKeys $keys): bool
    {
        $siren = $keys->normalizedSiren() ?? $keys->sirenFromSiret();

        return $siren !== null && $this->activeTenants()->where('siren', $siren)->count() > 1;
    }

    /**
     * Dernier recours, réservé au parc mono-tenant. C'est un filet, pas une
     * stratégie : l'avertissement doit rester visible en exploitation.
     */
    private function defaultTenant(RoutingKeys $keys): ?Tenant
    {
        $actifs = $this->activeTenants();

        if ($actifs->count() !== 1) {
            return null;
        }

        $this->logger->warning(
            'einvoicing: routage sur le tenant unique par défaut, aucune clé ne correspondait',
            ['siret' => $this->mask($keys->normalizedSiret()), 'siren' => $this->mask($keys->normalizedSiren())],
        );

        return $actifs->first();
    }

    private function fail(RoutingKeys $keys, string $reason): null
    {
        $this->events->dispatch(new TenantResolutionFailed($keys, $reason));

        return null;
    }

    /**
     * @return Builder<Tenant>
     */
    private function activeTenants(): Builder
    {
        return Tenant::query()->where('active', true);
    }

    /**
     * Un identifiant d'entreprise ne doit pas apparaître en clair dans les
     * journaux ; les quatre derniers chiffres suffisent au diagnostic.
     */
    private function mask(?string $value): ?string
    {
        return $value === null ? null : str_repeat('*', max(0, strlen($value) - 4)).substr($value, -4);
    }
}
