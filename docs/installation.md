# Installation

## Prérequis

- PHP 8.3 ou 8.4
- Laravel 12 ou 13
- Un compte chez une Plateforme Agréée (Iopole pour cette version)
- Une file d'attente fonctionnelle : le traitement des factures ne se fait pas dans la requête

## Mise en place

```bash
composer require amazscript/laravel-einvoicing
php artisan einvoicing:install
php artisan migrate
```

`einvoicing:install` publie deux choses : le fichier de configuration et les migrations.

> **Après une mise à jour du package**, republiez les migrations avec
> `php artisan einvoicing:install --force`. Les fichiers publiés sont des copies : une correction
> apportée au package ne les met pas à jour toute seule.

## Secret du webhook

```bash
php artisan einvoicing:secret
```

Le secret n'est pas enregistré : conservez-le à l'affichage. Il va dans votre `.env`, et **le même**
doit être déclaré à la plateforme lors de la création du webhook. C'est vous qui le fournissez, la
plateforme ne le génère pas.

## Identifiants de la plateforme

L'authentification se fait en OAuth2 `client_credentials` : aucun jeton permanent n'est délivré.

```dotenv
IOPOLE_BASE_URL=https://api.ppd.iopole.fr
IOPOLE_TOKEN_URL=https://auth.preprod.iopole.fr/realms/iopole/protocol/openid-connect/token
IOPOLE_CLIENT_ID=
IOPOLE_CLIENT_SECRET=
IOPOLE_CUSTOMER_ID=
EINVOICING_WEBHOOK_SECRET=
```

Le `customer-id` se récupère aussi par l'API : `GET /v1/config/customer/id`.

## Déclarer un dossier

Une facture est adressée à une entreprise. Le package doit savoir à quel modèle de votre application
rattacher chaque SIREN ou SIRET.

```php
use AmazScript\Einvoicing\Models\Tenant;

Tenant::create([
    'tenantable_type' => Societe::class,
    'tenantable_id'   => $societe->id,
    'customer_id'     => config('einvoicing.drivers.iopole.customer_id'),
    'siren'           => '123456789',
    'siret'           => '12345678900011',
    'active'          => true,
]);
```

Sans dossier actif, toute livraison sera conservée en `UNROUTED` : rien n'est perdu, mais rien n'est
exploité non plus.

## Déclarer le webhook

Côté plateforme, créez un webhook de direction `INBOUND` pointant sur :

```
https://votre-domaine.test/einvoicing/webhook
```

et fournissez-y le secret HMAC de votre `.env`.

```bash
php artisan einvoicing:webhooks:sync
```

La commande compare ce qui est déclaré à ce qui est attendu. Elle n'écrit rien : déclarer un webhook
redirige un flux de factures, cette décision vous revient.

## Lancer le worker

Le webhook n'exécute rien : il vérifie, encaisse et dispatche. Le traitement se fait dans une file
dédiée, `einvoicing`.

```bash
php artisan queue:work --queue=einvoicing
```

**C'est l'étape la plus facile à oublier**, parce que son absence ne ressemble pas à une panne : la
route répond `202`, les livraisons s'enregistrent, `doctor` est vert partout ailleurs — et pas une
facture n'est traitée. `--queue=einvoicing` est obligatoire : un worker lancé sans lui écoute
`default` et ne verra jamais ces jobs.

En production, confiez-le à un superviseur (`supervisord`, `systemd`, Horizon) plutôt qu'à un
terminal ouvert.

## Vérifier

```bash
php artisan einvoicing:doctor
```

Vérifie la configuration, les tables, la route, la file, puis interroge la plateforme. Aucun
identifiant n'est affiché : la sortie peut être transmise telle quelle à un support.

Le contrôle « jobs en souffrance » compte ceux qui attendent depuis plus d'une minute : c'est la
signature d'un worker absent ou lancé sur la mauvaise file.
