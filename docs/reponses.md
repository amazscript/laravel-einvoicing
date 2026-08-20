# Répondre à une facture reçue

Sous la réforme française, recevoir une facture n'est pas la fin de l'histoire : l'acheteur doit
dire ce qu'il en fait. Le silence n'est pas une réponse, et certains statuts sont obligatoires.

## Les réponses courantes

```php
use AmazScript\Einvoicing\Enums\RejectionReason;
use AmazScript\Einvoicing\Facades\Einvoicing;

$facture = Einvoicing::for($tenant)->invoice($id);

$facture->acknowledge();                    // IN_HAND — prise en charge
$facture->approve('Bon pour paiement');     // APPROVED
$facture->dispute('Écart de quantité');     // DISPUTED
$facture->reportPayment(1234.56);           // PAYMENT_SENT
```

`acknowledge()` est la première attendue : elle arrête le fournisseur de se demander si sa facture
est arrivée.

## Refuser

Un refus exige un motif. « Refusée » tout court laisse le fournisseur deviner, et la plateforme
rejette l'appel sans raison :

```php
$facture->refuse(RejectionReason::TotalAmountIncorrect, 'Le total ne correspond pas au bon de commande');
```

Les motifs sont normatifs — fixés par la réforme, pas par la plateforme — pour qu'un refus puisse
être **traité** et pas seulement lu. Les plus fréquents :

| Motif | Quand |
|---|---|
| `TotalAmountIncorrect` | le total ne correspond pas |
| `PoReferenceIncorrectOrMissing` | bon de commande absent ou faux |
| `DuplicateInvoice` | déjà reçue |
| `UnknownTransaction` | aucune prestation correspondante |
| `SiretIncorrectOrMissing` | identification erronée |
| `LegalInformationMissing` | mentions légales manquantes |

L'énumération `RejectionReason` en compte 28. `Other` existe, à garder en dernier recours : il
n'apprend rien à personne.

Une chaîne reste acceptée, si la liste évolue avant le package :

```php
$facture->refuse('MOTIF_FUTUR', 'Explication');
```

## Les autres statuts

```php
use AmazScript\Einvoicing\Enums\BuyerStatus;

$facture->answer(BuyerStatus::PartiallyApproved, 'Deux lignes sur cinq contestées');
$facture->answer(BuyerStatus::Suspended);
$facture->answer(BuyerStatus::Completed);
```

`answer()` couvre les neuf codes acheteur. Deux garde-fous, avant l'appel réseau :

- un `Refused` sans motif lève une exception ;
- un statut de paiement sans montant aussi.

Contrairement aux codes que la plateforme **rapporte** — une liste ouverte que le package se garde
de modéliser — ceux-ci sont **envoyés** et validés contre un ensemble fermé. Une énumération protège
donc l'appelant d'un 400, au lieu de masquer un code authentique.

## Ce qui revient

Une réponse envoyée repart dans le réseau et vous revient par webhook, rangée sur la facture :

```php
$facture->model()->statuses;   // dont vos propres réponses
```

Vérifié en réel : un `IN_HAND` puis un `REFUSED` envoyés sont revenus quelques secondes plus tard et
se sont rattachés seuls.
