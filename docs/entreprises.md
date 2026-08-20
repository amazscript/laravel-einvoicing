# Entreprises et joignabilité

Une facture n'arrive pas parce que la plateforme fonctionne, mais parce que le **destinataire est
joignable**. Ce sont deux choses différentes, et la seconde est invisible tant qu'une facture n'a
pas rebondi.

## Déclarée n'est pas joignable

Une entreprise peut être :

| État | Ce que la plateforme en sait | Peut-elle recevoir ? |
|---|---|---|
| déclarée | elle existe dans votre parc | non |
| inscrite sur un réseau | son adresse électronique est publiée | non |
| desservie par une plateforme | quelqu'un relève cette adresse | **oui** |

Seul le troisième état permet la réception. Les deux premiers produisent un rejet
`No route found for given key (electronicAddress : …)` chez l'émetteur — jamais chez vous, puisque
rien ne vous parvient. D'où l'intérêt de le contrôler avant, pas après.

## Le contrôle

```bash
php artisan einvoicing:doctor
```

```
Entreprises
 ✓ entreprises déclarées        8
 ✗ joignables                     1/8
   · UNIBAT34                      inscrite, mais aucune plateforme ne dessert cette adresse
```

Chaque ligne nomme la cause :

| Message | Cause | Qui règle |
|---|---|---|
| `aucun identifiant déclaré` | ni SIREN ni SIRET rattaché | vous, côté plateforme |
| `identifiants déclarés, aucun inscrit sur un réseau` | l'entreprise n'est pas publiée dans l'annuaire | votre plateforme |
| `inscrite, mais aucune plateforme ne dessert cette adresse` | l'adresse existe, personne ne la relève | votre plateforme |

Les trois relèvent de l'onboarding auprès de votre Plateforme Agréée. Le package les constate, il
ne les corrige pas — il n'écrit rien dans l'annuaire.

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
`no-serving-platform` — et non une phrase : à votre application de choisir sa langue. Il vaut `null`
quand l'entreprise est joignable.

`all()` parcourt l'annuaire page par page. Rien n'est chargé tant que rien n'est lu :

```php
Einvoicing::entities()->all()->take(10);   // une seule page appelée
Einvoicing::entities()->find($id);         // null si inconnue
```

## Ce que ce chapitre ne couvre pas

L'**enregistrement** d'une entreprise — déclarer une unité légale, publier une adresse, demander
son rattachement — passe par votre plateforme. Le package est en lecture seule sur ce sujet.
