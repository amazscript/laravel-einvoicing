# E-reporting

> Palier v0.4.

L'e-invoicing transporte ce qui circule **entre entreprises**. L'e-reporting couvre le reste : les
ventes aux particuliers, et les encaissements sur prestations. Rien de tout cela ne passe par le
réseau, donc rien d'autre ne le déclare.

Contrairement à l'émission, tout est en JSON : le package porte l'échange entier, sans dépendre d'un
format fiscal produit ailleurs.

## Prérequis : le régime de TVA

**L'entreprise déclarante doit avoir un régime de TVA enregistré sur la plateforme.** Sans lui, la
déclaration est refusée :

```
The business entity does not have a VAT regime specified.
```

Il se déclare à la création de l'entité :

```php
use AmazScript\Einvoicing\Enums\{EntityScope, VatRegime};

Einvoicing::entities()->declareLegalUnit(
    'MA SOCIETE', '948779160', EntityScope::PrivateTaxPayer, VatRegime::RealMonthly,
);
```

Sur une entreprise déjà déclarée, cela passe par votre plateforme.

**Limite connue** : ce régime n'est **jamais renvoyé** en lecture — ni dans la liste des entités, ni
sur une entité seule. `doctor` ne peut donc pas prévenir, et le manque n'apparaît qu'au refus de la
première déclaration. Le message de la plateforme est explicite, au moins.

## Déclarer des ventes

```php
use AmazScript\Einvoicing\Reporting\Transaction;
use AmazScript\Einvoicing\Enums\VatPointDate;

Einvoicing::for($tenant)->reporting()->reportTransactions(
    new DateTimeImmutable('2026-08-20'),
    [
        Transaction::goods(taxBasis: 1000.00, tax: 200.00),
        Transaction::goods(taxBasis: 50.00, tax: 2.75, rate: 5.5),
        Transaction::services(taxBasis: 80.00, tax: 16.00, vatPointDate: VatPointDate::PaymentDate),
    ],
    registerId: 'CAISSE-3',
    closureId: 'Z-2026-08-20',
);
```

`taxBasis` est le montant **hors taxe**, `rate` un pourcentage (`20.0`, pas `0.20`).

### Les quatre catégories

| Fabrique | Code | Ce que ça couvre |
|---|---|---|
| `Transaction::goods()` | `TLB1` | vente de biens physiques livrés |
| `Transaction::services()` | `TPS1` | prestation de service |
| `Transaction::nonTaxable()` | `TNT1` | opérations hors champ de TVA |
| `Transaction::mixed()` | `TMA1` | biens et services dans une même déclaration |

**Une prestation de service exige une date d'exigibilité de la TVA**, et le paramètre est donc
obligatoire dans la signature : sur un service, la TVA est due à l'encaissement, pas à la
réalisation, et se tromper de date rattache le montant à la mauvaise période. La plateforme refuse
la déclaration sans elle — autant que le code le dise avant.

### Aucun montant n'est recalculé

Ce qui est déclaré est ce que votre application dit avoir encaissé. Le package ne recalcule pas un
total, ne déduit pas un taux, ne corrige pas un écart : une incohérence est une question comptable,
et la masquer serait pire que de la transmettre.

## Déclarer un encaissement

```php
Einvoicing::for($tenant)->reporting()->reportPayment(
    new DateTimeImmutable('2026-08-25'), 600.00, reference: 'VIR-2026-08-25-01',
);
```

## Doublons

La plateforme attend **un appel par entreprise et par lot**. Envoyer deux fois le même jour le
déclare deux fois : contrairement à l'émission, aucune empreinte ne protège ici, parce que deux
journées de caisse identiques sont parfaitement possibles.

Le regroupement vous appartient. Conservez l'identifiant rendu par `reportTransactions()` — c'est
lui qui permettra de corriger la déclaration.

## Consulter les périodes

Les déclarations s'accumulent dans une période qui se clôt d'elle-même. Passé la clôture, plus rien
n'y entre.

```php
$periodes = Einvoicing::for($tenant)->reporting()->reports(new DateTimeImmutable('2026-01-01'));

foreach ($periodes as $p) {
    $p->isOpen();        // on peut encore y déclarer
    $p->wasRejected();   // le fisc l'a refusée
    $p->autoCloseDate;   // au-delà, c'est clos
    $p->vatRegime;
}
```

Le mois de départ est **obligatoire**, et les bornes sont des **mois** : la plateforme refuse une
date complète (`from must match YYYY-MM`). Une période de déclaration n'est jamais plus fine.

C'est aussi le seul endroit où le régime de TVA est lisible — il ne figure pas sur l'entité.

## Corriger : impossible pour l'instant

**Une déclaration envoyée ne peut être ni modifiée ni retirée.** Les endpoints existent et sont
documentés, mais la plateforme répond `501 Not Implemented` sur les deux — vérifié le 20 août 2026.

```php
$reporting->deleteTransaction($id);   // → 501 aujourd'hui
```

Les méthodes sont exposées parce que l'appel est correct et fonctionnera le jour où la plateforme
suivra. En attendant, **considérez chaque déclaration comme définitive** : vérifiez les montants
avant l'envoi, pas après.

Conservez tout de même l'identifiant rendu par `reportTransactions()` : c'est lui qui permettra la
correction quand elle existera.
