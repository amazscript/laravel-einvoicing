# Commandes

## `einvoicing:doctor`

Diagnostique la configuration, la base, la route, puis interroge la plateforme.

```bash
php artisan einvoicing:doctor
php artisan einvoicing:doctor --no-network
```

Chaque contrôle correspond à une panne réelle. **Aucun identifiant n'est affiché** : la sortie peut
être transmise telle quelle à un support. Code de retour non nul si un point est à corriger, ce qui
permet de l'utiliser en supervision.

## `einvoicing:install`

Publie la configuration et les migrations. `--force` écrase les fichiers déjà publiés — nécessaire
après une mise à jour du package, les copies ne se mettant pas à jour toutes seules.

## `einvoicing:secret`

Génère un secret HMAC de 32 octets. Il n'est pas enregistré : conservez-le à l'affichage, placez-le
dans le `.env`, et déclarez le **même** côté plateforme.

## `einvoicing:poll`

Filet de sécurité quand un webhook s'est perdu.

```bash
php artisan einvoicing:poll
php artisan einvoicing:poll --tenant=123456789
php artisan einvoicing:poll --dry-run
```

Demande à la plateforme ce qu'elle n'a pas vu acquitté et réinjecte le manquant dans le même circuit.
Ce qui est déjà arrivé par webhook n'est pas repris.

À planifier une fois par heure :

```php
// routes/console.php
Schedule::command('einvoicing:poll')->hourly();
```

## `einvoicing:webhooks:sync`

Compare la déclaration côté plateforme à la configuration locale. **N'écrit rien** : déclarer un
webhook redirige un flux de factures, cette décision vous revient.

## `einvoicing:retry:sync`

Affiche la stratégie de relance appliquée par la plateforme : c'est la marge dont vous disposez pour
redémarrer avant qu'une livraison ne soit abandonnée.

## `einvoicing:events:prune`

```bash
php artisan einvoicing:events:prune
php artisan einvoicing:events:prune --days=30 --dry-run
```

Ne supprime que les événements **déjà traités**. Les non routés et les échecs sont conservés : ils
portent une facture que personne n'a vue.

## `einvoicing:events:retry`

```bash
php artisan einvoicing:events:retry
php artisan einvoicing:events:retry --status=UNROUTED --limit=500
```

Repasse les événements par le routage, désormais que le dossier manquant existe peut-être. Sans
cette commande, un événement resté de côté le serait pour toujours.
