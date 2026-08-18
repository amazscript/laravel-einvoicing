# SPRINT — `amazscript/laravel-einvoicing` v0.1

**Périmètre** Réception de factures électroniques, driver Iopole.
**Référence** `CDC-laravel-einvoicing.md` — en cas de contradiction, le CDC fait foi.
**Charge estimée** 20 j (CDC §14) — voir « Réalité calendaire » ci-dessous.

Légende : `[ ]` à faire · `[~]` en cours · `[x]` fait et vérifié · `[-]` abandonné / hors périmètre

Une case ne se coche que si `composer test && composer analyse && composer format` passe.
Une story = une branche `feat/dXX-slug` = un commit. Jamais deux stories dans un commit.

---

## Réalité calendaire

Échéance réglementaire de réception obligatoire : **1er septembre 2026**. La charge v0.1 ne rentre
pas avant cette date. Découpage retenu :

- [ ] **Palier 1 — « ça reçoit »** (D01 → D12) : webhook sécurisé, routage, dédup, persistance, events. ~11 j
- [ ] **Palier 2 — « ça s'exploite »** (D13 → D16) : fichiers, polling, commandes, doc, CI. ~9 j

Le palier 1 est publiable seul sur Packagist en `v0.1.0-beta`.

---

## Environnement de travail — deux dossiers

Le package publiable ne doit jamais contenir l'application de test. Deux dossiers frères,
deux dépôts git distincts.

```
~/Development/
├── laravel-einvoicing/        le package — dépôt git publié sur Packagist
│   ├── src/  tests/  config/  database/migrations/
│   └── composer.json          name: amazscript/laravel-einvoicing
│
└── einvoicing-playground/     app Laravel 12 de recette — dépôt privé, jamais publié
    ├── composer.json          repositories: path ../laravel-einvoicing (symlink)
    ├── app/Listeners/         listeners de démonstration
    └── .env                   token sandbox Iopole, secret HMAC, URL de tunnel
```

Lien Composer, à poser dans le `composer.json` du playground :

```json
{
  "repositories": [
    { "type": "path", "url": "../laravel-einvoicing", "options": { "symlink": true } }
  ],
  "require": { "amazscript/laravel-einvoicing": "@dev" }
}
```

Le symlink rend toute modification du package immédiatement visible dans l'app, sans
`composer update`.

**Deux environnements de test, deux rôles — ne pas les confondre :**

| | `orchestra/testbench` (dans le package) | `einvoicing-playground` (app réelle) |
|---|---|---|
| Usage | tests automatisés, CI | recette manuelle, webhook réel |
| Vitesse | millisecondes | démarrage d'une app complète |
| Réseau | jamais | tunnel + sandbox Iopole |
| Vérifie | la logique | le **parcours d'installation** (CDC §3) |

Toute story se termine par testbench. Les stories D04 → D07, D15 et D16 se vérifient **en plus**
dans le playground, parce qu'une signature HMAC qui passe en test unitaire peut échouer sur une
vraie requête HTTP (proxy, en-têtes réécrits, `path_with_query` différent).

**Attention environnement local** : PHP 8.5 en CLI. Le plancher 8.3 exigé par le package n'est
donc **pas** testé localement — seule la CI le couvre (D16). Ne pas conclure « ça marche » depuis
la machine de dev pour une question de compatibilité de version.

- [x] Créer `~/Development/einvoicing-playground` (Laravel 12)
- [x] Y déclarer le `path repository` en symlink vers le package
- [x] Vérifier que le symlink est actif : `ls -l vendor/amazscript/laravel-einvoicing`
- [x] `.gitignore` du playground : `.env`, `/vendor`, `/storage`
- [x] Dépôt git séparé pour le playground — jamais poussé sur le dépôt public du package

---

## Lot 0 — Préalable : lever le risque technique (CDC §18)

Tant que ce lot n'est pas terminé, **aucune ligne de code du package**. Si le point HMAC échoue,
le CDC est à revoir avant d'engager les 20 jours. Demi-journée.

- [x] Créer la sandbox sur `labs.iopole.io`
- [x] Récupérer les identifiants OAuth2 + `customer-id`, placés dans le `.env` du playground (exclu de git)
- [ ] Ouvrir un tunnel local (ngrok / expose) et noter l'URL publique
- [ ] Pointer le tunnel sur le playground, ou sur un script PHP autonome — le lot 0 se fait
      volontairement **sans** le package, pour valider le calcul HMAC nu
- [ ] Configurer un webhook `INBOUND` vers le tunnel, noter le secret HMAC
- [ ] Émettre une facture de test depuis l'interface Iopole
- [ ] Capturer le payload multipart réel et le figer dans `tests/Fixtures/`
- [x] **Vérifier la signature HMAC à la main, en PHP** — fait par validation croisée contre
      l'implémentation Node.js de la plateforme (`tests/Fixtures/hmac-vectors.generate.js`).
      Reste à confirmer sur une livraison réelle de la plateforme.
- [x] Capteur de requêtes en place dans le playground (`POST /capture/webhook`), éprouvé sur un
      multipart : **`php://input` mesuré à 0 octet**, le piège est confirmé empiriquement.
- [ ] Vérifier la signature HMAC à la main sur un payload de statut JSON réel
- [ ] Consigner la méthode qui marche dans `docs/webhooks.md` (section « chaîne canonique »)

> Piège identifié : en `multipart/form-data`, `php://input` est **vide** — le SAPI PHP a déjà
> consommé le corps pour peupler `$_POST` / `$_FILES`. Le checksum doit venir de
> `hash_file('sha256', $file->getRealPath())` sur le fichier temporaire uploadé, pas du raw body.
> La consigne « raw body via php://input » du CDC §8 ne vaut que pour `application/json`.

---

## Lot 0 bis — Corrections à porter au CDC avant de coder

Points bloquants relevés à l'analyse. Chacun modifie le modèle de données ou le comportement,
donc coûte cher après coup.

- [x] **CDC §7** — `einvoicing_webhook_events` : ajouter `payload json`, `tenant_id uuid nullable`,
      `status enum(RECEIVED, PROCESSED, UNROUTED, FAILED)`. Sans ça, un événement `UNROUTED`
      n'a nulle part où être stocké (contradiction avec §9).
- [x] **CDC §7** — `einvoicing_inbound_invoices.tenant_id` : passer en **nullable** (cas `UNROUTED`).
- [x] **CDC §7** — `einvoicing_statuses` : contrainte `unique(provider, provider_status_id)` et non
      `unique(provider_status_id)`, sinon collision garantie au second driver (v0.4).
- [x] **CDC §7** — `einvoicing_inbound_invoices` : contrainte explicite `unique(provider, provider_invoice_id)`.
- [x] **CDC §11** — ajouter la commande `einvoicing:events:retry`. Sans elle, un job mort en échec
      définitif rend le retry de la PA invisible (`event_id` déjà connu) → **facture perdue**.
- [x] **CDC §7** — `einvoicing_tenants.customer_id` : cast `encrypted` (CLAUDE.md interdit de le logger,
      le stocker en clair est incohérent).
- [x] **CDC §12** — ajouter `webhook.canonical_path` : derrière un proxy / tunnel, l'URI reçue peut
      différer du path signé par la PA.
- [x] **CLAUDE.md §6** — les stories « D1 à D16 » n'existaient pas ; elles sont définies ci-dessous.
      Mettre à jour la référence.

---

## Lot 0 ter — Écarts entre le CDC et la documentation Iopole

Relevés le 18 août 2026 sur `docs.iopole.com`. Documentation complète copiée dans
`.iopole-docs/` (ignorée par git : contenu tiers).

### Confirmé — le CDC était juste

- **Chaîne canonique** `{timestamp}\n{METHOD}\n{path_with_query}\n{checksum}` : identique.
- **Checksum** : corps JSON intégral, ou **contenu du champ fichier seul** en multipart, les
  autres champs explicitement exclus. Le piège du §5.1 est réel et documenté comme tel.
- **Comparaison en temps constant** exigée par la doc (`timingSafeEqual`).
- `X-Timestamp`, `X-Signature`, `X-Checksum` — ce dernier optionnel, à vérifier avant la signature.
- **Livraison at-least-once**, déduplication à la charge du consommateur.
- Facture en multipart, statut en JSON. Pagination `offset` / `limit`. Backoff exponentiel sur 429.
- Routage : le payload de statut porte bien `json.context.recipients[].siret` et `.siren`.

### À corriger — le CDC est faux ou incomplet

- [x] **Signature encodée en hexadécimal** (`digest('hex')`). Le CDC ne le précisait pas.
      `hash_hmac(..., binary: false)` côté PHP. Impact : D05.
- [x] **L'authentification est OAuth2 `client_credentials`, pas un token statique.** La doc ne
      documente aucun Bearer permanent : on obtient un `access_token` sur un endpoint Keycloak
      (`/realms/iopole/protocol/openid-connect/token`) avec `client_id` + `client_secret`, puis
      on l'envoie en `Authorization: Bearer` accompagné du header `customer-id`.
      Le CDC §8 traitait OAuth2 comme un « support alternatif » : c'est en réalité la seule voie.
      **Impact : la configuration change** (`client_id`, `client_secret`, `token_url` remplacent
      `IOPOLE_TOKEN`), et D02 doit gérer l'obtention, la mise en cache et le renouvellement du
      jeton. Charge revue à la hausse.
- [ ] **Le payload de statut ne contient pas de `eventId`.** Il porte `invoiceId` et `statusId`.
      Le `eventId` n'apparaît que dans les événements d'onboarding et le format générique
      « events ». La doc affirme qu'« une clé d'idempotence est présente dans le header » mais
      **ne nomme jamais ce header** — les seuls documentés sont `X-Timestamp`, `X-Signature`,
      `X-Checksum`. **Impact direct sur D08** : la clé de déduplication n'est pas identifiée.
      À lever sur une requête sandbox réelle (lot 0), ou par une question au support Iopole.
- [x] **Pagination : `limit` vaut 50 par défaut**, `offset` 0. Aucun plafond de 100 n'est
      documenté — l'affirmation du CDC n'était pas vérifiable, corrigée en v1.2. Le maximum réel
      reste à mesurer sur la sandbox avant d'écrire l'itérateur paresseux (D14).
- [x] **`GET /v1/invoice/notSeen` ne pagine pas** — vérifié sur l'API réelle : il renvoie un
      tableau nu, sans enveloppe `data`/`meta`. Les listes paginées, elles, renvoient
      `{"data": [...], "meta": {offset, limit, count}}`. **D14 est à reconcevoir** : on consomme
      la liste puis on avance par `markAsSeen`, faute de quoi les mêmes factures reviennent.
- [x] **Quelle URL de base ?** Deux environnements confirmés via leur configuration OpenID :
      pré-production `api.ppd.iopole.fr` avec `auth.preprod.iopole.fr`, production
      `api.iopole.com` avec `auth.iopole.com`. Le playground pointe la pré-production.

### Reste à lire dans `.iopole-docs/`

- [ ] `apiDescriptions/webhook/*` : création et mise à jour du webhook, `subscribedEvents`
- [ ] `apiDescriptions/webhook/retryStrategy/*` : stratégie de retry (D15)
- [ ] `apiDescriptions/invoice/*` : `searchInvoiceNotseen`, `markInvoiceAsSeen`, `InvoiceObject`,
      `getInvoiceFiles`, `downloadReadable`, `getAttachments` (D13, D14)
- [ ] `apiDescriptions/status/*` (D11)
- [x] `errors` : format exact des 400 et des 409 (D02)
- [x] `pagination`, `authentication`, `reference`, `security`

---

## D01 — Socle du package

- [x] `git init`, branche `main`, `.gitignore` (`/vendor`, `.env*`, `.DS_Store`, `/build`, `.phpunit.cache`)
- [x] Sortir `tSs5K.jpg` du dossier (sans rapport avec le projet)
- [x] `composer.json` : namespace `AmazScript\Einvoicing\`, PHP `^8.3`, `illuminate/*` 11|12, `guzzlehttp/guzzle`
- [x] `extra.laravel.providers` → `EinvoicingServiceProvider`
- [x] Scripts `composer test` / `analyse` / `format`
- [x] `EinvoicingServiceProvider` : publication config + migrations, bindings
- [x] `config/einvoicing.php` conforme au CDC §12 (+ `canonical_path`)
- [x] Pest + `orchestra/testbench` + `TestCase` de base
- [x] Pint preset `laravel`, PHPStan niveau 8, `declare(strict_types=1)` partout
- [x] `LICENSE` (MIT), `CHANGELOG.md` (Keep a Changelog), `README.md` squelette
- [x] Playground relié : `composer install` vert et `vendor/amazscript/laravel-einvoicing` = symlink
- [x] Config publiée dans le playground via `vendor:publish` (`einvoicing:install` arrive en D15)
- [x] **DoD** : `composer test` passe sur un test trivial, `analyse` et `format` verts

## D02 — Client HTTP Iopole

- [-] `Contracts/Driver` — reporté à D14 : aucune méthode métier à y déclarer avant le polling,
      et figer une interface sur un seul driver reviendrait à inventer l'abstraction avant l'usage.
- [x] `Drivers/Iopole/Client` : Bearer + header `customer-id`, base URL configurable
- [x] `Drivers/Iopole/Endpoints` : versions `/v1` et `/v1.1` encapsulées (C8), invisibles du consommateur
- [x] Mapping des erreurs → `EinvoicingValidationException` (400 Zod : `path`, `code`, `message`),
      `EinvoicingAuthException` (401/403), `EinvoicingConflictException` (409),
      `EinvoicingRateLimitException` (429), `EinvoicingServerException` (5xx)
- [x] `409 DUPLICATE_RESOURCE` traité comme **succès** (C7)
- [x] Aucun secret ni `customer-id` dans les messages d'exception
- [x] Tests : chaque code d'erreur → exception attendue, sur fixtures figées
- [x] **DoD** : zéro appel réseau réel dans la suite de tests

## D03 — Tenants et migrations

- [x] Migrations des 5 tables (CDC §7 + corrections du lot 0 bis)
- [x] Modèles `Tenant`, `InboundInvoice`, `InvoiceFile`, `Status`, `WebhookEvent`
- [x] `Tenant` : morphs vers le modèle hôte, `customer_id` chiffré, index `siren` / `siret`
- [x] Tests : migrations up/down, contraintes uniques effectivement posées en base
- [x] **DoD** : une violation de contrainte unique remonte bien comme telle (pas de check applicatif seul)

## D04 — Route webhook et capture du corps brut

- [x] Route unique enregistrée par le provider (`webhook.path`, `webhook.middleware`)
- [x] Middleware prioritaire de capture : `php://input` en JSON, fichier temporaire en multipart
- [x] Aucun `throttle` dans le groupe de middleware (un 429 déclenche le retry de la PA pour rien)
- [~] Contrôleur : vérifier → dédupliquer → dispatcher → répondre 2xx. Vérification et réponse
      faites ; déduplication et dispatch arrivent en D08 et D10.
- [x] Réponse 2xx sous 200 ms, jamais de 5xx sur erreur métier
- [x] Tests : payload malformé → 2xx + event d'erreur, aucune exception remontée
- [x] **DoD** : le contrôleur ne contient aucune logique métier

## D05 — Signature HMAC (point critique 5.1)

**Test d'abord.** Ne pas toucher au calcul sans relire CLAUDE.md §10.

- [x] Chaîne canonique `{X-Timestamp}\n{METHOD}\n{path_with_query}\n{checksum}`
- [x] Checksum JSON = SHA-256 du corps brut intégral
- [x] Checksum multipart = SHA-256 du **contenu du champ fichier uniquement** (autres champs exclus)
- [x] `hash_equals` obligatoire, jamais `===`
- [x] Secret absent ou < 32 octets → la route refuse tout (401), jamais « pas de secret, pas de contrôle »
- [x] `path_with_query` lu depuis `canonical_path` si configuré
- [x] `Contracts/SignatureVerifier` remplaçable via le container
- [x] Test : signature valide en JSON
- [x] Test : signature valide en multipart **avec champs annexes** (le piège du 5.1)
- [ ] Test : signature invalide → 401, **rien en base**
- [x] Test : event `WebhookSignatureRejected` émis
- [ ] **DoD** : les tests tournent sur les fixtures réelles capturées au lot 0

## D06 — Anti-rejeu et checksum d'en-tête

- [x] Rejet si `X-Timestamp` dévie de plus de `webhook.tolerance` (défaut 300 s)
- [x] Vérification de `X-Checksum` quand l'en-tête est présent, **avant** la signature
- [x] Tests : timestamp trop ancien, trop futur, absent, non numérique
- [ ] **DoD** : aucun rejet ne produit de 5xx

## D07 — Routage multi-tenant (point critique 5.2)

- [x] `Contracts/TenantResolver`, implémentation `Tenancy/SiretResolver`
- [x] Ordre de résolution : `idPath` → SIRET → SIREN → tenant unique actif par défaut
- [x] Fallback « tenant unique » : log niveau `warning` (filet, pas stratégie)
- [ ] Échec → persistance `status = UNROUTED`, `tenant_id = null`, payload conservé
      — le résolveur signale l'échec, la persistance revient au webhook (D04)
- [x] Event `TenantResolutionFailed`
- [ ] Réponse 2xx malgré l'échec, aucune perte de donnée
- [-] `Tenancy/TenantContext` — reporté : aucun besoin réel tant qu'aucun job ne doit porter
      un tenant courant. À créer en D10 si le besoin apparaît vraiment.
- [x] Tests : les 4 stratégies, une par une
- [x] Test : tenant introuvable → event émis (le `UNROUTED` + 2xx se testent en D04)
- [ ] **DoD** : couverture ≥ 85 % sur `Tenancy/` — **non mesurée** : ni Xdebug ni PCOV installés
      sur la machine de dev. À vérifier par la CI (D16), qui doit installer PCOV.

## D08 — Déduplication (point critique 5.3)

- [ ] Contrainte unique en base sur `event_id` — pas de vérification applicative seule
- [ ] Violation de contrainte unique = **succès** (déjà traité), pas une erreur
- [ ] Insertion de l'événement puis dispatch en `after_commit` (sinon job orphelin sur rollback)
- [ ] Échec définitif du job → `status = FAILED` + `failed_reason`, jamais silencieux
- [ ] Test : même `event_id` deux fois → un seul traitement
- [ ] Test : deux requêtes concurrentes sur le même `event_id` → un seul job
- [ ] **DoD** : un job mort ne rend pas la facture irrécupérable (voir D15 `events:retry`)

## D09 — Idempotence des factures (point critique 5.4)

- [ ] Toute écriture de facture via `updateOrCreate` sur `(provider, provider_invoice_id)`
- [ ] Idem pour les statuts sur `(provider, provider_status_id)`
- [ ] Test : rejeu complet d'un webhook → aucun doublon en base
- [ ] Test : deux événements différents pour la même facture → une seule ligne, mise à jour
- [ ] **DoD** : rejouer 3 fois la même fixture laisse la base identique

## D10 — Job de traitement des factures entrantes

- [ ] `Jobs/ProcessInboundInvoice`, queue et connexion configurables
- [ ] Parsing du payload multipart → `InboundInvoice` (numéro, date, émetteur, montants, format)
- [ ] `raw_metadata` : payload brut conservé intégralement
- [ ] `429` → backoff exponentiel (C6)
- [ ] Aucune donnée de facture ni SIREN/SIRET en clair dans les logs d'erreur
- [ ] Tests sur fixtures figées : `FACTURX`, `UBL`, `CII`
- [ ] **DoD** : le job est rejouable sans effet de bord

## D11 — Statuts de cycle de vie

- [ ] `Jobs/ProcessStatusUpdate`
- [ ] Parsing du payload JSON → `Status` (`code`, `value`, `description`, `dest_type`, `occurred_at`)
- [ ] Rattachement à la facture quand l'ID est connu, `invoice_id` nullable sinon
- [ ] `payload` : JSON + XML bruts conservés
- [ ] Contract test sur la fixture de statut de la doc Iopole
- [ ] Contract test sur `INVOICE_INBOUND_INVALID`
- [ ] **DoD** : un statut orphelin ne fait pas échouer le job

## D12 — Events Laravel exposés

- [ ] `InboundInvoiceReceived`
- [ ] `InvoiceStatusUpdated`
- [ ] `InboundInvoiceInvalid`
- [ ] `OutboundInvoiceNotDelivered` (préparation v0.2, non déclenché en v0.1)
- [ ] `TenantResolutionFailed`
- [ ] `WebhookSignatureRejected`
- [ ] Aucun objet Iopole ne fuit dans la charge utile des events (frontière CLAUDE.md §4)
- [ ] Tests : chaque event émis dans son scénario nominal
- [ ] **DoD** : palier 1 publiable — un listener sur `InboundInvoiceReceived` reçoit une vraie facture

## D13 — Fichiers et stockage

- [ ] Téléchargement XML, PDF lisible, pièces jointes
- [ ] Stockage via disque Laravel configurable (`storage.disk`, `storage.path`)
- [ ] `InvoiceFile` : `kind`, `disk`, `path`, `checksum` SHA-256
- [ ] Re-téléchargement idempotent (checksum identique → pas de doublon)
- [ ] `attachments()->store('s3')`
- [ ] Tests avec `Storage::fake()`
- [ ] **DoD** : aucun fichier écrit hors du disque configuré

## D14 — Polling de repli

- [ ] `notSeen()` et `markAsSeen()`
- [ ] Itérateur paresseux sur pagination `offset` / `limit`, plafond 100 (C5)
- [ ] `Einvoicing::for($tenant)->invoices()->search([...])->lazy()`
- [ ] Trancher : `notSeen()` interroge la PA ou la base locale ? Nommage explicite décidé et documenté
- [ ] `Einvoicing::directory()->search('IOPOLE')`
- [ ] Facade `Einvoicing` — seule API statique autorisée
- [ ] Tests de pagination : 0, 1, 99, 100, 250 éléments
- [ ] **DoD** : aucune requête N+1, aucun chargement complet en mémoire

## D15 — Commandes Artisan

- [ ] `einvoicing:install`
- [ ] `einvoicing:secret` (≥ 32 octets aléatoires)
- [ ] `einvoicing:webhooks:sync`
- [ ] `einvoicing:retry:sync`
- [ ] `einvoicing:poll {--tenant=}`
- [ ] `einvoicing:events:prune`
- [ ] `einvoicing:events:retry` — rejeu des événements `FAILED` / `UNROUTED` (ajout, cf. lot 0 bis)
- [ ] `einvoicing:doctor` : token, `customer-id`, souscription webhook, accessibilité de l'URL,
      cohérence du `canonical_path`, présence d'un throttle sur la route, secret configuré
- [ ] Tests : chaque commande, cas nominal **et** cas d'erreur
- [ ] **DoD** : `doctor` diagnostique une config cassée sans exposer le moindre secret

## D16 — Tests, documentation, CI, publication

- [ ] Couverture ≥ 85 % sur `Webhook/` et `Tenancy/`, ≥ 70 % ailleurs
- [ ] CI GitHub Actions : matrice PHP 8.3 / 8.4 × Laravel 11 / 12, lint + tests + analyse
- [ ] `README.md` dans l'ordre imposé (CLAUDE.md §9) — installation visible sans scroller
- [ ] Chaque bloc de code du README copiable et fonctionnel tel quel
- [ ] `docs/` : installation, configuration, webhooks, multi-tenant, events, commandes, dépannage
- [ ] `docs/depannage.md` : signature invalide, 403, tenant introuvable, 429, job en échec
- [ ] `CHANGELOG.md` à jour
- [ ] Aucune promesse de conformité — le package est un OD, la PA seule est agréée
- [ ] Publication Packagist, tag `v0.1.0`
- [ ] **DoD** : recette finale dans un playground **neuf** — un dev installe, configure, reçoit sa première facture en < 15 min (CDC §3)

---

## Décisions en attente

- [ ] **Support de Laravel 13 ?** `composer create-project laravel/laravel` installe aujourd'hui
      Laravel **13.26** avec **Guzzle 8**. Le CDC (§13) cible 11/12 et le package contraint
      `illuminate/* ^11.0|^12.0` + `guzzlehttp/guzzle ^7.8` : il est donc **ininstallable sur une
      app Laravel 13 neuve**, c'est-à-dire sur l'app par défaut d'un nouveau projet. Trois issues :
      élargir à `^13.0` et `guzzle ^7.8|^8.0` (implique `orchestra/testbench ^11` et une ligne de
      matrice CI en plus), rester sur 11/12 et l'assumer dans le README, ou sortir en 11/12 puis
      ajouter 13 en v0.1.1. Décision produit — non tranchée de mon côté.
- [ ] `notSeen()` : API distante ou base locale ? (impacte D14 et le README)
- [ ] Palier 1 publié en `beta` avant le 1er septembre, ou attente de la v0.1 complète ?
- [ ] Support OAuth2 `client_credentials` (CDC §8) : v0.1 ou v0.2 ?

## Journal

| Date | Story | État | Note |
|---|---|---|---|
| 2026-08-18 | — | — | Sprint créé |
| 2026-08-18 | Environnement | fait | Playground Laravel 12.67 créé, symlink actif, config publiée |
| 2026-08-18 | D01 | fait | Socle du package : `test` 3 verts, `analyse` 0 erreur, `format` propre |
| 2026-08-18 | — | alerte | Laravel 13 est la version par défaut — voir Décisions en attente |
| 2026-08-18 | Lot 0 bis | fait | CDC porté en v1.1, 8 corrections + journal des révisions (§19) |
| 2026-08-18 | D03 | fait | 5 migrations, 5 modèles, 3 enums — 23 tests verts, PHPStan 8 sans erreur |
| 2026-08-18 | Lot 0 ter | fait | Doc Iopole récupérée (93 pages) — 4 écarts avec le CDC, voir lot 0 ter |
| 2026-08-18 | D02 | fait | Client OAuth2 + 6 exceptions + 76 endpoints figés — 62 tests verts |
| 2026-08-18 | — | commits | Dépôt initialisé, 4 commits, une branche par story, `main` vert |
| 2026-08-18 | Docs | fait | CDC en v1.2, CLAUDE.md et SPRINT alignés — incohérences levées |
| 2026-08-18 | D07 | fait | Routage multi-tenant, 4 stratégies — 21 tests verts |
| 2026-08-18 | D05/D06 | fait | Signature HMAC validée contre l'implémentation de référence — 26 tests |
| 2026-08-18 | Lot 0 | partiel | Sandbox opérationnelle : auth OAuth2 et appel API vérifiés en réel |
| 2026-08-18 | D02 | corrigé | `/v1/config/customer/id` répond en text/html — ajout de `Client::raw()` |
| 2026-08-18 | Lot 0 | avancé | Webhook INBOUND activé vers le tunnel, secret HMAC déclaré |
| 2026-08-18 | — | constat | Sandbox sans entité enregistrée : rien ne peut encore y être reçu |
| 2026-08-18 | D04 | fait | Route webhook, capture du corps, signature — 11 tests, vérifié dans le playground |
