# CDC — `amazscript/laravel-einvoicing`

**Version** 1.1 — 18 août 2026
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
| C5 | Pagination `offset`/`limit`, plafond 100 | Itérateur paresseux requis |
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
- Support alternatif OAuth2 `client_credentials` (les autres flux sont dépréciés côté Iopole).

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
- **Matrice CI** : PHP 8.3 / 8.4, Laravel 11 / 12.
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
