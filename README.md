# laravel-einvoicing

Recevoir les factures électroniques françaises dans une application Laravel, via une Plateforme Agréée.

> **État : en développement.** La v0.1 n'est pas publiée. Le squelette est en place, la réception
> ne l'est pas encore. Voir `SPRINT.md` pour l'avancement.

<!-- badges : version, tests, licence, PHP — à ajouter à la publication (D16) -->

## Le problème

Depuis le 1er septembre 2026, toute entreprise assujettie à la TVA doit être capable de **recevoir**
des factures électroniques. Le PPF n'étant plus une plateforme d'échange, tout flux passe par une
Plateforme Agréée. Se raccorder suppose d'écrire soi-même la plomberie : webhook signé en HMAC,
routage vers le bon dossier client, déduplication des livraisons répétées, stockage des fichiers.

Ce package écrit cette plomberie une fois pour toutes.

## Installation

```bash
composer require amazscript/laravel-einvoicing
php artisan einvoicing:install
php artisan einvoicing:secret
php artisan migrate
```

## Recevoir une facture

```php
// app/Listeners/StoreSupplierInvoice.php

use AmazScript\Einvoicing\Events\InboundInvoiceReceived;

final class StoreSupplierInvoice
{
    public function handle(InboundInvoiceReceived $event): void
    {
        $invoice = $event->invoice;

        // $invoice->invoice_number, $invoice->amount_total, $invoice->sender_siret...
    }
}
```

## Events

| Event | Déclencheur |
|---|---|
| `InboundInvoiceReceived` | facture entrante traitée et stockée |
| `InvoiceStatusUpdated` | statut de cycle de vie reçu |
| `InboundInvoiceInvalid` | facture entrante rejetée par la plateforme |
| `OutboundInvoiceNotDelivered` | échec de remise |
| `TenantResolutionFailed` | routage impossible, événement conservé |
| `WebhookSignatureRejected` | signature invalide — à surveiller |

## Ce que le package ne fait pas

- Il ne génère aucun format de facture : ni Factur-X, ni UBL, ni CII, ni PDF/A-3.
- Il n'exécute aucune validation Schematron.
- Il ne remplace pas un compte chez une Plateforme Agréée : il en consomme l'API.
- Il n'émet pas de factures en v0.1.

Le package est un Opérateur de Dématérialisation. Il ne certifie rien et n'apporte aucune garantie
de conformité : seule la Plateforme Agréée est agréée.

## Configuration

Documentation d'usage dans `docs/`.

## Licence

MIT. Voir `LICENSE`.
