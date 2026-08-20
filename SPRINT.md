# SPRINT — `amazscript/laravel-einvoicing` v0.1

**Périmètre** Réception de factures électroniques, driver Iopole.
**Référence** `CDC-laravel-einvoicing.md` — en cas de contradiction, le CDC fait foi.
**Charge estimée** 20 j (CDC §14) — voir « Réalité calendaire » ci-dessous.

Légende : `[ ]` à faire · `[~]` en cours · `[x]` fait et vérifié · `[-]` abandonné / hors périmètre

Une case ne se coche que si `composer test && composer analyse && composer format` passe.
Une story = une branche `feat/dXX-slug` = un commit. Jamais deux stories dans un commit.

---

## Réalité calendaire

Échéance réglementaire de réception obligatoire : **1er septembre 2026**.

**Les deux paliers ont été livrés le 19 août**, treize jours avant l'échéance. Le découpage prévu
pour absorber un dépassement n'a pas eu à servir ; il reste consigné tel qu'il avait été décidé.

- [x] **Palier 1 — « ça reçoit »** (D01 → D12) : webhook sécurisé, routage, dédup, persistance, events. ~11 j
- [x] **Palier 2 — « ça s'exploite »** (D13 → D16) : fichiers, polling, commandes, doc, CI. ~9 j

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
- [x] Ouvrir un tunnel local (ngrok / expose) et noter l'URL publique
- [x] Pointer le tunnel sur le playground, ou sur un script PHP autonome — le lot 0 se fait
      volontairement **sans** le package, pour valider le calcul HMAC nu
- [x] Configurer un webhook `INBOUND` vers le tunnel, noter le secret HMAC
- [x] Émettre une facture de test — faite par l'API, statut `REJECTED` (destinataire non routable
      dans l'annuaire), mais la livraison du statut a fourni le vecteur recherché
- [x] Capturer le payload **multipart** réel — non obtenu : aucune facture entrante n'a pu être
      remise. Le payload **JSON** d'un statut, lui, est figé en fixture.
- [x] **Vérifier la signature HMAC à la main, en PHP** — d'abord par validation croisée contre
      l'implémentation Node.js de la plateforme, puis **sur une livraison qu'elle a réellement
      émise** : signature reproduite à l'octet près. Le risque technique du projet est levé.
- [x] Capteur de requêtes en place dans le playground (`POST /capture/webhook`), éprouvé sur un
      multipart : **`php://input` mesuré à 0 octet**, le piège est confirmé empiriquement.
- [x] Vérifier la signature HMAC sur un payload de statut JSON réel
- [x] Consigner la méthode qui marche dans `docs/webhooks.md` (section « chaîne canonique »)

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
- [x] **Le payload de statut ne contient pas de `eventId`.** Il porte `invoiceId` et `statusId`.
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

- [x] `apiDescriptions/webhook/*` : création et mise à jour du webhook, `subscribedEvents`
- [x] `apiDescriptions/webhook/retryStrategy/*` : stratégie de retry (D15)
- [x] `apiDescriptions/invoice/*` : `searchInvoiceNotseen`, `markInvoiceAsSeen`, `InvoiceObject`,
      `getInvoiceFiles`, `downloadReadable`, `getAttachments` (D13, D14)
- [x] `apiDescriptions/status/*` (D11)
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

- [x] `Contracts/Driver` — reporté à D14 : aucune méthode métier à y déclarer avant le polling,
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
- [x] Test : signature invalide → 401, **rien en base**
- [x] Test : event `WebhookSignatureRejected` émis
- [x] **DoD** : les tests tournent sur les fixtures réelles capturées au lot 0

## D06 — Anti-rejeu et checksum d'en-tête

- [x] Rejet si `X-Timestamp` dévie de plus de `webhook.tolerance` (défaut 300 s)
- [x] Vérification de `X-Checksum` quand l'en-tête est présent, **avant** la signature
- [x] Tests : timestamp trop ancien, trop futur, absent, non numérique
- [x] **DoD** : aucun rejet ne produit de 5xx

## D07 — Routage multi-tenant (point critique 5.2)

- [x] `Contracts/TenantResolver`, implémentation `Tenancy/SiretResolver`
- [x] Ordre de résolution : `idPath` → SIRET → SIREN → tenant unique actif par défaut
- [x] Fallback « tenant unique » : log niveau `warning` (filet, pas stratégie)
- [x] Échec → persistance `status = UNROUTED`, `tenant_id = null`, payload conservé
      — le résolveur signale l'échec, la persistance revient au webhook (D04)
- [x] Event `TenantResolutionFailed`
- [x] Réponse 2xx malgré l'échec, aucune perte de donnée
- [-] `Tenancy/TenantContext` — reporté : aucun besoin réel tant qu'aucun job ne doit porter
      un tenant courant. À créer en D10 si le besoin apparaît vraiment.
- [x] Tests : les 4 stratégies, une par une
- [x] Test : tenant introuvable → event émis (le `UNROUTED` + 2xx se testent en D04)
- [x] **DoD** : couverture ≥ 85 % sur `Tenancy/` — mesurée à 100 %.

## D08 — Déduplication (point critique 5.3)

- [x] Contrainte unique en base sur `event_id` — pas de vérification applicative seule
- [x] Violation de contrainte unique = **succès** (déjà traité), pas une erreur
- [~] Insertion de l'événement faite ; le dispatch `after_commit` arrive avec les jobs (D10)
- [x] Échec définitif du job → `status = FAILED` + `failed_reason` — dépend des jobs (D10)
- [x] Test : même `event_id` deux fois → un seul traitement
- [x] Test : deux requêtes concurrentes sur le même `event_id` → un seul job
- [x] **DoD** : un job mort ne rend pas la facture irrécupérable (voir D15 `events:retry`)

## D09 — Idempotence des factures (point critique 5.4)

- [x] Toute écriture de facture via `updateOrCreate` sur `(provider, provider_invoice_id)`
- [x] Idem pour les statuts sur `(provider, provider_status_id)`
- [x] Test : rejeu complet d'un webhook → aucun doublon en base
- [x] Test : deux événements différents pour la même facture → une seule ligne, mise à jour
- [x] **DoD** : rejouer 3 fois la même fixture laisse la base identique

## D10 — Job de traitement des factures entrantes

- [x] `Jobs/ProcessInboundInvoice`, queue et connexion configurables
- [x] Parsing du payload multipart → `InboundInvoice` : le webhook ne porte que `invoiceId` et
      `senderAcceptStatus`. Numéro, date, montants et émetteur devront être récupérés via l'API (D13).
- [x] `raw_metadata` : payload brut conservé intégralement
- [x] `429` → backoff exponentiel (C6)
- [x] Aucune donnée de facture ni SIREN/SIRET en clair dans les logs d'erreur
- [x] Tests sur fixture figée issue d'une livraison réelle. Le format n'est pas annoncé dans le
      webhook : la facture arrive en PDF, sans indication de `FACTURX`/`UBL`/`CII`.
- [x] **DoD** : le job est rejouable sans effet de bord

## D11 — Statuts de cycle de vie

- [x] `Jobs/ProcessStatusUpdate`
- [x] Parsing du payload JSON → `Status` (`code`, `value`, `description`, `dest_type`, `occurred_at`)
- [x] Rattachement à la facture quand l'ID est connu, `invoice_id` nullable sinon
- [x] `payload` : JSON + XML bruts conservés
- [x] Contract test sur la fixture de statut de la doc Iopole
- [x] Contract test sur `INVOICE_INBOUND_INVALID`
- [x] **DoD** : un statut orphelin ne fait pas échouer le job

## D12 — Events Laravel exposés

- [x] `InboundInvoiceReceived`
- [x] `InvoiceStatusUpdated`
- [x] `InboundInvoiceInvalid`
- [x] `OutboundInvoiceNotDelivered` (préparation v0.2, non déclenché en v0.1)
- [x] `TenantResolutionFailed`
- [x] `WebhookSignatureRejected`
- [x] Aucun objet Iopole ne fuit dans la charge utile des events (frontière CLAUDE.md §4)
- [x] Tests : chaque event émis dans son scénario nominal
- [x] **DoD** : palier 1 publiable — un listener sur `InboundInvoiceReceived` reçoit une vraie facture

## D13 — Fichiers et stockage

- [x] Téléchargement XML, PDF lisible, pièces jointes
- [x] Stockage via disque Laravel configurable (`storage.disk`, `storage.path`)
- [x] `InvoiceFile` : `kind`, `disk`, `path`, `checksum` SHA-256
- [x] Re-téléchargement idempotent (checksum identique → pas de doublon)
- [x] `attachments()->store('s3')` — l'API publique de consultation arrive avec la façade (D14)
- [x] Tests avec `Storage::fake()`
- [x] **DoD** : aucun fichier écrit hors du disque configuré

## D14 — Polling de repli

- [x] `notSeen()` et `markAsSeen()`
- [x] Itérateur paresseux sur pagination `offset` / `limit`, plafond 100 (C5)
- [x] `Einvoicing::for($tenant)->invoices()->search([...])` — sur `/v1.1/invoice/search`
- [x] Trancher : `notSeen()` interroge la PA ou la base locale ? Nommage explicite décidé et documenté
- [x] `Einvoicing::directory()->search('IOPOLE')`
- [x] Facade `Einvoicing` — seule API statique autorisée
- [x] Tests de pagination : 0, 1, 99, 100, 250 éléments
- [x] **DoD** : aucune requête N+1, aucun chargement complet en mémoire

## D15 — Commandes Artisan

- [x] `einvoicing:install`
- [x] `einvoicing:secret` (≥ 32 octets aléatoires)
- [x] `einvoicing:webhooks:sync`
- [x] `einvoicing:retry:sync`
- [x] `einvoicing:poll {--tenant=}`
- [x] `einvoicing:events:prune`
- [x] `einvoicing:events:retry` — rejeu des événements `FAILED` / `UNROUTED` (ajout, cf. lot 0 bis)
- [x] `einvoicing:doctor` : token, `customer-id`, souscription webhook, accessibilité de l'URL,
      cohérence du `canonical_path`, présence d'un throttle sur la route, secret configuré
- [x] Tests : chaque commande, cas nominal **et** cas d'erreur
- [x] **DoD** : `doctor` diagnostique une config cassée sans exposer le moindre secret

## D16 — Tests, documentation, CI, publication

- [x] Couverture ≥ 85 % sur `Webhook/` et `Tenancy/`, ≥ 70 % ailleurs — **mesurée** : 97,5 % sur
      les points critiques, 84,5 % au global.
- [x] CI GitHub Actions : matrice PHP 8.3 / 8.4 × Laravel 11 / 12, lint + tests + analyse
- [x] `README.md` dans l'ordre imposé (CLAUDE.md §9) — installation visible sans scroller
- [x] Chaque bloc de code du README copiable et fonctionnel tel quel
- [x] `docs/` : installation, configuration, webhooks, multi-tenant, events, commandes, dépannage
- [x] `docs/depannage.md` : signature invalide, 403, tenant introuvable, 429, job en échec
- [x] `CHANGELOG.md` à jour
- [x] Aucune promesse de conformité — le package est un OD, la PA seule est agréée
- [x] Tag `v0.1.0` poussé le 20/08 avec `main`
- [ ] Publication Packagist (soumission de l'URL du dépôt, côté packagist.org)
- [x] **DoD** : recette finale dans un playground **neuf** — un dev installe, configure, reçoit sa première facture en < 15 min (CDC §3)

---

## D17 — Lire les entreprises et leur joignabilité

Hors périmètre v0.1 à l'origine, ouverte sur demande : une entreprise déclarée mais non desservie
ne reçoit rien, et rien ne le disait avant qu'une facture ne rebondisse.

- [x] `BusinessEntity`, `EntityIdentifier`, `NetworkRegistration` — objets de valeur, aucun Iopole
- [x] `BusinessEntityGateway` (contrat) + implémentation Iopole sous `Drivers/Iopole`
- [x] `Einvoicing::entities()` : `all()`, `find()`, `reachable()`, `unreachable()`, parcours paresseux
- [x] `unreachableReason()` rend un **code** (`no-identifier`, `no-registration`,
      `no-serving-platform`), pas une phrase — la langue appartient à l'application hôte
- [x] `einvoicing:doctor` contrôle chaque entreprise et nomme la cause
- [x] Lecture seule : aucun enregistrement d'entité, aucune écriture dans l'annuaire
- [x] 11 tests **sur la réponse réelle copiée à la lettre**, dont l'endpoint unitaire qui répond
      par une liste, l'inscription sans adresse et l'inscription à effet futur
- [x] `docs/entreprises.md`, dépannage et README raccordés
- [x] **DoD** : vérifié en réel sur la sandbox — 8 entreprises déclarées, **8 joignables**,
      cohérent avec les factures effectivement reçues

**Correction du 20/08.** La première version reposait sur un champ `platformDetail` qui **n'existe
pas** dans la réponse : il avait été supposé, pas relevé, et les tests validaient cette forme
inventée. Résultat, 8 entreprises sur 8 déclarées injoignables alors que deux d'entre elles
venaient de recevoir une facture — la contradiction a été vue à l'écran, pas par les tests.
La joignabilité se lit en réalité sur `networkRegistered[].directoryAddress` et sa date d'effet.
Au passage, l'adresse qui route (`0225:…`) n'est pas l'identifiant légal (`0002:…`) affiché
jusque-là. Règle rappelée : une fixture se copie d'une réponse réelle, elle ne se rédige pas.

---

## D18 — Boucler la réception réelle

Constat : la chaîne n'avait jamais tourné seule. Le webhook de la plateforme pointait sur le
capteur brut du playground, et les factures en base venaient de rejeux manuels.

- [x] Webhook INBOUND rebasculé sur `/einvoicing/webhook`, secret HMAC refourni dans le `PUT`
- [x] Livraison réelle rejouée **signée** à travers la vraie route : `202` en 125 ms, job traité
      en 12,65 ms, idempotence vérifiée (aucun doublon créé)
- [x] `doctor` détecte un worker absent ou lancé sur la mauvaise file — 3 tests
- [x] Worker documenté dans le README et `docs/installation.md`, absent des deux jusqu'ici
- [x] **DoD** : `doctor` tout vert sur la sandbox, worker inclus

**Le piège trouvé en chemin.** Le package dispatche sur la file `einvoicing`. Un worker lancé sans
`--queue=einvoicing` écoute `default` et ne voit jamais ces jobs : la route répond `202`, les
livraisons s'enregistrent, et rien n'est traité. Le parcours d'installation ne mentionnait pas le
worker — un utilisateur suivant le README aurait eu un système muet, sans un message d'erreur.

Reste ouvert : aucune facture n'a encore traversé la chaîne **depuis une émission réelle**. Le
rejeu signé prouve la route, la file et l'idempotence, pas la livraison par la plateforme.

---

# Palier v0.2 — Émission

Ouvert le 20 août sur demande utilisateur : « il faut toutes les versions pour un produit complet
que je peux tester ». Le périmètre v0.1 était clos, il est rouvert.

**Contrainte structurante.** Aucun endpoint n'accepte de données de facture en JSON : l'émission
passe obligatoirement par un fichier PDF ou XML. Le package ne le fabrique pas — il transporte ce
que l'application produit. La règle absolue du CLAUDE.md tient, et elle est juste : un format
fiscal invalide est un problème fiscal.

## D19 — Envoyer une facture

- [x] `Client::upload()` — multipart, flux depuis le disque, rejeu du 401 sur flux rouvert
- [x] `OutboundInvoiceGateway` (contrat) + implémentation Iopole
- [x] Migration + modèle `OutboundInvoice`, enum `OutboundStatus`
- [x] `Einvoicing::for($tenant)->send($chemin)` — ligne écrite **avant** l'appel, unicité sur
      `(tenant_id, file_hash)` : l'endpoint n'a pas de clé d'idempotence, la base la remplace
- [x] Refus conservé avec sa raison, jamais effacé
- [x] Events `OutboundInvoiceSent` et `OutboundInvoiceFailed`
- [x] 9 tests, dont la double émission et le refus
- [x] `docs/emission.md`
- [x] **DoD** : émission réelle acceptée par la sandbox, second envoi du même fichier renvoyant la
      même ligne sans rappeler la plateforme

**Bug trouvé par le test.** `Client::request()` appliquait `asJson()`, dont l'en-tête
`Content-Type: application/json` **survit** à un `asMultipart()` appelé ensuite : le corps serait
parti en multipart sous une fausse étiquette. Une requête d'upload distincte le corrige.

## D20 — Suivre le cycle de vie sortant

- [x] Webhook OUTBOUND rebasculé sur la route du package — un flux sortant n'accepte que
      l'endpoint `status`, la plateforme refuse `invoice`
- [x] Colonne `outbound_invoice_id` sur les statuts, rattachement dans `ProcessStatusUpdate`
- [x] `Einvoicing::for($tenant)->sent()` : `get`, `failed`, `awaitingDelivery`, `rejected`, `find`
- [x] `deliveryFailed()` / `failureCode()` sur les codes **observés**, liste ouverte
- [x] Routage des statuts sortants par identifiant de facture — 4 tests écrits **avant** le code
- [x] 12 tests au total (8 cycle de vie, 4 routage)
- [x] `docs/emission.md` complété
- [x] **DoD** : facture émise en réel, statut revenu par webhook, routé, rattaché —
      `UNACCEPTABLE / UNKNOWN_INVOICE_FLAVOR`, verdict exact sur un fichier qui n'était pas un
      Factur-X valide

**Deux défauts trouvés par le test en réel.**

Le premier était prévisible mais invisible en test : un statut de facture émise nomme le **client**
comme destinataire, jamais l'émetteur. Le routage multi-tenant n'y trouvait rien, et le premier
statut réel est arrivé en `UNROUTED` — enregistré, jamais traité. Rattaché désormais par
l'identifiant de la facture, la clé la plus sûre puisqu'elle vient de chez nous.

Le second est un **bug de la v0.1** : `einvoicing:events:retry` promettait « le tenant manquant a pu
être créé depuis » mais relisait un `tenant_id` resté nul, sans jamais refaire la résolution. Un
événement `UNROUTED` ne pouvait donc être récupéré par aucun moyen. La commande refait le routage
depuis le payload conservé.

## D21 — Accepter ou refuser une facture reçue

- [x] `POST /v1/invoice/{id}/status` via `InvoiceGateway::postStatus()`
- [x] Enums `BuyerStatus` (9 codes) et `RejectionReason` (28 motifs normatifs)
- [x] `acknowledge()`, `approve()`, `refuse()`, `dispute()`, `reportPayment()`, `answer()`
- [x] Refus sans motif et paiement sans montant refusés **avant** l'appel réseau
- [x] Isolation : un dossier ne répond pas pour un autre
- [x] 10 tests
- [x] `docs/reponses.md`
- [x] **DoD** : `IN_HAND` puis `REFUSED` envoyés en réel, revenus par webhook et rattachés seuls
      à la facture reçue

**Choix assumé.** Ces codes sont **envoyés**, pas rapportés : l'API les valide contre un ensemble
fermé. Une énumération y protège l'appelant d'un 400, là où elle masquerait un code authentique du
côté réception. Les deux traitements sont opposés parce que les deux situations le sont.

---

# Palier v0.3 — Onboarding en écriture

## D22 — Déclarer une entreprise et l'inscrire à l'annuaire

- [x] Enums `EntityScope`, `VatRegime`, `InvoicingNetwork` — valeurs relevées dans la spec
- [x] `declareLegalUnit()` et `registerOnNetwork()` sur le contrat + driver Iopole
- [x] `Einvoicing::entities()->declareLegalUnit()` / `->register()`
- [x] SIREN vérifié avant l'appel : mieux vaut échouer ici que créer une entité inutilisable
- [x] 9 tests
- [x] `docs/entreprises.md` complété
- [x] **DoD** : déclaration réelle faite le 20/08 sur l'entreprise de l'utilisateur —
      déclarée, inscrite à l'annuaire (`0225:…`), joignable, dossier local créé, `doctor` vert
      sur 9 entreprises

**Deux bugs que seule l'écriture réelle pouvait montrer.**

`declareLegalUnit()` levait une exception quand la réponse ne portait pas d'`id` — or l'entité
**avait bien été créée**. Signaler un échec après une écriture réussie invite au retry, et le retry
crée le doublon. Le code rend désormais une chaîne vide (« créée, non nommée ») et sait déballer une
réponse rendue sous forme de liste, comme les autres endpoints unitaires.

`register()` envoyait un corps vide quand aucune option n'était passée : un tableau PHP vide
s'encode `[]` là où l'endpoint attend `{}`, et il répondait « Expected object, received array » sans
rien inscrire. L'inscription échouait silencieusement dans le cas le plus courant — celui sans
option.

**Trouvé dans la spec.** `platformDetail` y est marqué *White Label ONLY* : voilà pourquoi il
n'apparaissait dans aucune de nos réponses, et pourquoi le diagnostic de joignabilité bâti dessus
était faux de bout en bout. La spec le disait, à un endroit où je ne l'avais pas lue.

---

# Palier v0.4 — E-reporting

## D24 — Déclarer les ventes B2C et les encaissements

- [x] Enums `TransactionCategory`, `VatPointDate`, `VatCategory` — codes et sens relevés dans le
      guide, pas devinés
- [x] Objet de valeur `Transaction` à fabriques nommées : la règle « un service exige sa date
      d'exigibilité » est portée par la signature, pas par un commentaire
- [x] `ReportingGateway` + driver Iopole + `Einvoicing::for($tenant)->reporting()`
- [x] Aucun montant recalculé — un écart est une question comptable, pas un arrondi à rattraper
- [x] 10 tests
- [x] `docs/reporting.md`
- [x] **DoD** : appel réel émis et **refusé pour un motif métier** —
      `The business entity does not have a VAT regime specified.` La chaîne fonctionne ; c'est
      l'entité de test qui n'a pas de régime de TVA.

**Limite trouvée en réel.** Le `vatRegime` peut être **envoyé** à la création d'une entité mais
n'est **jamais renvoyé** en lecture, ni en liste ni en unitaire. `doctor` ne peut donc pas prévenir :
le manque n'apparaît qu'au refus de la première déclaration. Documenté plutôt que contourné — un
diagnostic bâti sur un champ absent serait exactement l'erreur du matin.

## D25 — Corriger et consulter une déclaration

- [x] Consultation des périodes : `reports()`, objet `ReportFolder`, `isOpen()`, `wasRejected()`
- [x] `deleteTransaction()` / `deletePayment()` exposés
- [x] `setVatRegime()` — l'endpoint `configure`, sans lequel aucune déclaration n'est acceptée
- [x] 15 tests
- [x] `docs/reporting.md` complété
- [x] **DoD** : déclaration réelle **acceptée** sur l'entreprise de l'utilisateur après
      configuration du régime, période lue en retour (ouverte, clôture au 28/09/2026)

**Trois écarts entre la spec et l'API réelle.**

`PUT` **et** `DELETE` répondent `501`. Le premier était annoncé comme non implémenté ; le second
annonçait un `204`, et n'est pas implémenté non plus. Rien de déclaré ne peut donc être repris. Les
méthodes restent exposées — l'appel est juste, la plateforme ne suit pas — et la doc le dit sans
détour.

`from` est **requis** sur la consultation, et au format **`YYYY-MM`** : la spec ne disait ni l'un
ni l'autre.

Le `vatRegime` envoyé à la création d'une entité **ne prend pas**. Il faut
`POST /v1/config/business/entity/{id}/configure`, et sans lui toute déclaration est refusée. C'est
aussi le rapport, et non l'entité, qui permet de relire ce régime.

---

## D23 — Rattacher une entreprise au compte (KYB)

- [ ] `POST /v1/config/business/entity/{id}/claim`
- [ ] Suivi de l'état de la revendication

---

# La v0.2 est complète

Émettre, suivre, répondre. Éprouvé contre la sandbox réelle, pas seulement en test.

---

## Décisions en attente

- [x] **Support de Laravel 13** — tranché par les faits : les 234 tests passent sur Laravel 13 avec
      Guzzle 8 sans modification de code, et le package s'installe sur une application 13 neuve.
      Contraintes élargies, matrice CI portée à six combinaisons.
- [x] `notSeen()` : API distante ou base locale ? (impacte D14 et le README)
- [x] **Publication en `v0.1.0`, sans suffixe `beta`, avant le 1er septembre.** La question
      supposait de devoir choisir entre une partie du périmètre et l'échéance : les deux paliers
      étant terminés le 19 août, elle n'a plus lieu d'être. Reste le numéro de version, et un
      suffixe `beta` obligerait chaque utilisateur à contourner `minimum-stability` dès la première
      commande — friction absurde pour un package qui promet un raccordement en quinze minutes. Le
      `0.x` de SemVer dit déjà que l'API peut bouger ; la maturité réelle est annoncée dans le README.
- [x] Support OAuth2 `client_credentials` (CDC §8) : v0.1 ou v0.2 ?

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
| 2026-08-18 | Lot 0 | **levé** | Livraison réelle capturée, signature reproduite à l'octet près |
| 2026-08-18 | D06 | corrigé | Horodatage en millisecondes : le code rejetait 100 % des livraisons |
| 2026-08-18 | D08 | fait | Déduplication sur `X-Idempotency-Key` — 11 tests, éprouvé dans le playground |
| 2026-08-18 | D11 | fait | Job de traitement des statuts sur payload réel — 16 tests, chaîne complète vérifiée |
| 2026-08-19 | Lot 0 | **clos** | Facture entrante multipart réelle capturée, signature reproduite à l'octet près |
| 2026-08-19 | D07/D11 | corrigés | Schéma `0225` et `status.networkCode` : deux ruptures silencieuses |
| 2026-08-19 | D10 | fait | Traitement des factures entrantes sur payload réel — 8 tests, 5 livraisons rejouées |
| 2026-08-19 | D13 | fait | Métadonnées comptables et fichiers récupérés puis stockés — 7 tests |
| 2026-08-19 | D12 | fait | Les six events du CDC sont exposés et émis — palier 1 complet |
| 2026-08-19 | D14 | fait | Façade, API publique et parcours paresseux — 17 tests |
| 2026-08-19 | D15 | fait | Huit commandes Artisan — 20 tests, éprouvées contre la sandbox réelle |
| 2026-08-19 | D16 | partiel | CI, README et sept pages de doc. Reste : couverture à constater, publication |
| 2026-08-19 | Recette | **réussie** | Application neuve : installée, raccordée et facture réelle reçue complète |
| 2026-08-19 | D13 | corrigé | `GET /v1/invoice` rend une liste, et `originalFormat` vaut `FacturX` |
| 2026-08-19 | Packaging | fait | Le CDC ne part plus dans les installations ; `routes/` vide supprimé |
| 2026-08-19 | Laravel 13 | tranché | Pris en charge, vérifié sur une application neuve |
| 2026-08-19 | Couverture | **mesurée** | 97,5 % sur les points critiques, 84,5 % au global |
| 2026-08-19 | Version | tranché | `v0.1.0` sans `beta` ; maturité réelle annoncée dans le README |
| 2026-08-19 | Statuts | corrigé | Rattachement à la facture par le numéro de l'émetteur — 4/4 en réel |
| 2026-08-19 | D14 | complété | Recherche de factures sur `/v1.1/invoice/search`, vérifiée en réel |
| 2026-08-20 | D17 | fait | Lecture des entreprises et de leur joignabilité — 7 tests, 0/8 joignables en réel |
| 2026-08-20 | D17 | **corrigé** | `platformDetail` n'existe pas : joignabilité relue sur `directoryAddress` — 8/8 en réel |
| 2026-08-20 | D18 | fait | Réception bouclée : webhook rebasculé, 202 en réel, worker surveillé par `doctor` |
| 2026-08-20 | D19 | fait | Émission d'un fichier fourni — 9 tests, acceptée en réel, idempotence vérifiée |
| 2026-08-20 | D20 | fait | Cycle de vie sortant — 12 tests ; routage des statuts émis et rejeu corrigés |
| 2026-08-20 | D21 | fait | Réponses acheteur — 10 tests ; IN_HAND et REFUSED envoyés et revenus en réel |
| 2026-08-20 | D22 | fait | Déclarer et inscrire une entreprise — 9 tests ; écriture réelle non lancée |
| 2026-08-20 | D24 | fait | E-reporting B2C — 10 tests ; refus réel sur régime de TVA manquant, précondition documentée |
| 2026-08-20 | D22 | corrigé | Écriture réelle : exception après création réussie, et corps vide encodé `[]` |
| 2026-08-20 | D25 | fait | Consultation des périodes et régime de TVA — déclaration réelle acceptée ; PUT et DELETE en 501 |
| 2026-08-20 | Publication | fait | `main` et `v0.1.0` poussés ; `v0.2.0` préparé |
| 2026-08-20 | CI | corrigé | Laravel 11 retiré : branche entière bloquée par 7 avis de sécurité |
