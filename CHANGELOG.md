# Changelog

Toutes les évolutions notables de ce package sont consignées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le versionnage
respecte [SemVer](https://semver.org/lang/fr/).

## [0.1.0] — non publiée

Première version : réception des factures électroniques via une Plateforme Agréée.

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

- Traitement des factures entrantes : `ProcessInboundInvoice`, rattachement des statuts arrivés
  avant leur facture, et événement `InboundInvoiceReceived`.

- Complétion des factures : numéro, date, montants, émetteur et format d'origine sont récupérés
  auprès de la plateforme, le webhook ne les transportant pas.
- Téléchargement et stockage des fichiers d'une facture sur le disque Laravel configuré, avec
  empreinte SHA-256 et re-téléchargement sans doublon.

- Événement `InboundInvoiceInvalid` lorsqu'une facture entrante est refusée par la plateforme,
  avec le détail des erreurs de validation.
- Événement `OutboundInvoiceNotDelivered` lorsqu'un statut de rejet signale qu'une facture n'a pas
  atteint son destinataire.

- Recherche de factures auprès de la plateforme, parcourue paresseusement, avec assemblage des
  critères et enrichissement optionnel des résultats.
- API publique et façade `Einvoicing` : consultation des factures d'un dossier, acquittement
  auprès de la plateforme, accès aux documents et recherche paresseuse dans l'annuaire.

- Huit commandes Artisan : `install`, `secret`, `doctor`, `poll`, `webhooks:sync`, `retry:sync`,
  `events:prune` et `events:retry`.

### Corrigé

- Les statuts de cycle de vie sont désormais rattachés à la facture reçue, dans les deux sens
  d'arrivée. Le rapprochement s'appuie sur le numéro attribué par l'émetteur et son SIREN, les
  identifiants techniques différant de chaque côté de la chaîne.

- Les destinataires adressés en `0225:<siren>` sont désormais reconnus : le schéma employé par
  la plateforme n'était pas pris en charge, et l'en-tête de routage était ignoré.
- Le code réseau d'un statut est lu sous `networkCode`, nom réellement employé.
- La colonne `value` d'un statut est nullable : la plateforme n'envoie parfois que le code, là
  où sa documentation montre systématiquement un trio code/valeur/description.
- L'horodatage des webhooks est transmis en millisecondes par la plateforme. Comparé tel quel à
  l'horloge locale, il faisait rejeter toute livraison authentique. Les deux unités sont
  désormais acceptées.

### Compatibilité

- Laravel 11, 12 et 13 ; Guzzle 7 et 8 ; PHP 8.3 et 8.4.

### Documentation

- README, et sept pages d'usage : installation, configuration, webhooks, multi-tenant, events,
  commandes et dépannage.
- Intégration continue : tests sur PHP 8.3 et 8.4 croisés avec Laravel 11 et 12, analyse statique,
  style, et couverture avec un seuil relevé sur les points critiques.
