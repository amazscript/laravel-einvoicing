# Webhooks

## Ce que fait la route

`POST /einvoicing/webhook` authentifie, encaisse, répond. Rien d'autre : le traitement part en file.

Les codes de retour suivent une règle stricte. Un payload incompréhensible reçoit tout de même une
réponse `2xx` — un `5xx` dirait à la plateforme que le service est en panne et déclencherait ses
relances pour rien. Seule une signature invalide vaut un `401`, et dans ce cas **rien n'est écrit** :
une requête non authentifiée n'est pas une donnée.

## Signature

Chaîne canonique :

```
{X-Timestamp}\n{MÉTHODE}\n{chemin_avec_query}\n{checksum}
```

Signature et checksum sont en **hexadécimal**. La comparaison se fait en temps constant.

### Le calcul du checksum, qui piège tout le monde

| Type de contenu | Source du SHA-256 |
|---|---|
| `application/json` | le corps brut intégral |
| `multipart/form-data` | **le contenu du champ fichier, et lui seul** |

En multipart, les champs annexes accompagnent le fichier mais **n'entrent pas** dans le calcul.

Et surtout : en multipart, `php://input` **est vide**. PHP a déjà consommé le corps pour peupler
`$_POST` et `$_FILES`. Une implémentation qui hacherait « le corps brut » hacherait la chaîne vide et
rejetterait toutes les factures. Le package lit le fichier temporaire uploadé.

*Vérifié sur des livraisons réelles : `php://input` mesuré à 0 octet, signature reproduite à l'octet
près à partir du seul contenu du fichier.*

### Horodatage

`X-Timestamp` est transmis en **millisecondes**, ce qu'aucune documentation ne mentionne. Comparé tel
quel à une horloge en secondes, l'écart se compte en milliers d'années et toute livraison authentique
est rejetée. Le package accepte les deux unités.

L'écart est pris en valeur absolue : une horloge en avance est aussi suspecte qu'un rejeu tardif.

## En-têtes reçus

| En-tête | Rôle |
|---|---|
| `X-Timestamp` | horodatage de la signature, en millisecondes |
| `X-Signature` | signature HMAC-SHA256, en hexadécimal |
| `X-Checksum` | empreinte du contenu — optionnel, vérifié s'il est présent |
| `X-Idempotency-Key` | clé de déduplication, stable d'une relance à l'autre |
| `X-Target-Electronic-Address` | destinataire, sous la forme `scheme:valeur` |

## Déduplication

La livraison est *at-least-once* : la même chose arrive plusieurs fois. L'unicité est portée par la
base, jamais par une lecture préalable — entre un `SELECT` et un `INSERT`, une seconde livraison a le
temps de passer.

Une violation de contrainte signifie « déjà reçu », ce qui est un **succès** : la plateforme reçoit
un `2xx` et cesse de relancer.

La clé vient de `X-Idempotency-Key`. À défaut, l'identifiant métier de l'objet livré ; à défaut
encore, une empreinte du contenu.

## Ce qui arrive vraiment

Une facture entrante arrive en `multipart/form-data` : un champ `file` contenant un **PDF**, plus
`invoiceId` et `senderAcceptStatus`. Elle ne porte ni numéro, ni date, ni montant : ces métadonnées
sont récupérées auprès de l'API dans la foulée.

Un statut arrive en `application/json`, avec `invoiceId`, `statusId`, `destType`, `status.code` et le
justificatif XML. Le cycle observé : `SUBMITTED → ISSUED → RECEIVED → MADE_AVAILABLE`.

## Si un webhook se perd

```bash
php artisan einvoicing:poll
```

Demande à la plateforme ce qu'elle n'a pas vu acquitté et réinjecte le manquant dans le même circuit,
déduplication comprise.
