# laravel-einvoicing

Recevoir les factures électroniques françaises dans une application Laravel, via une Plateforme Agréée.

[![Tests](https://github.com/amazscript/laravel-einvoicing/actions/workflows/tests.yml/badge.svg)](https://github.com/amazscript/laravel-einvoicing/actions/workflows/tests.yml)
[![Qualité](https://github.com/amazscript/laravel-einvoicing/actions/workflows/quality.yml/badge.svg)](https://github.com/amazscript/laravel-einvoicing/actions/workflows/quality.yml)
[![Packagist](https://img.shields.io/packagist/v/amazscript/laravel-einvoicing.svg)](https://packagist.org/packages/amazscript/laravel-einvoicing)
[![PHP](https://img.shields.io/packagist/php-v/amazscript/laravel-einvoicing.svg)](https://github.com/amazscript/laravel-einvoicing/blob/main/composer.json)
[![Licence](https://img.shields.io/packagist/l/amazscript/laravel-einvoicing.svg)](https://github.com/amazscript/laravel-einvoicing/blob/main/LICENSE)

> **Maturité.** Version `0.1.0` : le package est fonctionnel et couvert par 234 tests, mais il
> **n'a pas encore d'usage en production connu**. Il a été vérifié contre une sandbox réelle, pas
> contre des flux de facturation d'entreprise. L'API publique peut évoluer d'ici la `1.0`.
>
> Il est publié avant l'échéance du 1er septembre 2026 parce qu'un package disponible et perfectible
> sert mieux qu'un package parfait et absent. Les remontées d'usage sont les bienvenues.

## Le problème

Depuis le 1er septembre 2026, toute entreprise assujettie à la TVA doit être capable de **recevoir**
des factures électroniques. Le PPF n'étant plus une plateforme d'échange, tout flux transite par une
Plateforme Agréée.

Se raccorder suppose d'écrire la plomberie : un webhook signé en HMAC dont le calcul diffère selon le
type de contenu, le routage vers le bon dossier client alors qu'une seule URL de rappel dessert tout
le parc, la déduplication de livraisons répétées par conception, le stockage des documents.

Ce package écrit cette plomberie une fois pour toutes.

## Installation

```bash
composer require amazscript/laravel-einvoicing
php artisan einvoicing:install
php artisan migrate
php artisan einvoicing:secret
```

Puis, dans `.env`, les identifiants fournis par votre Plateforme Agréée :

```dotenv
IOPOLE_BASE_URL=https://api.ppd.iopole.fr
IOPOLE_TOKEN_URL=https://auth.preprod.iopole.fr/realms/iopole/protocol/openid-connect/token
IOPOLE_CLIENT_ID=
IOPOLE_CLIENT_SECRET=
IOPOLE_CUSTOMER_ID=
EINVOICING_WEBHOOK_SECRET=
```

Enfin, vérifiez le raccordement :

```bash
php artisan einvoicing:doctor
```

## Recevoir une facture

```php
namespace App\Listeners;

use AmazScript\Einvoicing\Events\InboundInvoiceReceived;

final class EnregistrerFactureFournisseur
{
    public function handle(InboundInvoiceReceived $event): void
    {
        $facture = $event->invoice;

        Achat::create([
            'fournisseur' => $facture->sender_name,
            'siren'       => $facture->sender_siren,
            'numero'      => $facture->invoice_number,
            'date'        => $facture->invoice_date,
            'montant_ttc' => $facture->amount_total,
            'montant_tva' => $facture->amount_tax,
            'devise'      => $facture->currency,
        ]);
    }
}
```

C'est tout. Le webhook, la vérification de signature, le routage multi-tenant, la déduplication et le
téléchargement des documents ont déjà eu lieu.

Les montants sont des chaînes, pas des flottants : un centime perdu dans un arrondi binaire est une
écriture fausse. Pour les calculer, employez `bcsub()` ou une bibliothèque décimale.

## Consulter les factures

```php
use AmazScript\Einvoicing\Facades\Einvoicing;

// Ce que le package détient
Einvoicing::for($tenant)->invoices()->local()->get();

// Ce que la plateforme n'a pas vu acquitté
Einvoicing::for($tenant)->invoices()->remoteNotSeen();

// Recherche, parcourue paresseusement
Einvoicing::for($tenant)->invoices()
    ->search(['invoice.direction' => 'INBOUND', 'invoice.state' => 'NOT_DELIVERED'])
    ->take(20);

// Une facture précise
Einvoicing::for($tenant)->invoice($id)->markAsSeen();
Einvoicing::for($tenant)->invoice($id)->readablePdf();
Einvoicing::for($tenant)->invoice($id)->attachments();
```

## Events

| Event | Déclencheur |
|---|---|
| `InboundInvoiceReceived` | une facture fournisseur est arrivée et a été consignée |
| `InvoiceStatusUpdated` | un statut de cycle de vie a été reçu |
| `InboundInvoiceInvalid` | une facture entrante a été refusée par la plateforme |
| `OutboundInvoiceNotDelivered` | une facture émise n'a pas atteint son destinataire |
| `TenantResolutionFailed` | aucun dossier ne correspond au destinataire — à surveiller |
| `WebhookSignatureRejected` | signature invalide — à surveiller de près |

## Ce que le package ne fait pas

- Il ne génère aucun format de facture : ni Factur-X, ni UBL, ni CII, ni PDF/A-3.
- Il n'exécute aucune validation Schematron.
- Il ne remplace pas un compte chez une Plateforme Agréée : il en consomme l'API.
- Il n'émet pas de factures.

Le package est un **Opérateur de Dématérialisation**. Il ne certifie rien et n'apporte aucune garantie
de conformité : seule la Plateforme Agréée est agréée.

## Configuration

Le fichier `config/einvoicing.php` couvre le driver, le webhook, le stockage, la file d'attente, la
rétention des événements et le résolveur de tenant. Voir la
[documentation de configuration](https://github.com/amazscript/laravel-einvoicing/blob/main/docs/configuration.md).

## Commandes

| Commande | Rôle |
|---|---|
| `einvoicing:doctor` | diagnostique la configuration et le raccordement |
| `einvoicing:install` | publie la configuration et les migrations |
| `einvoicing:secret` | génère un secret HMAC |
| `einvoicing:poll` | récupère ce qu'un webhook aurait manqué |
| `einvoicing:webhooks:sync` | compare la déclaration du webhook à la configuration locale |
| `einvoicing:retry:sync` | affiche la stratégie de relance de la plateforme |
| `einvoicing:events:prune` | purge les événements déjà traités |
| `einvoicing:events:retry` | rejoue les événements non routés ou en échec |

## Sécurité

Le secret du webhook doit faire au moins 32 octets et n'apparaître que dans le `.env`. Une signature
invalide, un horodatage hors tolérance ou un secret absent font répondre `401` sans rien écrire.

Le `customer-id` est chiffré au repos. Ni les jetons, ni les secrets, ni les identifiants d'entreprise
n'apparaissent dans les messages d'erreur ou les journaux.

Pour signaler une vulnérabilité : [contact@amazscript.com](mailto:contact@amazscript.com).

## Documentation

[Installation](https://github.com/amazscript/laravel-einvoicing/blob/main/docs/installation.md) ·
[Configuration](https://github.com/amazscript/laravel-einvoicing/blob/main/docs/configuration.md) ·
[Webhooks](https://github.com/amazscript/laravel-einvoicing/blob/main/docs/webhooks.md) ·
[Multi-tenant](https://github.com/amazscript/laravel-einvoicing/blob/main/docs/multi-tenant.md) ·
[Events](https://github.com/amazscript/laravel-einvoicing/blob/main/docs/events.md) ·
[Commandes](https://github.com/amazscript/laravel-einvoicing/blob/main/docs/commandes.md) ·
[Entreprises](https://github.com/amazscript/laravel-einvoicing/blob/main/docs/entreprises.md) ·
[Dépannage](https://github.com/amazscript/laravel-einvoicing/blob/main/docs/depannage.md)

## Licence

MIT. Voir [LICENSE](https://github.com/amazscript/laravel-einvoicing/blob/main/LICENSE).

Support commercial et accompagnement à l'intégration : [contact@amazscript.com](mailto:contact@amazscript.com).
