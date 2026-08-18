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
