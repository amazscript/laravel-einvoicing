<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Contracts\TenantResolver;
use AmazScript\Einvoicing\Events\TenantResolutionFailed;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Tenancy\RoutingKeys;
use AmazScript\Einvoicing\Tenancy\SiretResolver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

function makeTenant(array $attributes = []): Tenant
{
    static $n = 0;
    $n++;

    return Tenant::create(array_merge([
        'tenantable_type' => 'App\\Models\\Company',
        'tenantable_id' => (string) $n,
        'customer_id' => 'cust-'.$n,
        'siren' => str_pad((string) $n, 9, '0', STR_PAD_LEFT),
        'siret' => str_pad((string) $n, 14, '0', STR_PAD_LEFT),
        'active' => true,
    ], $attributes));
}

function resolver(): SiretResolver
{
    return app(SiretResolver::class);
}

// ---------------------------------------------------------------- stratégie 1

it('route sur l\'identifiant externe quand la plateforme le renvoie', function (): void {
    $cible = makeTenant(['siret' => '11111111111111', 'siren' => '111111111']);
    makeTenant(['siret' => '22222222222222', 'siren' => '222222222']);

    $resolu = resolver()->resolve(new RoutingKeys(externalId: $cible->id));

    expect($resolu?->id)->toBe($cible->id);
});

it('préfère l\'identifiant externe au siret quand les deux sont présents', function (): void {
    $parIdentifiant = makeTenant(['siret' => '11111111111111']);
    makeTenant(['siret' => '22222222222222']);

    $resolu = resolver()->resolve(new RoutingKeys(
        externalId: $parIdentifiant->id,
        siret: '22222222222222',
    ));

    expect($resolu?->id)->toBe($parIdentifiant->id);
});

it('ignore un identifiant externe inconnu et poursuit la résolution', function (): void {
    $cible = makeTenant(['siret' => '33333333333333']);

    $resolu = resolver()->resolve(new RoutingKeys(
        externalId: '00000000-0000-0000-0000-000000000000',
        siret: '33333333333333',
    ));

    expect($resolu?->id)->toBe($cible->id);
});

// ---------------------------------------------------------------- stratégie 2

it('route sur le siret du destinataire', function (): void {
    makeTenant(['siret' => '11111111111111', 'siren' => '111111111']);
    $cible = makeTenant(['siret' => '44444444444444', 'siren' => '444444444']);

    expect(resolver()->resolve(new RoutingKeys(siret: '44444444444444'))?->id)->toBe($cible->id);
});

it('tolère un siret ponctué ou espacé', function (string $siret): void {
    $cible = makeTenant(['siret' => '44444444444444', 'siren' => '444444444']);

    expect(resolver()->resolve(new RoutingKeys(siret: $siret))?->id)->toBe($cible->id);
})->with([
    'espaces' => ['444 444 444 44444'],
    'points' => ['444.444.444.44444'],
    'espaces en bordure' => ['  44444444444444  '],
]);

it('ne route jamais vers un tenant inactif', function (): void {
    makeTenant(['siret' => '55555555555555', 'siren' => '555555555', 'active' => false]);

    expect(resolver()->resolve(new RoutingKeys(siret: '55555555555555')))->toBeNull();
});

// ---------------------------------------------------------------- stratégie 3

it('retombe sur le siren quand aucun siret ne correspond', function (): void {
    $cible = makeTenant(['siret' => '66666666666666', 'siren' => '666666666']);

    $resolu = resolver()->resolve(new RoutingKeys(siren: '666666666'));

    expect($resolu?->id)->toBe($cible->id);
});

it('déduit le siren des neuf premiers chiffres d\'un siret inconnu', function (): void {
    // L'établissement exact n'est pas connu du package, mais l'entreprise l'est.
    $cible = makeTenant(['siret' => '77777777700011', 'siren' => '777777777']);

    $resolu = resolver()->resolve(new RoutingKeys(siret: '77777777700099'));

    expect($resolu?->id)->toBe($cible->id);
});

it('refuse de choisir quand plusieurs tenants partagent le même siren', function (): void {
    Event::fake();

    makeTenant(['siret' => '88888888800011', 'siren' => '888888888']);
    makeTenant(['siret' => '88888888800022', 'siren' => '888888888']);

    // Router au hasard entre deux établissements serait un incident comptable :
    // on préfère ne pas router et laisser l'événement en UNROUTED.
    expect(resolver()->resolve(new RoutingKeys(siren: '888888888')))->toBeNull();

    Event::assertDispatched(TenantResolutionFailed::class);
});

// ---------------------------------------------------------------- stratégie 4

it('retombe sur le tenant par défaut lorsqu\'il est le seul actif', function (): void {
    $seul = makeTenant(['siret' => '99999999999999', 'siren' => '999999999']);

    expect(resolver()->resolve(new RoutingKeys(siret: '12345678900011'))?->id)->toBe($seul->id);
});

it('journalise un avertissement quand il utilise le tenant par défaut', function (): void {
    Log::spy();
    makeTenant(['siret' => '99999999999999']);

    resolver()->resolve(new RoutingKeys(siret: '12345678900011'));

    Log::shouldHaveReceived('warning')->once();
});

it('ne divulgue pas le siret en clair dans les journaux', function (): void {
    Log::spy();
    makeTenant(['siret' => '99999999999999']);

    resolver()->resolve(new RoutingKeys(siret: '12345678900011'));

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []): bool {
        return ! str_contains($message.json_encode($context), '12345678900011');
    });
});

it('n\'utilise pas le tenant par défaut si plusieurs sont actifs', function (): void {
    makeTenant(['siret' => '11111111111111', 'siren' => '111111111']);
    makeTenant(['siret' => '22222222222222', 'siren' => '222222222']);

    expect(resolver()->resolve(new RoutingKeys(siret: '12345678900011')))->toBeNull();
});

it('ignore les tenants inactifs pour décider qu\'un seul reste', function (): void {
    $actif = makeTenant(['siret' => '11111111111111', 'siren' => '111111111']);
    makeTenant(['siret' => '22222222222222', 'siren' => '222222222', 'active' => false]);

    expect(resolver()->resolve(new RoutingKeys(siret: '12345678900011'))?->id)->toBe($actif->id);
});

// ---------------------------------------------------------------- échec

it('émet un événement d\'échec quand rien ne permet de router', function (): void {
    Event::fake();
    makeTenant(['siret' => '11111111111111', 'siren' => '111111111']);
    makeTenant(['siret' => '22222222222222', 'siren' => '222222222']);

    $keys = new RoutingKeys(siret: '12345678900011', siren: '123456789');

    expect(resolver()->resolve($keys))->toBeNull();

    Event::assertDispatched(TenantResolutionFailed::class, function (TenantResolutionFailed $e) use ($keys): bool {
        return $e->keys === $keys;
    });
});

it('échoue proprement sur des clés entièrement vides', function (): void {
    Event::fake();
    makeTenant();
    makeTenant();

    expect(resolver()->resolve(new RoutingKeys))->toBeNull();

    Event::assertDispatched(TenantResolutionFailed::class);
});

it('n\'émet aucun événement d\'échec quand la résolution aboutit', function (): void {
    Event::fake();
    $cible = makeTenant(['siret' => '44444444444444']);

    expect(resolver()->resolve(new RoutingKeys(siret: '44444444444444'))?->id)->toBe($cible->id);

    Event::assertNotDispatched(TenantResolutionFailed::class);
});

it('échoue quand le parc est vide', function (): void {
    Event::fake();

    expect(resolver()->resolve(new RoutingKeys(siret: '44444444444444')))->toBeNull();

    Event::assertDispatched(TenantResolutionFailed::class);
});

// ---------------------------------------------------------------- remplaçabilité

it('est remplaçable par le conteneur', function (): void {
    $faux = new class implements TenantResolver
    {
        public function resolve(RoutingKeys $keys): ?Tenant
        {
            return null;
        }
    };

    app()->instance(TenantResolver::class, $faux);

    expect(app(TenantResolver::class))->toBe($faux);
});
