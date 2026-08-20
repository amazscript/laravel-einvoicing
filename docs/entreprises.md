# Entreprises et joignabilité

Une facture n'arrive pas parce que la plateforme fonctionne, mais parce que le **destinataire est
joignable**. Ce sont deux choses différentes, et la seconde est invisible tant qu'une facture n'a
pas rebondi.

## Déclarée n'est pas joignable

Une entreprise peut être :

| État | Ce que la plateforme en sait | Peut-elle recevoir ? |
|---|---|---|
| déclarée | elle existe dans votre parc | non |
| inscrite à l'annuaire, date d'effet à venir | l'inscription est déposée | pas encore |
| inscrite à l'annuaire, en vigueur | son adresse électronique route | **oui** |

Seul le troisième état permet la réception. Les deux premiers produisent un rejet
`No route found for given key (electronicAddress : …)` chez l'émetteur — jamais chez vous, puisque
rien ne vous parvient. D'où l'intérêt de le contrôler avant, pas après.

## Deux adresses à ne pas confondre

Une entreprise porte un **identifiant légal** (`0002:449290493`, son SIREN) et, si elle est
inscrite, une **adresse d'annuaire** (`0225:449290493`). Seule la seconde route une facture, et
c'est elle que cite un rejet. Elles se ressemblent, elles ne servent pas à la même chose.

```php
$entreprise->identifiers[0]->legalAddress();  // "0002:449290493" — qui elle est
$entreprise->electronicAddress();             // "0225:449290493" — où elle reçoit
```

## Le contrôle

```bash
php artisan einvoicing:doctor
```

```
Entreprises
 ✓ entreprises déclarées        8
 ✗ joignables                     7/8
   · UNIBAT34                      aucun identifiant inscrit à l'annuaire
```

Chaque ligne nomme la cause :

| Message | Cause | Qui règle |
|---|---|---|
| `aucun identifiant déclaré` | ni SIREN ni SIRET rattaché | vous, côté plateforme |
| `aucun identifiant inscrit à l'annuaire` | l'entreprise n'est pas publiée | votre plateforme |
| `inscrite à l'annuaire, mais pas avant sa date d'effet` | inscription déposée, `validFrom` à venir | patienter jusqu'à cette date |

Les deux premières relèvent de l'onboarding auprès de votre Plateforme Agréée. Le package les
constate, il ne les corrige pas — il n'écrit rien dans l'annuaire.

## En code

```php
use AmazScript\Einvoicing\Facades\Einvoicing;

// Les entreprises qui peuvent effectivement recevoir
Einvoicing::entities()->reachable();

// Celles qui ne le peuvent pas, avec la raison
foreach (Einvoicing::entities()->unreachable() as $entreprise) {
    logger()->warning('Entreprise non joignable', [
        'nom' => $entreprise->name,
        'raison' => $entreprise->unreachableReason(),
    ]);
}
```

`unreachableReason()` rend un code stable — `no-identifier`, `no-registration`,
`registration-not-yet-active` — et non une phrase : à votre application de choisir sa langue. Il
vaut `null` quand l'entreprise est joignable.

Les deux méthodes acceptent une date, ce qui permet de répondre à « sera-t-elle joignable le
1er septembre ? » :

```php
$entreprise->isReachable(new DateTimeImmutable('2026-09-01'));
```

`all()` parcourt l'annuaire page par page. Rien n'est chargé tant que rien n'est lu :

```php
Einvoicing::entities()->all()->take(10);   // une seule page appelée
Einvoicing::entities()->find($id);         // null si inconnue
```

## Ce que ce chapitre ne couvre pas

L'**enregistrement** d'une entreprise — déclarer une unité légale, publier une adresse, demander
son rattachement — passe par votre plateforme. Le package est en lecture seule sur ce sujet.
