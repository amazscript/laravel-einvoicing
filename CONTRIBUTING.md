# Contribuer

## Mise en route

```bash
composer install
make            # liste les raccourcis disponibles
```

## Avant chaque commit

```bash
make check      # format, analyse statique et tests d'affilée
```

Aucun commit ne part si les tests échouent. Les cibles individuelles existent aussi :
`make test`, `make analyse`, `make format`.

## Couverture

```bash
make test-critical   # Webhook/ et Tenancy/, seuil 85 %
make test-coverage   # ensemble du package, seuil 70 %
```

Les deux exigent un driver de couverture. **PCOV** convient et ne ralentit pas la suite :

```bash
pecl install pcov
```

Sous macOS/Homebrew, la compilation cherche les en-têtes `pcre2`, qu'il faut lui indiquer :

```bash
CPPFLAGS="-I$(brew --prefix pcre2)/include" pecl install pcov
```

Les scripts activent l'extension à la demande (`-d pcov.enabled=1`), il n'y a donc rien à laisser
allumé en permanence.

Seuils atteints à ce jour : **97,5 %** sur les points critiques, **84,5 %** au global.

## Tests contre la plateforme réelle

Deux tests interrogent une vraie sandbox. Ils s'ignorent partout où les identifiants sont absents de
l'environnement — donc en intégration continue, et sur toute machine sans sandbox.

```bash
make test-integration   # lit les identifiants du .env du playground
```

Aucun secret ne doit être écrit dans le dépôt.

## Ce qui exige une attention particulière

Cinq points portent la raison d'être du package. Une régression y coûte une facture perdue,
dupliquée ou mal routée — un incident comptable, pas un défaut d'affichage.

1. **Signature HMAC** — le checksum porte sur le corps entier en JSON, sur le contenu du champ
   fichier seul en multipart. `php://input` est vide en multipart.
2. **Routage multi-tenant** — mal router est pire que ne pas router.
3. **Déduplication** — l'unicité est portée par la base, jamais par une lecture préalable.
4. **Idempotence** — toute écriture passe par `updateOrCreate`.
5. **Erreurs d'API** — un 5xx renvoyé à la plateforme déclenche ses relances pour rien.

Pour ces points, écrivez le test **avant** le code.

## Recette manuelle

Les tests automatisés tournent en isolation ; le playground voisin sert à voir le package à
l'œuvre dans une vraie application.

```bash
make serve      # sert le playground sur le port 8000
make work       # traite la file d'attente
make doctor     # diagnostique le raccordement
make fresh      # réinitialise la base et republie les migrations
make captures   # liste les livraisons capturées sur le tunnel
make package    # montre ce qui partirait réellement chez un utilisateur
```

## Langue

Le code publié — `src/`, `config/`, `database/migrations/` — est commenté en **anglais** : ces
docblocks s'affichent au survol dans un IDE, et le package s'adresse à tout l'écosystème Laravel.
Les messages d'exception le sont aussi, puisqu'ils atterrissent dans les journaux.

Le README, `docs/`, les noms de tests et les sorties des commandes Artisan restent en **français** :
ils s'adressent à l'exploitant, et le marché visé est français.

## Fixtures

`tests/Fixtures/` contient des livraisons réellement émises par la plateforme, anonymisées :
identifiants d'entreprise remplacés, signatures recalculées sous un secret de test public.

Elles ont attrapé des défauts qu'aucun vecteur inventé n'aurait révélés — un horodatage en
millisecondes, un schéma d'identifiant inconnu, une réponse servie dans un tableau. Préférez-les à
des exemples construits de toutes pièces.
