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

## Fabriquer le document

Le package n'en produit aucun. La bibliothèque de référence en PHP est
[`horstoeko/zugferd`](https://packagist.org/packages/horstoeko/zugferd).

L'exemple ci-dessous a été **émis et accepté** par une plateforme réelle le 20 août 2026. Chaque
ligne commentée correspond à une règle française qui a fait rejeter la facture avant d'être ajoutée.

```php
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\codelists\ZugferdInvoiceType;

$doc = ZugferdDocumentBuilder::createNew(ZugferdProfiles::PROFILE_EN16931)
    ->setDocumentInformation('FA-2026-001', ZugferdInvoiceType::INVOICE, new DateTime, 'EUR')

    // BR-FR-08 : mode de facturation. B1 = dépôt d'une facture simple.
    ->setDocumentBusinessProcess('B1')

    // BR-FR-05 : trois mentions légales, obligatoires même quand elles sont vides de sens.
    ->addDocumentNote('Indemnité forfaitaire pour frais de recouvrement : 40 euros.', '', 'PMT')
    ->addDocumentNote('Pénalités de retard : trois fois le taux d\'intérêt légal.', '', 'PMD')
    ->addDocumentNote('Escompte pour paiement anticipé : néant.', '', 'AAB')

    ->setDocumentSeller('MA SOCIETE', '102705746')
    // BR-FR-10 : le SIREN va ici, pas seulement dans l'identifiant de la partie.
    ->setDocumentSellerLegalOrganisation('102705746', '0002', 'MA SOCIETE')
    ->addDocumentSellerTaxRegistration('FC', '102705746')
    ->setDocumentSellerAddress('1 rue ...', '', '', '93800', 'Épinay-sur-Seine', 'FR')
    ->setDocumentSellerCommunication('0225', '102705746')

    ->setDocumentBuyer('MON CLIENT', '948779160')
    ->setDocumentBuyerLegalOrganisation('948779160', '0002', 'MON CLIENT')
    ->addDocumentBuyerTaxRegistration('FC', '948779160')
    ->setDocumentBuyerAddress('1 rue ...', '', '', '34000', 'Montpellier', 'FR')
    // L'adresse qui route. schemeID porte déjà 0225 : ne le repetez pas dans la valeur.
    ->setDocumentBuyerCommunication('0225', '948779160')
    ->setDocumentBuyerReference('948779160')

    ->addNewPosition('1')
    ->setDocumentPositionProductDetails('Prestation', 'Forfait')
    ->setDocumentPositionNetPrice(500.00)
    ->setDocumentPositionQuantity(1, 'C62')
    ->addDocumentPositionTax('E', 'VAT', 0.0, null, 'TVA non applicable, art. 293 B du CGI')
    ->setDocumentPositionLineSummation(500.00)

    ->addDocumentTax('E', 'VAT', 500.00, 0.00, 0.0, 'TVA non applicable, art. 293 B du CGI')
    ->setDocumentSummation(500.00, 500.00, 500.00, 0.00, 0.00, 500.00, 0.00);

file_put_contents($chemin, $doc->getContent());
```

### Les pièges français

Une facture conforme à EN 16931 peut être rejetée par les règles françaises `BR-FR`. Les quatre
rencontrées, dans l'ordre où la plateforme les a signalées :

| Règle | Ce qui manquait |
|---|---|
| *(routage)* | `schemeID="0225"` porte déjà le scheme : la valeur ne doit pas le répéter |
| `BR-FR-05` | mentions `PMT` (frais de recouvrement), `PMD` (pénalités), `AAB` (escompte) |
| `BR-FR-08` | mode de facturation absent |
| `BR-FR-10` | SIREN du vendeur absent de l'organisation légale |

**Vous n'avez pas à les deviner** : la plateforme les nomme une à une dans le statut de rejet, avec
la règle, le message en français et l'emplacement fautif.

```php
$envoi->statuses->first()->payload['json']['responses'][0]['rejectionDetail']['errors'];
```

Trois envois ont suffi à passer de `Participant ID must exist` à une facture livrée.

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

## Suivre ce que devient une facture émise

Prise n'est pas livrée. La suite arrive par le webhook **OUTBOUND**, qui doit pointer sur la même
route que le reste — un seul `callbackUrl` pour tout le parc.

```php
$envois = Einvoicing::for($tenant)->sent();

$envois->get();                // toutes, avec leur cycle de vie
$envois->failed();             // refusées à l'envoi — rien n'est parti
$envois->awaitingDelivery();   // parties, aucune nouvelle depuis
$envois->rejected();           // la plateforme dit qu'elles n'arriveront pas
```

Sur une facture :

```php
$envoi->statuses;          // le cycle de vie rapporté
$envoi->lastStatus();      // le dernier mot de la plateforme
$envoi->deliveryFailed();  // elle n'arrivera pas
$envoi->failureCode();     // pourquoi, dans ses termes
```

**Sans nouvelle n'est pas livrée.** `awaitingDelivery()` isole les factures parties dont rien n'est
revenu : le silence n'est pas un verdict, et le package ne le traite pas comme tel.

### Les motifs de non-livraison

`deliveryFailed()` s'appuie sur les codes **observés**, pas sur une énumération figée — la liste de
la plateforme est ouverte, et un code inconnu ne doit pas être écarté :

| Code | Ce qu'il veut dire |
|---|---|
| `REJECTED` | le routage a échoué, aucun destinataire joignable |
| `UNACCEPTABLE` | le document lui-même est refusé — vu avec `UNKNOWN_INVOICE_FLAVOR` sur un fichier qui n'était pas un Factur-X valide |

C'est `OutboundInvoice::FAILURE_CODES`, destiné à s'allonger. Un code hors liste reste enregistré et
lisible par `lastStatus()`.

### Le routage des statuts sortants

Un statut de facture émise nomme **votre client** comme destinataire, pas vous. Le routage
multi-tenant habituel n'y trouve donc rien. Le package le rattache par l'identifiant de la facture :
elle est partie de chez vous, son dossier est connu de façon certaine.

Si un statut arrive avant que sa facture soit enregistrée, il est conservé en `UNROUTED` et
récupérable :

```bash
php artisan einvoicing:events:retry
```

La commande **refait** le routage, elle ne se contente pas de relire l'ancien résultat.
