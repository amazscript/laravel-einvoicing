# Écrire un driver pour une autre plateforme

> Le package ne parle aujourd'hui qu'à **Iopole**. Cette page décrit ce qu'il faut fournir pour en
> brancher une autre.

## Pourquoi il n'y en a qu'un

Ce n'est pas un oubli d'architecture : les contrats sont neutres depuis le premier jour, et rien de
propre à un fournisseur ne remonte dans le webhook, le routage, les modèles ou les events.

C'est un refus d'écrire ce qui n'est pas vérifiable. Sur la seule journée du 20 août 2026, une
douzaine d'écarts entre la documentation d'Iopole et son API réelle ont été relevés : horodatage en
millisecondes là où la doc dit des secondes, endpoints unitaires répondant par une liste, champ
`platformDetail` annoncé mais réservé au White Label, `DELETE` documenté `204` répondant `501`.

Un driver écrit d'après une documentation, sans compte pour l'éprouver, ressemblerait à du code
juste et serait faux. **Si vous avez un compte chez une autre Plateforme Agréée, vous êtes mieux
placé que quiconque pour écrire son driver.**

## Ce qu'il faut implémenter

Huit contrats, dans `AmazScript\Einvoicing\Contracts`. Tous ne sont pas nécessaires selon ce que
vous visez.

| Contrat | Méthodes | Nécessaire pour |
|---|---|---|
| `SignatureVerifier` | 1 | **recevoir** — vérifier l'authenticité d'une livraison |
| `PayloadInterpreter` | 3 | **recevoir** — en extraire les clés de routage |
| `StatusMapper` | 1 | **recevoir** — lire un statut de cycle de vie |
| `InvoiceGateway` | 12 | lire les factures, télécharger, acquitter, répondre |
| `OutboundInvoiceGateway` | 1 | **émettre** |
| `BusinessEntityGateway` | 7 | annuaire, joignabilité, rattachement |
| `ReportingGateway` | 5 | e-reporting B2C |
| `TenantResolver` | 1 | déjà fourni, neutre — à ne remplacer que pour un routage sur mesure |

**Le minimum pour recevoir** tient dans les trois premiers, plus `InvoiceGateway`. Le reste peut
venir ensuite.

## Comment le brancher

Le package lie chaque contrat à son implémentation Iopole dans son service provider. Redéclarez
ceux que vous remplacez dans le vôtre, après l'enregistrement du package :

```php
public function register(): void
{
    $this->app->bind(SignatureVerifier::class, MaPlateformeSignatureVerifier::class);
    $this->app->bind(PayloadInterpreter::class, MaPlateformePayloadInterpreter::class);
    $this->app->bind(StatusMapper::class, MaPlateformeStatusMapper::class);
    $this->app->bind(InvoiceGateway::class, MaPlateformeInvoiceGateway::class);
}
```

**Limite connue** : `Einvoicing`, la façade publique, instancie encore les gateways Iopole en dur
pour porter le `customer-id` de chaque dossier. Un second driver demandera d'y substituer une
résolution par configuration — une demi-journée, pas une réécriture. Ce chantier s'ouvrira avec le
premier driver réel, pas avant.

## Ce sur quoi porter l'attention

Ces points ont chacun coûté un incident. Ils ne sont pas propres à Iopole.

**La signature.** Ce qui est haché diffère souvent selon le type de contenu. En multipart,
`php://input` est **vide** — le SAPI l'a consommé — et le checksum doit porter sur le fichier
temporaire. Utilisez `hash_equals`, jamais `===`.

**Les horodatages.** Vérifiez l'unité contre une livraison réelle. Une fenêtre anti-rejeu calculée
en secondes sur un horodatage en millisecondes rejette 100 % du trafic.

**Les listes déguisées.** Plusieurs endpoints unitaires répondent par une liste d'un élément.
Traitez-le comme la règle, pas comme l'exception.

**Les codes de statut.** Ne les modélisez pas en énumération : la liste est ouverte, et un code
inconnu doit être enregistré, pas rejeté. À l'inverse, les codes que vous **envoyez** sont validés
contre un ensemble fermé — une énumération y protège l'appelant.

**Les corps vides.** Un tableau PHP vide s'encode `[]` là où une API attend `{}`, et elle répond
« Expected object, received array » sans rien faire.

## Éprouvez-le contre le réel

La règle du projet, apprise à ses dépens : **une fixture se copie d'une réponse réelle capturée,
elle ne se rédige pas.** Une fixture écrite de mémoire décrit la supposition de son auteur, et les
tests passent au vert sur une API qui n'existe pas.

Les tests du package montrent le procédé : `tests/Fixtures/` contient des livraisons réelles,
anonymisées, dont les signatures ont été recalculées sous un secret public.

## Proposer votre driver

Ouvrez une issue avant d'écrire. La question la plus utile est : votre plateforme signe-t-elle ses
livraisons comme Iopole, ou autrement ? La réponse dit d'emblée si le contrat `SignatureVerifier`
suffit.
