# Émettre une facture

> Palier v0.2. La réception (v0.1) fonctionne sans rien de ce qui suit.

## Ce que le package fait, et ne fait pas

Il **transporte** un document que votre application a déjà produit, et il en suit le sort. Il ne
**fabrique** aucun format.

| | |
|---|---|
| Produire le Factur-X / UBL / CII | votre logiciel de facturation, ou une bibliothèque dédiée |
| Envoyer, dédupliquer, suivre | ce package |
| Valider, router, livrer | votre Plateforme Agréée |

Ce partage n'est pas une commodité : un format fiscal invalide est un problème fiscal, et seule la
plateforme est agréée pour en répondre. La sienne est la signature qui compte.

## Envoyer

```php
use AmazScript\Einvoicing\Facades\Einvoicing;

$envoi = Einvoicing::for($tenant)->send('/chemin/vers/facture.xml');

$envoi->provider_invoice_id;  // l'identifiant donné par la plateforme
$envoi->status;               // OutboundStatus::Sent
```

Le fichier doit être un **PDF ou un XML** : la plateforme n'accepte rien d'autre.

Le destinataire n'est pas un paramètre. Il est **dans le document**, et c'est la plateforme qui l'y
lit. Le package ne l'analyse pas : lire un CII pour en extraire une adresse reviendrait à
comprendre un format qu'il a fait le choix de ne pas connaître.

Vérifiez donc la joignabilité **avant** d'émettre — voir [Entreprises](entreprises.md) :

```php
Einvoicing::entities()->unreachable();  // celles vers qui une facture rebondira
```

## Envoyer deux fois n'émet qu'une facture

L'endpoint d'émission n'accepte **aucune clé d'idempotence**. Un renvoi après un timeout réseau
facturerait donc le client deux fois — le genre d'erreur qu'une comptabilité découvre des mois plus
tard.

Le package s'en protège par une contrainte d'unicité sur `(dossier, empreinte SHA-256 du fichier)` :

```php
$a = Einvoicing::for($tenant)->send($fichier);
$b = Einvoicing::for($tenant)->send($fichier);   // même fichier

$a->id === $b->id;  // true, et un seul appel a été fait
```

Deux fichiers différents restent deux factures, même à une virgule près : c'est l'empreinte qui
tranche, pas une heuristique.

**Conséquence à connaître** : corriger une facture refusée suppose de modifier le document. S'il
repart identique à l'octet, le package rend le refus initial sans rappeler la plateforme.

## Quand la plateforme refuse

L'envoi lève une exception, et la ligne est **conservée** avec la raison :

```php
try {
    Einvoicing::for($tenant)->send($fichier);
} catch (EinvoicingException $e) {
    $refus = OutboundInvoice::where('status', 'FAILED')->latest()->first();
    $refus->failure_reason;   // ce que la plateforme a répondu
}
```

Rien n'est effacé : ce qui a été refusé, et pourquoi, est exactement ce qu'on viendra vous demander.

## Events

| Event | Quand |
|---|---|
| `OutboundInvoiceSent` | la plateforme a pris le document et l'a nommé |
| `OutboundInvoiceFailed` | elle l'a refusé d'emblée — rien n'est parti |

Pris n'est pas livré. La suite arrive sous forme de statuts de cycle de vie.
