# Dépannage

Les erreurs listées ici ont toutes été rencontrées en conditions réelles.

**Commencez toujours par :**

```bash
php artisan einvoicing:doctor
```

---

## Je ne reçois aucune facture

### Aucun dossier actif

`doctor` affiche `dossiers actifs : aucun`. Toute livraison part en `UNROUTED` : elle est conservée,
mais aucun événement applicatif n'est émis.

```bash
php artisan einvoicing:events:retry   # une fois le dossier créé
```

### Aucun worker ne tourne

Le contrôleur encaisse et met en file. Sans worker, les factures sont reçues et jamais exploitées.

```bash
php artisan queue:work --queue=einvoicing
```

Vérifiez : `DB::table('jobs')->count()`. Si le nombre grimpe, c'est le worker qui manque.

### Le webhook ne pointe pas sur l'application

```bash
php artisan einvoicing:webhooks:sync
```

Compare l'URL déclarée à celle attendue. Une URL de développement oubliée est la cause la plus
fréquente.

### Le destinataire n'est pas dans l'annuaire

Statut `REJECTED` portant `No route found for given key (electronicAddress : …)`. L'entreprise
existe, mais aucune plateforme ne dessert son adresse électronique.

`php artisan einvoicing:doctor` le dit avant qu'une facture ne rebondisse, entreprise par
entreprise, avec la cause. La correction, elle, passe par votre plateforme : voir
[Entreprises et joignabilité](entreprises.md).

---

## Toutes les livraisons répondent 401

### Secret absent ou différent

Un secret absent fait **tout** rejeter, volontairement : l'inverse reviendrait à accepter n'importe
quelle requête. Le secret du `.env` doit être identique à celui déclaré côté plateforme.

### Un proxy réécrit le chemin

La signature porte sur le chemin **public**. Si un proxy présente `/api/webhook` à l'extérieur et
`/einvoicing/webhook` à l'application, la vérification échoue alors que la requête est authentique.

```php
'canonical_path' => '/api/webhook',
```

### Horloge décalée

L'horodatage est accepté à 300 secondes près, dans les deux sens. Un serveur dont l'horloge dérive
rejette des requêtes authentiques. Vérifiez la synchronisation NTP avant d'élargir la tolérance.

### Le corps est altéré avant vérification

Un middleware qui reformate le corps invalide la signature. La route webhook ne doit traverser aucun
middleware modifiant le contenu.

---

## La même facture apparaît deux fois

Ne devrait pas arriver : l'unicité est portée par la base. Si cela se produit, vérifiez que la table
`einvoicing_webhook_events` porte bien son index unique sur `event_id` — une migration publiée
ancienne peut en manquer.

```bash
php artisan einvoicing:install --force
php artisan migrate
```

---

## Une erreur de contrainte au traitement d'un statut

```
NOT NULL constraint failed: einvoicing_statuses.value
```

Migrations publiées obsolètes. Les fichiers publiés sont des copies : une correction du package ne
les met pas à jour.

```bash
php artisan einvoicing:install --force
php artisan migrate
```

---

## `429 Too Many Requests`

La plateforme limite le débit. Les jobs reculent d'eux-mêmes entre deux tentatives (10 s, 1 min,
5 min, 15 min). Si cela persiste, espacez `einvoicing:poll`.

**N'appliquez jamais de limitation de débit à la route webhook** : un `429` renvoyé à la plateforme
lui fait relancer la livraison sans raison. `doctor` le signale.

---

## `401` à l'authentification

Le flux est OAuth2 `client_credentials` : aucun jeton permanent n'existe.

- `IOPOLE_TOKEN_URL` doit pointer le serveur d'authentification, pas l'API.
- Pré-production et production ont des identifiants **distincts** ; ceux de l'une sont refusés par
  l'autre.

---

## Une réponse illisible de la plateforme

```
Réponse de la plateforme illisible : JSON attendu.
```

Un endpoint répond parfois autre chose que du JSON malgré sa documentation — `GET /v1/config/customer/id`
renvoie du `text/html`. Si vous rencontrez ce cas sur un autre endpoint, signalez-le : c'est un
correctif à apporter au package.

---

## Des événements s'accumulent en souffrance

```bash
php artisan einvoicing:doctor          # « événements en souffrance : N »
php artisan einvoicing:events:retry
```

`UNROUTED` : le dossier destinataire n'existait pas. Créez-le, puis rejouez.
`FAILED` : le traitement a échoué. La raison est dans `failed_reason` ; elle ne cite jamais le
payload, qui porte des identifiants d'entreprise.

Ces événements ne sont **jamais** purgés automatiquement.

---

## Les tests d'intégration sont toujours « skipped »

```
- it obtient un jeton auprès du serveur d'authentification réel → identifiants sandbox absents
```

C'est le comportement voulu : ces deux tests sont les seuls à toucher le réseau, et ils s'ignorent
partout où les identifiants ne sont pas dans l'environnement — en intégration continue, et sur toute
machine sans sandbox.

**Renseigner le `.env` de votre application ne suffit pas.** Les tests du package tournent sous
Testbench, qui fabrique sa propre application Laravel minimale : elle ne lit le `.env` d'aucun autre
projet. Les identifiants doivent lui être passés explicitement :

```bash
make test-integration
```

ou, à la main :

```bash
IOPOLE_TOKEN_URL=… IOPOLE_CLIENT_ID=… IOPOLE_CLIENT_SECRET=… IOPOLE_BASE_URL=… \
  vendor/bin/pest --group=integration
```

---

## Signaler un problème

Joignez la sortie de `einvoicing:doctor` : elle ne contient aucun identifiant.

[github.com/amazscript/laravel-einvoicing/issues](https://github.com/amazscript/laravel-einvoicing/issues)
