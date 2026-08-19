# Multi-tenant

La plateforme n'accepte **qu'une seule URL de rappel** pour tout le parc. Le routage vers le bon
dossier est donc entièrement à la charge du package.

## Stratégie de résolution

Dans l'ordre, du plus précis au plus large :

1. **identifiant externe** renvoyé par la plateforme (`idPath`)
2. **SIRET** du destinataire — désigne un établissement
3. **SIREN** du destinataire — désigne l'entreprise
4. **dossier par défaut**, si le parc n'en compte qu'un seul actif

Le destinataire arrive dans l'en-tête `X-Target-Electronic-Address`, sous la forme `scheme:valeur`.
Le schéma `0225` désigne les adresses électroniques françaises et porte un SIREN ou un SIRET, selon
la longueur. `0002` désigne le répertoire SIRENE, `0009` le SIRET. À défaut d'en-tête, le destinataire
est cherché dans le payload.

## Le principe qui gouverne tout

**Mal router est pire que ne pas router.** Une facture qui entre dans les livres d'une autre société
est un incident comptable ; un événement non routé reste stocké et rejouable.

Dès qu'une étape laisse plus d'un candidat, la résolution s'arrête. Deux dossiers partageant un même
SIREN produisent un `TenantResolutionFailed`, pas un choix au hasard.

Un SIRET dont l'établissement est inconnu route quand même via ses neuf premiers chiffres, qui
identifient l'entreprise.

Le repli sur le dossier unique journalise un avertissement, avec les identifiants masqués. C'est un
filet, pas une stratégie.

## Ce qui se passe en cas d'échec

L'événement est conservé avec `tenant_id = null` et le statut `UNROUTED`, payload complet inclus. Il
n'est **pas** traité : produire une facture rattachée à personne serait pire qu'attendre.

```bash
php artisan einvoicing:events:retry
```

Une fois le dossier créé, cette commande repasse les événements par le routage.

## Remplacer le résolveur

```php
namespace App\Einvoicing;

use AmazScript\Einvoicing\Contracts\TenantResolver;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Tenancy\RoutingKeys;

final class ResolveurMaison implements TenantResolver
{
    public function resolve(RoutingKeys $keys): ?Tenant
    {
        return Tenant::query()
            ->where('siren', $keys->normalizedSiren())
            ->where('active', true)
            ->first();
    }
}
```

```php
'tenant_resolver' => \App\Einvoicing\ResolveurMaison::class,
```

Retourner `null` n'est pas une erreur : c'est un échec de routage, et l'événement est conservé.
