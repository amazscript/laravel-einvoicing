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
 * Resolves the recipient, from the most reliable key to the least.
 *
 *   1. external identifier echoed back by the platform (idPath)
 *   2. recipient SIRET — designates one specific establishment
 *   3. recipient SIREN — designates the company
 *   4. default tenant, when only one is active in the whole estate
 *
 * Guiding rule: misrouting is worse than not routing. As soon as a step leaves
 * more than one candidate, resolution gives up rather than picking arbitrarily —
 * the delivery then lands in UNROUTED, where it stays replayable.
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
            return $this->fail($keys, 'several active tenants share this SIREN');
        }

        return $this->defaultTenant($keys) ?? $this->fail($keys, 'no usable routing key');
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
     * The transmitted SIREN wins; failing that, the one derived from the SIRET
     * routes an invoice addressed to an establishment unknown to the package.
     */
    private function bySiren(RoutingKeys $keys): ?Tenant
    {
        $siren = $keys->normalizedSiren() ?? $keys->sirenFromSiret();

        if ($siren === null) {
            return null;
        }

        $candidats = $this->activeTenants()->where('siren', $siren);

        // Exactly one candidate, or we give up: see isAmbiguousSiren().
        return $candidats->count() === 1 ? $candidats->first() : null;
    }

    private function isAmbiguousSiren(RoutingKeys $keys): bool
    {
        $siren = $keys->normalizedSiren() ?? $keys->sirenFromSiret();

        return $siren !== null && $this->activeTenants()->where('siren', $siren)->count() > 1;
    }

    /**
     * Last resort, for single-tenant estates only. A safety net rather than a
     * strategy, hence the warning that must stay visible in operation.
     */
    private function defaultTenant(RoutingKeys $keys): ?Tenant
    {
        $actifs = $this->activeTenants();

        if ($actifs->count() !== 1) {
            return null;
        }

        $this->logger->warning(
            'einvoicing: routed to the single default tenant, no key matched',
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
     * A company identifier must not appear in clear in the logs; the last four
     * digits are enough to diagnose.
     */
    private function mask(?string $value): ?string
    {
        return $value === null ? null : str_repeat('*', max(0, strlen($value) - 4)).substr($value, -4);
    }
}
