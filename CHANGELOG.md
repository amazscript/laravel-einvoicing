# Changelog

Toutes les évolutions notables de ce package sont consignées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le versionnage
respecte [SemVer](https://semver.org/lang/fr/).

## [0.1.0] — 2026-08-19

Première version. Réception des factures électroniques françaises via une Plateforme Agréée,
driver Iopole.

### Réception

- Route de rappel enregistrée automatiquement, qui authentifie, encaisse et répond sans jamais
  traiter dans la requête.
- Vérification de signature HMAC-SHA256 : chaîne canonique, checksum du corps entier en JSON ou du
  seul contenu du champ fichier en multipart, fenêtre anti-rejeu et comparaison en temps constant.
  Validée contre l'implémentation de référence de la plateforme, puis contre ses livraisons réelles.
- Déduplication portée par la base : une livraison répétée est consignée une fois et une seule.
- Routage multi-tenant par identifiant externe, SIRET, SIREN, puis dossier unique par défaut. Une
  livraison non routable est conservée intacte plutôt que perdue.

### Traitement

- Traitement en file d'attente, avec recul exponentiel entre tentatives.
- Factures entrantes complétées auprès de la plateforme : numéro, date, montants, émetteur et
  format d'origine, que le webhook ne transporte pas.
- Téléchargement et rangement des documents sur le disque configuré, avec empreinte SHA-256 et
  re-téléchargement sans doublon.
- Statuts de cycle de vie rattachés à leur facture, dans les deux sens d'arrivée.

### Events

`InboundInvoiceReceived`, `InvoiceStatusUpdated`, `InboundInvoiceInvalid`,
`OutboundInvoiceNotDelivered`, `TenantResolutionFailed`, `WebhookSignatureRejected`.

### API publique

- Façade `Einvoicing` : factures d'un dossier, acquittement auprès de la plateforme, accès aux
  documents et pièces jointes.
- Recherche de factures et parcours des factures distantes, tous deux paresseux.
- Lecture des entreprises déclarées et de leur joignabilité : une entreprise inscrite mais
  desservie par aucune plateforme ne peut rien recevoir, et rien d'autre ne le révélait avant
  qu'une facture ne rebondisse.

### Commandes

`einvoicing:install`, `secret`, `doctor`, `poll`, `webhooks:sync`, `retry:sync`, `events:prune`
et `events:retry`. `doctor` contrôle aussi la joignabilité de chaque entreprise déclarée.

### Compatibilité

PHP 8.3 et 8.4 · Laravel 11, 12 et 13 · Guzzle 7 et 8.

### Limites connues

- **Aucun usage en production connu.** Le package a été vérifié contre une sandbox réelle, pas
  contre les flux de facturation d'une entreprise.
- L'émission de factures est hors périmètre (prévue en v0.2), de même que l'e-reporting (v0.3).
  L'onboarding est **en lecture seule** : le package constate qu'une entreprise n'est pas joignable,
  il ne l'enregistre pas.
- Un seul driver de Plateforme Agréée. L'abstraction multi-plateforme est prête mais n'a pas été
  éprouvée sur un second fournisseur.
- Les seuls formats reçus en conditions réelles sont Factur-X et PDF. UBL et CII sont pris en
  charge par la plateforme mais n'ont pas transité par le package.
