# Changelog

Toutes les évolutions notables de ce package sont consignées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le versionnage
respecte [SemVer](https://semver.org/lang/fr/).

## [Non publié]

### Ajouté

- Squelette du package : service provider, configuration publiable, harnais de test
  (Pest + Testbench), Pint et PHPStan niveau 8.
- Modèle de données : cinq migrations (`tenants`, `inbound_invoices`, `invoice_files`,
  `statuses`, `webhook_events`), les modèles Eloquent associés et trois énumérations
  (`InvoiceFormat`, `InvoiceFileKind`, `WebhookEventStatus`).
- Contraintes d'intégrité portées par la base : unicité `(provider, provider_invoice_id)`,
  `(provider, provider_status_id)` et `event_id`.
- Chiffrement du `customer_id` au repos.
- Client HTTP de la plateforme Iopole : authentification OAuth2 `client_credentials` avec mise
  en cache et renouvellement du jeton, en-tête `customer-id` par tenant, nouvelle tentative
  unique sur 401.
- Traduction des erreurs de l'API en exceptions typées : validation (400, format Zod),
  authentification (401/403), conflit (409, avec détection de `DUPLICATE_RESOURCE`),
  quota (429, avec `Retry-After`) et panne serveur (5xx).
- Chemins d'API encapsulés dans `Endpoints`, vérifiés par un test de contrat contre la
  spécification publiée.
- Routage multi-tenant : contrat `TenantResolver`, implémentation `SiretResolver` résolvant par
  identifiant externe, SIRET, SIREN puis tenant unique par défaut, et événement
  `TenantResolutionFailed` en cas d'échec.
- Vérification de signature HMAC-SHA256 des webhooks entrants : chaîne canonique, checksum,
  fenêtre anti-rejeu et comparaison en temps constant, validée contre des vecteurs produits par
  l'implémentation de référence de la plateforme.
- Route de rappel du webhook, enregistrée automatiquement : authentifie la requête, répond 202,
  et rejette en 401 toute signature invalide sans rien persister.

- Déduplication des livraisons : chaque webhook authentifié est consigné une fois et une seule,
  routé vers son tenant, ou conservé en `UNROUTED` si le destinataire est inconnu.
- Contrat `PayloadInterpreter` : les conventions d'en-têtes et de payload restent confinées au
  driver de la plateforme.

- Traitement des statuts de cycle de vie en file d'attente : `ProcessStatusUpdate`, mise en file
  après validation de la transaction, recul exponentiel entre tentatives, et événement
  `InvoiceStatusUpdated` à destination de l'application.
- Un événement dont le destinataire est inconnu n'est pas traité : il reste consigné en `UNROUTED`,
  rejouable une fois le tenant créé.

### Corrigé

- La colonne `value` d'un statut est nullable : la plateforme n'envoie parfois que le code, là
  où sa documentation montre systématiquement un trio code/valeur/description.
- L'horodatage des webhooks est transmis en millisecondes par la plateforme. Comparé tel quel à
  l'horloge locale, il faisait rejeter toute livraison authentique. Les deux unités sont
  désormais acceptées.
