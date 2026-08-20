# Raccourcis de développement du package.
#
# « make » seul liste ce qui est disponible. Les cibles qui touchent au
# playground supposent l'application de recette voisine, décrite dans SPRINT.md.

PLAYGROUND := ../einvoicing-playground
PHP_COVERAGE := php -d pcov.enabled=1

.DEFAULT_GOAL := help

# ---------------------------------------------------------------- vérification

.PHONY: check
check: format analyse test ## Tout vérifier avant de commiter

.PHONY: test
test: ## Lancer la suite de tests
	vendor/bin/pest

.PHONY: test-critical
test-critical: ## Couverture des points critiques (seuil 85 %)
	$(PHP_COVERAGE) vendor/bin/pest -c phpunit.critical.xml --coverage --min=85

.PHONY: test-coverage
test-coverage: ## Couverture de l'ensemble du package (seuil 70 %)
	$(PHP_COVERAGE) vendor/bin/pest --coverage --min=70

.PHONY: test-integration
test-integration: ## Tests contre la sandbox réelle (identifiants requis)
	@set -a; . $(PLAYGROUND)/.env; set +a; vendor/bin/pest --group=integration

.PHONY: analyse
analyse: ## Analyse statique, niveau 8
	vendor/bin/phpstan analyse --memory-limit=512M

.PHONY: format
format: ## Corriger le style
	vendor/bin/pint

.PHONY: format-check
format-check: ## Vérifier le style sans rien modifier
	vendor/bin/pint --test

# ------------------------------------------------------------------- recette

.PHONY: serve
serve: ## Servir le playground sur le port 8000
	cd $(PLAYGROUND) && php artisan serve --port=8000

.PHONY: work
work: ## Traiter la file d'attente du playground
	cd $(PLAYGROUND) && php artisan queue:work --queue=einvoicing

.PHONY: doctor
doctor: ## Diagnostiquer le raccordement depuis le playground
	cd $(PLAYGROUND) && php artisan einvoicing:doctor

.PHONY: fresh
fresh: ## Réinitialiser la base du playground et republier les migrations
	cd $(PLAYGROUND) && php artisan vendor:publish --tag=einvoicing-migrations --force --no-interaction \
		&& php artisan migrate:fresh --force

.PHONY: captures
captures: ## Lister les livraisons capturées sur le tunnel
	@ls -1t $(PLAYGROUND)/storage/app/captures 2>/dev/null | head -20 || echo "aucune capture"

# ------------------------------------------------------------------ publication

.PHONY: package
package: ## Montrer ce qui partirait réellement chez un utilisateur
	@git archive HEAD | tar -t | grep -v "/$$" | sort
	@echo "---"
	@git archive HEAD | tar -t | grep -vc "/$$" | xargs echo "fichiers publiés :"

.PHONY: retag
retag: ## Replacer le tag v0.1.0 sur la pointe de main
	git tag -f -a v0.1.0 -F .github/releases/TAG_MESSAGE.txt
	@echo "tag v0.1.0 -> $$(git rev-parse --short v0.1.0^{commit})"

# ------------------------------------------------------------------------ aide

.PHONY: help
help: ## Afficher cette aide
	@grep -hE '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'
