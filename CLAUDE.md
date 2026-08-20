# CLAUDE.md — `amazscript/laravel-einvoicing`

Package Laravel de **réception** de factures électroniques françaises via une Plateforme Agréée (driver Iopole).
Référence fonctionnelle : `CDC-laravel-einvoicing.md` (version courante en tête du document).
Découpage et avancement : `SPRINT.md`. En cas de contradiction, le CDC fait foi.

---

## 1. Rôle attendu

Tu es développeur Laravel senior sur un package open-source destiné à un usage en production comptable.
Une facture perdue, dupliquée ou mal routée est un **incident comptable**, pas un bug d'affichage.

Priorités, dans cet ordre : **correction > sécurité > lisibilité > ergonomie > performance**.

## 2. Règles absolues

- **Ne jamais générer** de Factur-X, UBL, CII, PDF/A-3 ni de validation Schematron. La PA s'en charge. Si une tâche semble l'exiger, arrête-toi et signale-le.
- **Ne jamais logger** : token Bearer, `customer-id`, secret HMAC, contenu de facture, SIREN/SIRET en clair dans un contexte d'erreur public.
- **Ne jamais renvoyer un 5xx** à la PA sur une erreur métier — ça déclenche sa stratégie de retry pour rien. Encaisser, persister, dispatcher, répondre 2xx.
- **Ne jamais traiter en synchrone** dans le contrôleur webhook. Vérifier, dédupliquer, dispatcher, répondre.
- **Ne jamais inventer** un champ, un endpoint ou un comportement d'API. Si la doc Iopole ne le dit
  pas, demande. Une forme d'API supposée est le pire des bugs : les tests écrits dans la foulée
  décrivent la supposition, passent au vert, et la fonctionnalité est fausse de bout en bout.
  Le seul garde-fou est la **réponse réelle** — va la chercher avant d'écrire le mapping.
- **Aucune dépendance** hors `illuminate/*` et `guzzlehttp/guzzle` sans validation explicite.

## 3. Stack et conventions

| | |
|---|---|
| PHP | 8.3 minimum, `declare(strict_types=1)` partout |
| Laravel | 12 et 13 |
| Namespace | `AmazScript\Einvoicing\` |
| Tests | Pest |
| Style | Laravel Pint, preset `laravel` |
| Analyse statique | PHPStan niveau 8 |
| Versionnage | SemVer strict |

Typage explicite sur tout paramètre et tout retour. Pas de `mixed` sans commentaire justificatif.

**Langue.** Ce qui est publié dans l'application consommatrice — `src/`, `config/`,
`database/migrations/` — est commenté en **anglais** : les docblocks s'affichent au survol dans
l'IDE, et un package Packagist s'adresse à tout le monde. Les messages d'exception et les raisons
d'échec aussi, puisqu'ils finissent dans les journaux et la supervision.

Restent en **français** : le README, `docs/`, le CDC, le sprint, les noms de tests, et les sorties
des commandes Artisan — celles-ci s'adressent à l'exploitant, et le marché est français.

**La règle de partage : ce que Packagist affiche est en anglais, ce qui s'ouvre après le clic est en
français.** La `description` du `composer.json` et les métadonnées du dépôt sont lues par quelqu'un
qui parcourt une liste sans savoir si elle le concerne : l'anglais y est la convention, et le mot
« French » y fait le tri mieux qu'une phrase française. Le README, lui, s'adresse à quelqu'un qui a
déjà cliqué — donc à un développeur confronté à la réforme française.
Pas de facade statique dans le code interne du package — injection par constructeur. La facade `Einvoicing` est réservée à l'API publique consommateur.

## 4. Architecture

```
src/
├── EinvoicingServiceProvider.php
├── Facades/Einvoicing.php
├── Contracts/          Driver, TenantResolver, SignatureVerifier
├── Drivers/Iopole/     Client, Endpoints, ResponseMappers
├── Webhook/            Controller, Middleware, Payload parsers
├── Tenancy/            SiretResolver, TenantContext
├── Jobs/               ProcessInboundInvoice, ProcessStatusUpdate
├── Models/             Tenant, InboundInvoice, InvoiceFile, Status, WebhookEvent
├── Enums/              InvoiceFormat, InvoiceFileKind, WebhookEventStatus
├── Events/
├── Exceptions/
└── Console/            Commandes Artisan
```

Toute logique spécifique à Iopole reste sous `Drivers/Iopole`. Rien d'Iopole ne remonte dans `Webhook`, `Tenancy`, `Models` ou `Events` — le v0.4 ajoutera un second driver.

## 5. Les 5 points critiques

Ces cinq points sont la raison d'être du package. Ils exigent une attention supérieure.

### 5.1 Signature HMAC

Chaîne canonique : `{X-Timestamp}\n{METHOD}\n{path_with_query}\n{checksum}`

- Checksum en `application/json` → SHA-256 du **corps brut intégral**.
- Checksum en `multipart/form-data` → SHA-256 du **contenu du champ fichier uniquement**. Les autres champs sont exclus.
- Raw body capturé **avant** tout parsing Laravel.
- `hash_equals` obligatoire. Jamais `===`.
- Rejet si `X-Timestamp` dévie de plus de la tolérance configurée.

### 5.2 Routage multi-tenant

Un seul `callbackUrl` pour tout le parc. Résolution : `idPath` → SIRET → SIREN → tenant unique par défaut.
Échec → persistance en `UNROUTED` + event. Jamais de perte, jamais de 5xx.

### 5.3 Déduplication

Livraison at-least-once. Contrainte unique sur `event_id` en base — pas de vérification applicative seule.
Une violation de contrainte unique est un **succès** (déjà traité), pas une erreur.

### 5.4 Idempotence

Un retry ne doit jamais créer de doublon de facture. Toute écriture passe par `updateOrCreate` sur `(provider, provider_invoice_id)`.

### 5.5 Erreurs API

`400` au format Zod (`path`, `code`, `message`) → `EinvoicingValidationException` avec les erreurs mappées.
`429` → backoff exponentiel dans le job. `409 DUPLICATE_RESOURCE` → traité comme succès.

## 6. Cycle de travail

Pour chaque tâche, dans cet ordre :

1. **Confirmer** la story concernée dans `SPRINT.md` (D01 à D16). Si aucune ne correspond, demande avant de coder.
2. **Écrire le test d'abord** quand la tâche touche un des 5 points critiques. Ailleurs, test immédiatement après.
3. **Implémenter** le minimum qui fait passer le test.
4. **Vérifier** : `composer test && composer analyse && composer format`.
5. **Commit** atomique.
6. **Rapporter** en 3 lignes : ce qui est fait, ce qui est testé, ce qui reste.

Ne jamais enchaîner plusieurs stories dans un même commit.

## 7. Tests

- Pest, `tests/Unit` et `tests/Feature`, orchestration via `orchestra/testbench`.
- Jamais d'appel réseau réel dans les tests.
- **Une fixture se copie d'une réponse réelle capturée, elle ne se rédige pas.** La doc décrit ce
  que l'API devrait renvoyer ; la fixture doit dire ce qu'elle renvoie. Les écarts déjà constatés
  — horodatage en millisecondes, endpoint unitaire répondant par une liste, casing `FacturX`,
  champ `platformDetail` inexistant — étaient tous invisibles depuis la doc seule.
- Couverture minimale **85 %** sur `Webhook/` et `Tenancy/`. Le reste : 70 %.

Toute lecture d'une nouvelle ressource d'API se vérifie **contre la sandbox** avant d'être déclarée
faite, et son résultat se confronte aux faits déjà en base. Une conclusion qui contredit les données
observées — « cette entreprise ne peut rien recevoir » alors qu'elle vient de recevoir — est fausse
jusqu'à preuve du contraire, et c'est le code qu'il faut soupçonner, pas la réalité.

Cas obligatoires avant toute PR touchant le webhook :

- signature valide en JSON
- signature valide en multipart **avec champs annexes** (le piège du 5.1)
- signature invalide → 401, rien en base
- timestamp hors tolérance → rejet
- même `event_id` deux fois → un seul traitement
- tenant introuvable → `UNROUTED`, réponse 2xx
- payload malformé → 2xx, event d'erreur, pas d'exception remontée

## 8. Commits

Conventional Commits, en anglais, à l'impératif.

```
feat(webhook): verify HMAC signature on multipart payloads
fix(tenancy): fall back to SIREN when SIRET is absent
test(webhook): cover replayed event deduplication
docs(readme): add listener example
chore(ci): add PHP 8.4 to matrix
```

Règles :

- **Commiter à la fin de chaque story, sans demander.** L'étape 5 du cycle de travail prime ici
  sur la règle globale « ne commiter que sur demande explicite », qui ne vise que le **push**.
  Pousser reste soumis à une demande explicite.
- Un commit = une intention. Jamais de refactor mélangé à une feature.
- Le corps explique **pourquoi**, pas quoi.
- Ne jamais commit sans que `composer test` passe.
- Ne jamais commit de `.env`, de token, de secret, ni de fixture contenant des données d'entreprise réelles.
- Branche par story : `feat/d09-tenant-routing`.
- Pas de co-author ni de mention d'outil dans les messages de commit.

## 9. Documentation

Deux documents, deux publics distincts. Ne pas les mélanger.

### README.md — c'est la page de vente

L'ordre est imposé, il détermine le taux d'installation :

1. Une phrase : ce que fait le package, pour qui.
2. Badges (version, tests, licence, PHP).
3. **Le problème** en 3 lignes — la réforme, la réception obligatoire, la plomberie à écrire.
4. **Installation en 4 lignes**, visible sans scroller.
5. **Un exemple de listener** sur `InboundInvoiceReceived`. C'est le moment de conversion.
6. Tableau des events.
7. Ce que le package ne fait **pas** (pas de génération de format, pas d'agrément — il faut un compte PA).
8. Configuration, commandes, sécurité.
9. Licence MIT, lien de support commercial.

Contraintes de ton :

- Français. Le marché est français.
- Aucun superlatif, aucun emoji dans le corps.
- Chaque bloc de code doit être copiable et fonctionner tel quel.
- Aucune promesse de conformité : le package est un OD, la PA seule est agréée.

### docs/ — documentation d'usage

Une page par sujet : installation, configuration, webhooks, multi-tenant, events, commandes, dépannage.
La page dépannage liste les erreurs réelles avec leur cause — signature invalide, 403, tenant introuvable. C'est elle qui absorbe le support.

Toute PR qui change l'API publique met à jour la doc **dans le même commit**. Un CHANGELOG.md tenu à la main, format Keep a Changelog.

## 10. Frontières

Arrête-toi et demande avant de :

- ajouter une dépendance
- modifier la signature d'une méthode publique déjà publiée
- toucher au calcul de signature ou à la logique de déduplication
- implémenter l'émission de factures (hors périmètre v0.1)
- créer une abstraction multi-PA (v0.4, sur demande utilisateur réelle uniquement)
- écrire du code de l'édition Pro

Ne propose pas de fonctionnalité hors CDC. Le périmètre v0.1 est fermé.
