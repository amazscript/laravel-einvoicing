# Changelog

Toutes les évolutions notables de ce package sont consignées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le versionnage
respecte [SemVer](https://semver.org/lang/fr/).

## [0.3.0] — 2026-08-20

### Compatibilité

- **Laravel 11 n'est plus pris en charge.** La totalité de la branche (`v11.0.0` → `v11.56.0`) est
  frappée d'avis de sécurité, et Composer refuse d'installer ces versions. Continuer à l'annoncer
  reviendrait à orienter des projets vers une base vulnérable, ou à leur demander de désactiver une
  protection. Reste **Laravel 12 et 13**.
- Les actions GitHub passent en `checkout@v5` : la v4 tournait sur Node 20, déprécié.

## [0.2.0] — 2026-08-20

Le package émet, suit et répond. La v0.1 ne faisait que recevoir.

Tout ce qui suit a été éprouvé contre la sandbox réelle de la plateforme, pas seulement en test.

### Émission

- `Einvoicing::for($tenant)->send($chemin)` remet à la plateforme un document PDF ou XML **produit
  par l'application** : le package n'a jamais fabriqué de format, et ne commence pas.
- Envoyer deux fois le même fichier n'émet qu'une facture. L'endpoint n'offrant aucune clé
  d'idempotence, une contrainte d'unicité sur `(dossier, empreinte du fichier)` la remplace — un
  renvoi après un timeout ne facturera pas le client deux fois.
- Un refus est conservé avec sa raison plutôt qu'effacé.
- Events `OutboundInvoiceSent` et `OutboundInvoiceFailed`.
- Suivi du cycle de vie des factures émises : `Einvoicing::for($tenant)->sent()` distingue ce qui a
  été refusé, ce qui est parti sans nouvelle, et ce que la plateforme dit qu'elle ne livrera pas.
- Les statuts de factures émises sont routés par l'identifiant de la facture : ils nomment le client
  comme destinataire, jamais l'émetteur, et le routage multi-tenant n'y trouvait rien.

### Réponses de l'acheteur

- `approve()`, `refuse($motif)`, `acknowledge()`, `dispute()`, `reportPayment()` sur une facture
  reçue, et `answer()` pour les neuf codes acheteur.
- Un refus sans motif et un paiement sans montant sont refusés **avant** l'appel réseau : la
  plateforme les rejetterait, et « refusée » tout court n'apprend rien au fournisseur.
- 28 motifs de refus normatifs (`RejectionReason`), une chaîne restant acceptée si la liste évolue.

### Onboarding

- `Einvoicing::entities()->declareLegalUnit()` et `->register()` : déclarer une entreprise puis
  publier son adresse à l'annuaire. Deux gestes distincts — le premier la fait connaître, seul le
  second la rend joignable, et l'écart entre les deux est ce qui fait rebondir une facture.
- Le SIREN est vérifié avant l'appel plutôt qu'après le refus.
- Une création dont la plateforme ne renvoie pas l'identifiant n'est plus signalée comme un échec :
  l'entité est bien créée, et croire l'inverse mène à un retry qui la duplique.
- L'inscription n'envoie jamais un corps vide : un tableau PHP vide s'encode `[]` là où l'endpoint
  attend `{}`, et l'inscription échouait alors sans rien dire.

### E-reporting

- `Einvoicing::for($tenant)->reporting()` déclare les ventes B2C et les encaissements. Tout est en
  JSON : le package porte l'échange entier, sans dépendre d'un format produit ailleurs.
- Quatre fabriques de transaction — biens, services, hors champ, mixte — qui portent les règles de
  la plateforme : une prestation de service **exige** sa date d'exigibilité de TVA, et la signature
  la réclame plutôt que de laisser la plateforme refuser.
- Aucun montant n'est recalculé : ce qui est déclaré est ce que l'application dit avoir encaissé.
- Prérequis relevé en réel : sans régime de TVA sur l'entité, la plateforme refuse. Ce régime
  n'étant jamais renvoyé en lecture, le manque ne se découvre qu'au premier refus.

### E-reporting — consultation

- `reports()` lit les périodes de déclaration : ouverte ou close, acceptée ou refusée, et la date de
  clôture automatique au-delà de laquelle plus rien n'y entre. Le mois de départ est obligatoire et
  les bornes sont des mois, pas des jours.
- `setVatRegime()` : le régime envoyé à la création d'une entité ne prend pas, et sans lui la
  plateforme refuse toute déclaration. C'est aussi le rapport, et non l'entité, qui permet de le
  relire ensuite.
- `deleteTransaction()` / `deletePayment()` sont exposées mais **la plateforme répond 501** : rien
  de déclaré ne peut aujourd'hui être repris ni corrigé. À considérer comme définitif.

### Corrigé

- Un statut de facture émise nomme le client comme destinataire, jamais l'émetteur : le routage
  multi-tenant n'y trouvait rien et le premier statut réel est resté `UNROUTED`. Il est désormais
  rattaché par l'identifiant de la facture.
- `einvoicing:doctor` détecte un worker absent ou lancé sur la mauvaise file — la panne la plus
  silencieuse : la route répond 202, les livraisons s'enregistrent, et rien n'est traité.
- Une requête d'upload n'hérite plus du `Content-Type: application/json` posé par `asJson()`, qui
  survivait à `asMultipart()` et faisait partir le corps sous une fausse étiquette.
- `einvoicing:webhooks:sync` n'annonce plus une option `--apply` qui n'était branchée sur rien.
- `einvoicing:events:retry` **refait** le routage au lieu de relire un `tenant_id` resté nul :
  jusqu'ici un événement `UNROUTED` ne pouvait être récupéré par aucun moyen, ce qui vidait la
  commande de son objet.

### Limites connues

- **Aucune facture au format Factur-X valide n'a été émise.** Le transport est prouvé de bout en
  bout ; la validation du format par la plateforme ne l'est pas.
- Une déclaration d'e-reporting ne peut être ni corrigée ni retirée : la plateforme répond `501`
  sur ses propres endpoints de mise à jour et de suppression.
- Le rattachement d'une entreprise à un compte (KYB) reste hors périmètre.
- Toujours aucun usage en production connu.

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
- Lecture des entreprises déclarées et de leur joignabilité : une entreprise absente de l'annuaire,
  ou dont l'inscription n'a pas encore pris effet, ne peut rien recevoir, et rien d'autre ne le
  révélait avant qu'une facture ne rebondisse. L'adresse qui route (`0225:…`) est distinguée de
  l'identifiant légal (`0002:…`), que le rejet ne cite jamais.

### Commandes

`einvoicing:install`, `secret`, `doctor`, `poll`, `webhooks:sync`, `retry:sync`, `events:prune`
et `events:retry`. `doctor` contrôle aussi la joignabilité de chaque entreprise déclarée et
détecte un worker absent ou lancé sur la mauvaise file — la panne la plus silencieuse du lot.

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
