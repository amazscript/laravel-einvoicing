# Configuration

Tout se trouve dans `config/einvoicing.php`.

## Driver

```php
'default' => env('EINVOICING_DRIVER', 'iopole'),
```

Une seule plateforme est prise en charge dans cette version. La configuration est structurée pour en
accueillir d'autres sans rupture.

## Webhook

| Clé | Défaut | Rôle |
|---|---|---|
| `path` | `einvoicing/webhook` | chemin de l'URL de rappel |
| `middleware` | `['api']` | groupe appliqué à la route |
| `secret` | — | secret HMAC partagé, **obligatoire** |
| `canonical_path` | `null` | chemin réellement signé, si un proxy réécrit l'URI |
| `tolerance` | `300` | écart maximal, en secondes, avec l'horodatage reçu |
| `direction` | `INBOUND` | direction attendue des livraisons |

**N'appliquez aucune limitation de débit à cette route.** Un `429` renvoyé à la plateforme lui ferait
relancer la livraison sans raison. `einvoicing:doctor` le vérifie.

**`canonical_path`** ne sert que derrière un proxy qui réécrit le chemin. La signature porte sur le
chemin public : si l'application voit autre chose, la vérification échoue alors que la requête est
authentique.

## Stockage

```php
'storage' => [
    'disk' => env('EINVOICING_DISK', 'local'),
    'path' => 'einvoicing',
],
```

Les fichiers sont rangés sous `{path}/{id de facture}/`. Le nom transmis par la plateforme n'entre
jamais dans le chemin, seule son extension est reprise après filtrage.

Un fichier est relu depuis le disque enregistré avec lui, pas depuis celui configuré aujourd'hui :
changer de disque ne rend pas les factures passées illisibles.

## File d'attente

```php
'queue' => [
    'connection' => env('EINVOICING_QUEUE_CONNECTION'),
    'name'       => 'einvoicing',
],
```

Le contrôleur encaisse et répond ; tout le traitement passe par la file. **Un worker doit tourner**,
sans quoi les factures sont reçues mais jamais exploitées.

```bash
php artisan queue:work --queue=einvoicing
```

## Rétention

```php
'events' => ['retention_days' => 90],
```

Ancienneté au-delà de laquelle un événement **déjà traité** peut être purgé. Les événements non
routés ou en échec ne sont jamais purgés : ils portent une donnée que personne n'a encore exploitée.

## Résolveur de tenant

```php
'tenant_resolver' => \AmazScript\Einvoicing\Tenancy\SiretResolver::class,
```

Remplaçable par toute classe implémentant `AmazScript\Einvoicing\Contracts\TenantResolver`.
Voir [multi-tenant.md](multi-tenant.md).
