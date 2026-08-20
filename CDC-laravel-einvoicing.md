# CDC — `amazscript/laravel-einvoicing`

**Version** 1.12 — 20 août 2026
**Auteur** Denis Decilap / AmazScript
**Périmètre** v0.1 — Réception de factures électroniques (driver Iopole)

---

## 1. Contexte

La réforme française de la facturation électronique impose la **réception obligatoire** de factures électroniques à toutes les entreprises assujetties à la TVA au **1er septembre 2026**. L'émission suit au 1er septembre 2026 pour les GE/ETI et au **1er septembre 2027** pour les PME, TPE et micro-entreprises.

Le PPF ayant été abandonné en octobre 2024 comme plateforme d'échange, tout flux transite obligatoirement par une **Plateforme Agréée (PA)**, ex-PDP. Environ 101 PA sont immatriculées.

Toute application Laravel qui émet ou reçoit des factures B2B doit donc se raccorder à une PA.

## 2. Positionnement

| | |
|---|---|
| **Rôle réglementaire** | Opérateur de Dématérialisation (OD) |
| **Agrément DGFiP** | Non requis |
| **PA de référence v0.1** | Iopole (sandbox self-service, doc publique) |
| **Responsabilité** | Le package ne transmet ni ne certifie ; il consomme l'API d'une PA |

Le package **ne génère pas** de Factur-X, d'UBL ni de CII : la PA s'en charge. Il ne produit pas de PDF/A-3 et n'exécute aucune validation Schematron.

## 3. Objectif v0.1

Permettre à une application Laravel de **recevoir, sécuriser, router et persister** les factures fournisseurs et leurs statuts de cycle de vie, en multi-tenant, sans écrire une ligne de plomberie HTTP.

**Critère de réussite** : un dev installe, configure un token et une URL, lance une commande, et reçoit ses premières factures en moins de 15 minutes.

## 4. Périmètre

### Inclus (v0.1)

- Client HTTP authentifié (OAuth2 `client_credentials` + header `customer-id`)
- Endpoint de réception webhook (multipart + JSON)
- Vérification de signature HMAC-SHA256
- Routage multi-tenant depuis le payload
- Déduplication `eventId` / idempotence
- Modèle de statuts de cycle de vie
- Téléchargement et stockage des fichiers (XML, PDF, pièces jointes)
- Polling de repli (`notSeen` / `markAsSeen`)
- Synchronisation des souscriptions webhook et de la stratégie de retry
- Events Laravel exposés au consommateur

### Exclus (v0.1)

- Émission de factures (`POST /v1/invoice`) → v0.2
- E-reporting → v0.3
- Gestion des business entities / onboarding KYB → v0.3
- Drivers PA additionnels → v0.4
- Interface d'administration → hors package

## 5. Architecture

```
Iopole PA
   │  webhook (multipart | json)
   ▼
[ Route package ] ── raw body ──► [ VerifyIopoleSignature ]
                                          │
                                          ▼
                                  [ TenantResolver ]  ← SIREN/SIRET
                                          │
                                          ▼
                                  [ DeduplicationGuard ] ← eventId
                                          │
                                    dispatch job ──► 2xx immédiat
                                          │
                                          ▼
                          [ ProcessInboundInvoice | ProcessStatusUpdate ]
                                          │
                          ┌───────────────┼───────────────┐
                          ▼               ▼               ▼
                    persistance      Storage         Event Laravel
```

**Principe directeur** : le contrôleur ne fait qu'authentifier, dédupliquer et encaisser. Tout traitement métier passe en queue. Réponse 2xx sous 200 ms.

## 6. Contraintes issues de l'API Iopole

Ces contraintes sont **structurantes** et justifient l'existence du package.

| # | Contrainte | Conséquence |
|---|---|---|
| C1 | Un seul webhook par direction (409 sinon), configuré au niveau opérateur | Le routage vers le tenant est à la charge du package |
| C2 | Factures en `multipart/form-data`, statuts en `application/json` | Double parsing sur une même route |
| C3 | Checksum HMAC = corps entier en JSON, **contenu du champ fichier seul** en multipart | Piège majeur, source de bugs |
| C4 | Livraison **at-least-once** | Déduplication obligatoire par `eventId` |
| C5 | Pagination `offset`/`limit`, `limit` par défaut 50, plafond non documenté | Itérateur paresseux requis ; le plafond réel est à mesurer sur la sandbox |
| C6 | 429 avec backoff exponentiel recommandé | Jobs + rate limiting côté package |
| C7 | 409 `DUPLICATE_RESOURCE` | Table de mapping ID interne ↔ ID Iopole |
| C8 | Versions hétérogènes (`/v1` et `/v1.1`) | Version gérée en interne, invisible du consommateur |
| C9 | Erreurs 400 au format Zod (`path`, `code`, `message`) | Mapping vers `ValidationException` Laravel |
| C10 | Retry des webhooks piloté côté PA | Le package ne gère pas la relance entrante |

## 7. Modèle de données

### `einvoicing_tenants`

| Colonne | Type | Note |
|---|---|---|
| `id` | uuid | |
| `tenantable_type`, `tenantable_id` | morphs | rattachement au modèle hôte |
| `customer_id` | string, **chiffré** | header `customer-id` Iopole, jamais en clair en base |
| `siren` | string(9), index | clé de routage |
| `siret` | string(14), index nullable | clé de routage prioritaire |
| `active` | bool | |

### `einvoicing_inbound_invoices`

| Colonne | Type | Note |
|---|---|---|
| `id` | uuid | |
| `tenant_id` | uuid, FK **nullable** | null tant que le routage a échoué (§9) |
| `provider` | string | `iopole` |
| `provider_invoice_id` | string | contrainte composée `unique(provider, provider_invoice_id)` |
| `invoice_number` | string nullable | |
| `invoice_date` | date nullable | |
| `sender_siren`, `sender_siret`, `sender_name` | string nullable | |
| `amount_total`, `amount_tax`, `currency` | decimal / string | |
| `format` | enum | `FACTURX`, `UBL`, `CII` |
| `seen_at` | timestamp nullable | miroir de `markAsSeen` |
| `raw_metadata` | json | payload brut conservé |

### `einvoicing_invoice_files`

| Colonne | Type | Note |
|---|---|---|
| `invoice_id` | uuid, FK | |
| `provider_file_id` | string nullable | |
| `kind` | enum | `XML`, `READABLE_PDF`, `ATTACHMENT` |
| `disk`, `path` | string | Storage Laravel |
| `checksum` | string | SHA-256 |

### `einvoicing_statuses`

| Colonne | Type | Note |
|---|---|---|
| `id` | uuid | |
| `invoice_id` | uuid, FK nullable | |
| `provider` | string | `iopole` |
| `provider_status_id` | string | contrainte composée `unique(provider, provider_status_id)` |
| `code` | string | ex. `RECEIVED` |
| `value` | string | ex. `202` |
| `description` | string | |
| `dest_type` | string | ex. `OPERATOR` |
| `occurred_at` | timestamp | |
| `payload` | json | JSON + XML bruts |

### `einvoicing_webhook_events`

Table de déduplication. Rétention configurable, purge par commande.

| Colonne | Type | Note |
|---|---|---|
| `event_id` | string, **unique** | clé d'idempotence |
| `event_type` | string | |
| `tenant_id` | uuid, FK nullable | null si le routage a échoué |
| `status` | enum | `RECEIVED`, `PROCESSED`, `UNROUTED`, `FAILED` |
| `payload` | json | payload brut conservé — seule source pour rejouer un événement |
| `received_at` | timestamp | |
| `processed_at` | timestamp nullable | |
| `failed_reason` | text nullable | |

## 8. Sécurité du webhook

### Chaîne canonique

```
{X-Timestamp}\n{METHOD_MAJUSCULE}\n{path_avec_query}\n{checksum}
```

Signature = `hash_hmac('sha256', canonical, $secretKey)`, comparée à `X-Signature`.

### Calcul du checksum

| Content-Type | Source du SHA-256 |
|---|---|
| `application/json` | corps brut intégral, tel que reçu |
| `multipart/form-data` | **contenu du champ fichier uniquement** |

Signature et checksum sont encodés en **hexadécimal**.

**Vérifié le 18 août 2026 sur une requête multipart réelle** : `php://input` est vide, le SAPI
ayant déjà consommé le corps pour peupler `$_POST` et `$_FILES`. Le checksum doit donc être
calculé par `hash_file('sha256', ...)` sur le fichier temporaire uploadé. Une implémentation
appliquant littéralement « raw body » aurait haché la chaîne vide et rejeté toutes les factures.

### Exigences d'implémentation

- Capturer le **raw body avant tout parsing Laravel** (middleware prioritaire, `php://input`)
  — **valable pour `application/json` uniquement**. En `multipart/form-data`, `php://input`
  est vide : le SAPI PHP a déjà consommé le corps pour peupler `$_POST` / `$_FILES`. Le
  checksum doit alors être calculé sur le fichier temporaire uploadé
  (`hash_file('sha256', ...)`), ce qui correspond exactement à la règle « contenu du champ
  fichier uniquement ».
- Comparaison en **temps constant** (`hash_equals`).
- Rejeter si `X-Timestamp` dévie de plus de N secondes (défaut : 300) — anti-rejeu.
- Vérifier `X-Checksum` quand présent, avant la signature.
- Secret ≥ 32 octets aléatoires, généré par commande dédiée, jamais en clair dans le dépôt.
- Authentification OAuth2 `client_credentials` : c'est la **seule** méthode documentée. Aucun jeton permanent n'est délivré ; l'`access_token` obtenu est à durée de vie courte et doit être renouvelé.

## 9. Routage multi-tenant

Contrainte C1 : un `callbackUrl` unique pour tout le parc.

**Stratégie de résolution**, dans l'ordre :

1. `idPath` configuré côté Iopole pointant vers un identifiant externe du package
2. SIRET du destinataire (`recipients[].siret` pour un statut, métadonnées pour une facture)
3. SIREN du destinataire
4. Fallback : tenant par défaut si un seul est actif

**Échec de résolution** → l'événement est persisté avec `tenant_id = null`, statut `UNROUTED`, et un event `TenantResolutionFailed` est émis. Aucune perte de donnée, aucune erreur 5xx renvoyée à la PA.

Le résolveur est **remplaçable** via le container (`EinvoicingTenantResolver` interface).

## 10. API publique du package

```php
// Réception — piloté par events, pas d'appel manuel

// Consultation
Einvoicing::for($tenant)->invoices()->notSeen();
Einvoicing::for($tenant)->invoice($id)->markAsSeen();
Einvoicing::for($tenant)->invoice($id)->xml();
Einvoicing::for($tenant)->invoice($id)->readablePdf();
Einvoicing::for($tenant)->invoice($id)->attachments()->store('s3');

// Annuaire
Einvoicing::directory()->search('IOPOLE');

// Recherche paginée paresseuse (C5)
Einvoicing::for($tenant)->invoices()->search([...])->lazy();
```

### Events Laravel exposés

| Event | Déclencheur |
|---|---|
| `InboundInvoiceReceived` | facture entrante traitée et stockée |
| `InvoiceStatusUpdated` | statut de cycle de vie reçu |
| `InboundInvoiceInvalid` | événement `INVOICE_INBOUND_INVALID` |
| `OutboundInvoiceNotDelivered` | échec de remise (préparation v0.2) |
| `TenantResolutionFailed` | routage impossible |
| `WebhookSignatureRejected` | signature invalide — à monitorer |

### Exceptions

`EinvoicingValidationException` (400 Zod, avec `path` et `code`), `EinvoicingAuthException` (401/403), `EinvoicingConflictException` (409), `EinvoicingRateLimitException` (429), `EinvoicingServerException` (5xx).

## 11. Commandes Artisan

| Commande | Rôle |
|---|---|
| `einvoicing:install` | publication config + migrations |
| `einvoicing:secret` | génération d'un secret HMAC |
| `einvoicing:webhooks:sync` | réconciliation config locale ↔ `GET /v1/config/webhook` |
| `einvoicing:retry:sync` | application de la stratégie de retry |
| `einvoicing:poll {--tenant=}` | repli sur `notSeen` |
| `einvoicing:events:prune` | purge de la table de déduplication |
| `einvoicing:events:retry` | rejeu des événements `FAILED` et `UNROUTED` |
| `einvoicing:doctor` | diagnostic : token, customer-id, webhooks, accessibilité de l'URL |

`einvoicing:doctor` est un différenciateur produit : il répond à 80 % des tickets de support avant qu'ils ne soient ouverts.

## 12. Configuration

```php
return [
    'default' => env('EINVOICING_DRIVER', 'iopole'),

    'drivers' => [
        'iopole' => [
            'base_url'      => env('IOPOLE_BASE_URL', 'https://api.ppd.iopole.fr'),
            'token_url'     => env('IOPOLE_TOKEN_URL'),
            'client_id'     => env('IOPOLE_CLIENT_ID'),
            'client_secret' => env('IOPOLE_CLIENT_SECRET'),
            'customer_id'   => env('IOPOLE_CUSTOMER_ID'),
        ],
    ],

    'webhook' => [
        'path'            => 'einvoicing/webhook',
        'middleware'      => ['api'],
        'secret'          => env('EINVOICING_WEBHOOK_SECRET'),
        'canonical_path'  => env('EINVOICING_WEBHOOK_CANONICAL_PATH'),
        'tolerance'       => 300,
        'direction'       => 'INBOUND',
    ],

    'storage' => [
        'disk' => env('EINVOICING_DISK', 'local'),
        'path' => 'einvoicing',
    ],

    'queue' => [
        'connection' => env('EINVOICING_QUEUE_CONNECTION'),
        'name'       => 'einvoicing',
    ],

    'tenant_resolver' => \AmazScript\Einvoicing\Tenancy\SiretResolver::class,
];
```

## 13. Tests

- **Unitaires** : chaîne canonique, checksum JSON vs multipart, tolérance de timestamp, résolution de tenant sur les 4 stratégies.
- **Fonctionnels** : rejeu du même `eventId` → un seul traitement ; signature invalide → 401 + event ; multipart avec champs annexes → signature valide.
- **Contract tests** : fixtures figées issues de la doc Iopole (payload statut, payload onboarding, `INVOICE_INBOUND_INVALID`).
- **Matrice CI** : PHP 8.3 / 8.4, Laravel 11 / 12 / 13.
- **Cible** : couverture ≥ 85 % sur les namespaces `Webhook` et `Tenancy`.

## 14. Charge estimée

| Lot | Jours |
|---|---|
| Client HTTP, auth, tenant | 2 |
| Endpoint webhook, raw body, HMAC | 3 |
| Routage tenant | 2 |
| Déduplication / idempotence | 2 |
| Statuts + events | 3 |
| Poll de repli, fichiers, Storage | 3 |
| Commandes de sync + doctor | 2 |
| Tests, doc, CI | 3 |
| **Total v0.1** | **20 j** |

## 15. Distribution et modèle économique

**v0.1 : gratuit, MIT, sur Packagist.** Le package est un canal d'acquisition, pas une source de revenu. Objectif : visibilité, crédibilité technique, prospects qualifiés.

Monétisation, par ordre de priorité :

1. **Missions d'intégration** — 3 à 8 k€ par client. Revenu immédiat.
2. **Revente PA en marque grise** — tarif de gros refacturé, revenu récurrent.
3. **Édition Pro** — drivers multi-PA, émission avancée, tableau de bord. Licence perpétuelle + 12 mois de mises à jour, renouvellement ~40 %. Distribution via Anystack (dépôt Composer privé, auth http-basic par clé de licence).

L'édition Pro n'est engagée **qu'après** des demandes utilisateurs explicites pour un second driver.

## 16. Roadmap

| Version | Contenu | Déclencheur |
|---|---|---|
| **v0.1** | Réception, driver Iopole | Échéance 1er sept. 2026 |
| **v0.2** | Émission (`POST /v1/invoice`), builder de facture | Demande utilisateur |
| **v0.3** | E-reporting, business entities, onboarding | T4 2026 |
| **v0.4** | Second driver PA, abstraction validée | ≥ 3 demandes explicites |
| **v1.0** | Stabilisation avant le pic PME/TPE | T1 2027 |

## 17. Risques

| Risque | Gravité | Mitigation |
|---|---|---|
| Iopole publie son propre SDK PHP | Élevée | Avance temporelle ; l'intégration Laravel native (queue, events, tenancy) reste hors périmètre d'un SDK générique |
| Marché des devs Laravel FR trop étroit | Élevée | Le package n'est pas le revenu ; la presta l'est |
| Évolution des spécifications DGFiP | Moyenne | Versionnage sémantique strict, fenêtre de mises à jour sur l'édition Pro |
| Rupture d'API Iopole (`/v1` → `/v1.1`) | Moyenne | Version encapsulée, contract tests sur fixtures |
| Erreur de conformité imputée au package | Moyenne | Licence MIT sans garantie ; le package ne certifie rien, la PA seule est agréée |
| Dérive vers le Pro avant validation | Élevée | Règle explicite : pas de code payant avant 3 demandes utilisateurs |

## 18. Première étape

Avant toute ligne de code du package :

1. Créer la sandbox sur `labs.iopole.io`
2. Configurer un webhook INBOUND vers un tunnel local
3. Émettre une facture de test depuis l'interface
4. **Vérifier la signature HMAC à la main, en PHP, sur le multipart réel**

Le point 4 est le seul risque technique non levé du projet. Une demi-journée. S'il échoue, le CDC est à revoir avant d'engager les 20 jours.

---

## 19. Journal des révisions

### v1.1 — 18 août 2026

Corrections issues de la relecture d'avant-développement. Aucune ne change le périmètre ;
toutes portent sur des points qui auraient coûté une migration en production.

| § | Correction | Motif |
|---|---|---|
| 7 | `einvoicing_webhook_events` : ajout de `tenant_id`, `status`, `payload` | Le §9 impose de persister un événement non routé en `UNROUTED` sans perte. Sans ces colonnes, il n'existait aucun endroit où le stocker ni de quoi le rejouer. |
| 7 | `einvoicing_inbound_invoices.tenant_id` passe en nullable | Même motif : une facture non routée doit pouvoir être persistée. |
| 7 | `einvoicing_statuses` : ajout de `provider`, contrainte `unique(provider, provider_status_id)` | Une contrainte globale entrerait en collision dès l'ajout d'un second driver (v0.4). |
| 7 | `einvoicing_inbound_invoices` : contrainte `unique(provider, provider_invoice_id)` explicitée | La formulation « unique(provider) » était ambiguë ; la règle d'idempotence porte sur le couple. |
| 7 | `einvoicing_tenants.customer_id` chiffré | Le `customer-id` ne doit jamais être loggé ; le stocker en clair était incohérent. |
| 8 | Précision sur `php://input` en multipart | La consigne d'origine, appliquée telle quelle, ne peut pas fonctionner en multipart. |
| 11 | Ajout de la commande `einvoicing:events:retry` | Sans elle, un job mort en échec définitif rend invisible tout retry ultérieur de la plateforme : la facture est perdue. |
| 12 | Ajout de `webhook.canonical_path` | Un proxy ou un tunnel peut réécrire l'URI et invalider la chaîne canonique. |
| 4, 12 | `token` remplacé par `token_url`, `client_id`, `client_secret` | La documentation ne prévoit aucun jeton permanent : l'authentification est OAuth2 `client_credentials`, avec un `access_token` à durée de vie courte. |

Point signalé mais **non tranché** : le CDC vise Laravel 11 et 12 (§13). Laravel 13 est
désormais la version installée par défaut par `composer create-project`, avec Guzzle 8. En
l'état, le package ne s'installe pas sur une application Laravel neuve.

### v1.2 — 18 août 2026

Écarts relevés en confrontant le CDC à la documentation publiée sur `docs.iopole.com`.

| § | Correction | Motif |
|---|---|---|
| 4, 8, 12 | L'authentification est OAuth2 `client_credentials`, et non un jeton permanent | Aucun bearer statique n'est documenté. Impact sur la configuration et sur la charge du lot « client HTTP ». |
| 6 (C5) | `limit` vaut 50 par défaut ; le plafond de 100 n'est pas documenté | Affirmation non vérifiable en l'état. À mesurer sur la sandbox avant d'écrire l'itérateur. |
| 10 | L'annuaire français est `/v1/directory/french` | Le chemin `/v1/directory` employé dans les exemples de pagination de leur doc ne correspond pas à la spécification d'endpoint. |

**Point ouvert, non tranché.** `GET /v1/invoice/notSeen` n'expose aucun paramètre de requête dans
la spécification : ni `offset`, ni `limit`. Si l'endpoint ne pagine effectivement pas, le repli par
polling (§4) ne peut pas s'appuyer sur un itérateur paresseux — il faut avancer en marquant les
factures comme vues. À vérifier sur la sandbox avant d'engager le lot correspondant.

**Environnements.** Le CDC pointe `api.ppd.iopole.fr` ; la page de présentation du Lab annonce
`api.iopole.com/v1/api`. Vraisemblablement pré-production et production. La valeur exacte de
`IOPOLE_BASE_URL` sera confirmée par la sandbox.

### v1.3 — 18 août 2026

Premiers constats sur la sandbox réelle, environnement de pré-production.

| Constat | Conséquence |
|---|---|
| L'authentification OAuth2 `client_credentials` fonctionne sur `auth.preprod.iopole.fr` | La v1.2 est confirmée sur le terrain. L'`access_token` fait environ 1,5 ko. |
| `GET /v1/config/customer/id` répond en **`text/html`** avec l'identifiant nu | La spécification annonce `application/json`. Le client doit pouvoir lire une réponse non structurée ; tout supposer JSON casserait sur cet endpoint. |
| `php://input` mesuré à **0 octet** sur une requête multipart | Confirme §8. Le checksum se calcule sur le fichier temporaire uploadé. |
| Les listes paginées renvoient `{"data": [...], "meta": {"offset", "limit", "count"}}` | L'itérateur paresseux (C5) s'appuie sur `meta.count`, et non sur la taille de la page. |
| `GET /v1/invoice/notSeen` renvoie un **tableau nu**, sans enveloppe ni pagination | Confirme le point ouvert de la v1.2 : le repli par polling ne peut pas paginer. Il faut consommer la liste puis avancer par `markAsSeen`, sinon les mêmes factures reviennent indéfiniment. |
| Le secret HMAC est **fourni par l'intégrateur** dans `interopData.endpoints.authentication.hmac.secretKey` | La plateforme ne le génère pas : `einvoicing:secret` doit produire le secret **avant** la déclaration du webhook. |
| `idPath` existe bel et bien sur les endpoints `invoice` et `status` | La première stratégie de résolution multi-tenant (§9) est disponible. |

Enseignement : la documentation annonce des types de contenu qui ne correspondent pas toujours à
la réalité. Chaque endpoint doit être observé avant d'être considéré comme acquis.

### v1.4 — 18 août 2026

Constats issus d'une **livraison réellement émise par la plateforme**, capturée sur un tunnel.
Ces points ne figurent dans aucune page de sa documentation.

| Constat | Conséquence |
|---|---|
| `X-Timestamp` est exprimé en **millisecondes** (13 chiffres) | Comparé tel quel à `time()`, l'écart se compte en milliers d'années : **toute livraison authentique aurait été rejetée**. L'unité n'étant pas contractuelle, secondes et millisecondes sont désormais toutes deux acceptées. |
| L'en-tête d'idempotence s'appelle **`X-Idempotency-Key`** et porte un UUID | Répond au point resté ouvert depuis la v1.2 : la clé de déduplication du §7 est cet en-tête, et non un champ du payload. Le payload de statut ne contient effectivement aucun `eventId`. |
| Un en-tête **`X-Target-Electronic-Address`** porte `scheme:valeur` du destinataire | Le routage multi-tenant (§9) peut s'appuyer sur un en-tête plutôt que sur un parcours du payload. Le schéma `0002` désigne un SIREN. |
| La chaîne canonique est signée sur le **chemin seul**, sans le domaine | Confirme §8 : `path_with_query` et non l'URL complète. Vérifié en reproduisant la signature à l'octet près. |
| Un statut porte `invoiceId` et `statusId`, jamais d'`eventId` | La clé d'unicité d'un statut est `statusId`, celle d'une facture `invoiceId`. |
| En `OUTBOUND`, `interopData.endpoints` n'accepte que `status` et `authentication` | Contrainte de configuration à respecter par `einvoicing:webhooks:sync` (§11). |

**Le risque technique du §18 est levé.** L'algorithme de signature du package reproduit exactement
celui de la plateforme, vérifié sur une signature qu'elle a réellement émise.

### v1.5 — 19 août 2026

Constats issus de **cinq livraisons réelles** déclenchées depuis le simulateur du Lab, dont une
facture entrante en multipart. Chacun contredisait la documentation, et chacun cassait en silence.

| Constat | Conséquence |
|---|---|
| Le checksum multipart porte bien sur le **contenu du fichier seul** | §8 vérifié sur une livraison authentique : la signature est reproduite à l'octet près, les champs annexes exclus du calcul. Le risque majeur du projet est levé pour de bon. |
| Les destinataires sont adressés en **`0225:<siren>`** | Ni `0002` ni `0009` comme supposé. Le schéma `0225` désigne les adresses électroniques françaises et porte un SIREN ou un SIRET, distingués par leur longueur. |
| Le code réseau arrive sous **`status.networkCode`**, pas `status.value` | Lu sous le nom documenté, il valait toujours null — et la contrainte de la base rejetait alors le statut entier. |
| Une facture entrante arrive en **PDF**, avec les champs `invoiceId` et `senderAcceptStatus` | Le webhook ne transporte ni numéro, ni date, ni montant, ni émetteur : ces métadonnées se récupèrent auprès de l'API. |
| Le cycle de vie observé est `SUBMITTED → ISSUED → RECEIVED → MADE_AVAILABLE` | Codes réels, à documenter pour le consommateur. |
| L'`invoiceId` d'un statut **diffère** de celui de la facture entrante | Le même document porte un identifiant distinct de chaque côté de la chaîne. La clé de rapprochement a été trouvée depuis : voir v1.9. |
| `roleCode` est tantôt une chaîne, tantôt un objet | Toute lecture de ce champ doit accepter les deux formes. |
| En `OUTBOUND`, `interopData.endpoints` refuse la clé `invoice` | Seuls `status` et `authentication` sont admis. |

### v1.6 — 19 août 2026

Constats de la recette finale, menée sur une application Laravel neuve.

| Constat | Conséquence |
|---|---|
| `GET /v1/invoice/{id}` rend une **liste**, pas un objet | La lecture des métadonnées échouait en silence : la facture arrivait sans numéro, sans montant et sans émetteur. |
| `originalFormat` vaut **`FacturX`**, non `FACTURX` | La conversion en énumération échouait sur la casse, et le format était perdu. |

**Critère de réussite du §3 atteint.** Une application neuve installe le package, se raccorde et
reçoit une facture réelle complète — fournisseur, numéro, montant, devise, date, format et document —
en quelques minutes.

### v1.7 — 19 août 2026

**Laravel 13 est pris en charge.** La question restée ouverte depuis la v1.2 est tranchée par les
faits : les 234 tests passent sur Laravel 13 avec Guzzle 8 sans qu'une ligne de code ait changé, et
le package s'installe et fonctionne sur une application 13 neuve.

Ne pas le faire aurait laissé le package **ininstallable sur une application Laravel neuve**, puisque
`composer create-project laravel/laravel` sert désormais la 13. Les contraintes passent donc à
`illuminate/* ^11|^12|^13` et `guzzlehttp/guzzle ^7.8|^8.0`, et la matrice d'intégration continue
couvre les trois versions.

### v1.8 — 19 août 2026

**Publication en `v0.1.0`, sans suffixe de pré-version.**

Le §16 prévoyait une v0.1 pour l'échéance du 1er septembre : elle est prête le 19 août, périmètre
complet. La publication en `beta` a été écartée pour une raison pratique — Composer refuse par défaut
les versions instables, si bien qu'un utilisateur devrait ajuster son `minimum-stability` dès la
première commande, à rebours du critère des quinze minutes du §3.

Le `0.x` de SemVer signale déjà qu'une API peut évoluer. Ce que ce numéro ne dit pas, le README le
dit : le package n'a **aucun usage en production connu**, ayant été vérifié contre une sandbox et non
contre des flux d'entreprise. Cette limite est annoncée plutôt que tue, conformément au §9 — une
limite connue énoncée d'avance vaut mieux qu'une mauvaise surprise.

### v1.9 — 19 août 2026

**Le rapprochement statut ↔ facture reçue est résolu.**

Le point restait ouvert depuis la v1.5 : les identifiants techniques diffèrent de chaque côté de la
chaîne, celui du statut désignant la facture émise. La référence commune est le **numéro que
l'émetteur a attribué à sa facture** :

| | Dans un statut | Dans la facture reçue |
|---|---|---|
| numéro | `json.responses[].documentReference.issuerAssignedId` | `businessData.invoiceId` |
| émetteur | `documentReference.issuer.siren` | `seller.siren` |

Les deux critères sont exigés **ensemble** : deux fournisseurs peuvent employer la même
numérotation, et un rapprochement sur le seul numéro mélangerait leurs statuts. Conformément au
principe du §9, mieux vaut ne rien rattacher que rattacher à tort.

Le rapprochement joue dans les deux sens : un statut arrivant après sa facture la trouve, et une
facture arrivant après ses statuts les raccroche. C'est le cas courant, les statuts de cycle de vie
précédant généralement la livraison de la facture.

Vérifié sur les livraisons réelles : les quatre statuts du cycle observé sont rattachés à la facture.

### v1.10 — 19 août 2026

**La recherche de factures du §10 est réalisable, contrairement à ce qui avait été conclu.**

Une première lecture de la spécification n'avait relevé aucun endpoint de recherche, et la
fonctionnalité avait été déclarée impossible. L'erreur venait de l'extraction : le motif employé
excluait le point dans « v1.1 », si bien que les endpoints de cette version passaient à la trappe.

`GET /v1.1/invoice/search` existe, avec pagination et une syntaxe de filtres :

```
invoice.direction:"INBOUND" AND invoice.state:"NOT_DELIVERED"
```

C'est l'illustration exacte de la contrainte C6 (§6, C8) : deux versions cohabitent, et la classe
`Endpoints` est là pour que le consommateur ne le sache jamais.

Chaque résultat porte un objet `metadata` (`invoiceId`, `state`, `direction`, `createDate`). Le
paramètre `expand` accepte `businessData` et `lastStatusData`, ce qui évite un appel par facture.

Les valeurs assemblées en requête voient leurs guillemets **retirés**, non échappés : la syntaxe
d'échappement du moteur n'est pas documentée, et un échappement mal interprété laisserait une valeur
extérieure réécrire le sens de la recherche.

### v1.11 — 20 août 2026

Deuxième série de livraisons réelles, sur des entreprises et des factures que le package n'avait
jamais vues : douze livraisons, douze traitées, aucun échec.

| Constat | Conséquence |
|---|---|
| Le cycle de vie comporte une cinquième étape, **`IN_HAND`** | La liste relevée en v1.5 était incomplète. Confirme le choix de ne pas modéliser ces codes en énumération : une liste figée aurait rejeté un statut authentique. |
| Le rapprochement statut ↔ facture tient sur plusieurs dossiers | Dix statuts répartis sur deux factures et quatre entreprises, tous rattachés correctement. |

Enseignement général : chaque nouvelle série de livraisons révèle un cas de plus. Ce qui n'a été vu
qu'une fois ne doit pas être traité comme une règle.

### v1.12 — 20 août 2026

Lecture des entreprises et de leur joignabilité (D17), livrée fausse puis corrigée le même jour.

| Constat | Conséquence |
|---|---|
| **Une entreprise porte deux adresses distinctes.** L'identifiant légal (`0002:449290493`, le SIREN) dit qui elle est ; l'adresse d'annuaire (`0225:449290493`) dit où elle reçoit. | Seule la seconde route une facture, et un rejet `No route found for given key` ne cite jamais que celle-là. Les confondre fait afficher une adresse qui n'existe pour personne. |
| La joignabilité se lit dans `identifiers[].networkRegistered[]` : `directoryId`, `networkId`, `directoryAddress`, `networkIdentifier`, `isSelfBilling`, `validFrom`. | Il n'y a **ni statut, ni plateforme desservante** sur une inscription. Le champ `platformDetail` n'existe pas ici — il appartient à `/v1/directory/french?withPlatformDetails=true`, une autre ressource. |
| `validFrom` peut être postérieur à aujourd'hui. | Une inscription déposée n'est pas une inscription en vigueur : une facture émise avant sa date d'effet rebondit. |
| `GET /v1/config/business/entity/{id}` répond par une **liste** d'un élément. | Même piège que `/v1/invoice/{id}` (v1.10). À considérer comme le comportement par défaut des endpoints unitaires, non comme une exception. |
| La recherche `q` attend la syntaxe `champ:"valeur"`, jointe par ` AND `. | Identique à `/v1.1/invoice/search`. Toute autre forme renvoie `Unable to parse correctly query search`. |

**Ce qui a échoué, et pourquoi.** La première version reposait sur un `platformDetail` supposé, et
ses fixtures avaient été rédigées à partir de cette supposition. Les tests passaient donc au vert
sur une forme d'API inexistante, et le diagnostic déclarait huit entreprises sur huit injoignables
alors que deux venaient de recevoir une facture. Ni les tests, ni PHPStan, ni la relecture n'ont pu
le voir : seule la contradiction avec les données affichées à l'écran l'a révélé.

Enseignement général : une fixture rédigée ne teste rien, elle confirme l'hypothèse de départ. Elle
se copie d'une réponse réelle. Et une conclusion qui contredit un fait observé est fausse avant que
le fait ne le soit.
