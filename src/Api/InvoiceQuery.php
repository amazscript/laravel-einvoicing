<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Api;

use AmazScript\Einvoicing\Contracts\InvoiceGateway;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * Les factures d'un dossier, vues d'ici ou vues de la plateforme.
 *
 * La distinction est explicite : `local()` interroge la base du package,
 * `remoteNotSeen()` interroge la plateforme. Une méthode unique qui ferait
 * tantôt l'un tantôt l'autre serait une source d'erreurs coûteuses.
 */
final class InvoiceQuery
{
    public function __construct(
        private readonly ?Tenant $tenant,
        private readonly InvoiceGateway $gateway,
    ) {}

    /**
     * Factures déjà reçues et consignées par le package.
     *
     * @return Builder<InboundInvoice>
     */
    public function local(): Builder
    {
        $query = InboundInvoice::query();

        return $this->tenant instanceof Tenant
            ? $query->where('tenant_id', $this->tenant->id)
            : $query;
    }

    /**
     * Factures que la plateforme considère comme non acquittées.
     *
     * @return list<array<string, mixed>>
     */
    public function remoteNotSeen(): array
    {
        return $this->gateway->notSeen();
    }

    /**
     * Statuts non acquittés côté plateforme.
     *
     * @return list<array<string, mixed>>
     */
    public function remoteStatusesNotSeen(): array
    {
        return $this->gateway->statusesNotSeen();
    }

    /**
     * Recherche de factures auprès de la plateforme.
     *
     * Accepte la syntaxe de filtres telle quelle :
     *
     *     search('invoice.direction:"INBOUND" AND invoice.state:"NOT_DELIVERED"')
     *
     * ou des critères, assemblés par « AND » :
     *
     *     search(['invoice.direction' => 'INBOUND', 'invoice.state' => 'NOT_DELIVERED'])
     *
     * Le parcours est paresseux : la plateforme pagine, et rien ne justifie de
     * tout charger pour n'en lire que les premiers.
     *
     * Chaque résultat porte un objet `metadata`. Pour obtenir davantage dans la
     * même réponse plutôt qu'un appel par facture :
     *
     *     search([...], expand: ['businessData', 'lastStatusData'])
     *
     * @param  string|array<string, string>  $criteria
     * @param  list<string>  $expand
     * @return LazyCollection<int, array<mixed>>
     */
    public function search(string|array $criteria, array $expand = []): LazyCollection
    {
        return $this->gateway->searchInvoices(
            is_array($criteria) ? $this->buildQuery($criteria) : $criteria,
            $expand,
        );
    }

    /**
     * Assemble les critères en requête.
     *
     * Guillemets et antislashs sont **retirés** des valeurs, non échappés : la
     * syntaxe d'échappement du moteur de recherche n'est pas documentée, et un
     * échappement qu'il n'interpréterait pas laisserait une valeur extérieure
     * refermer la clause et réécrire le sens de la recherche. Une valeur
     * légitime n'en contient pas.
     *
     * Qui a besoin d'une requête plus fine la passe telle quelle, en chaîne.
     *
     * @param  array<string, string>  $criteria
     */
    private function buildQuery(array $criteria): string
    {
        $clauses = [];

        foreach ($criteria as $champ => $valeur) {
            $clauses[] = sprintf('%s:"%s"', $champ, str_replace(['"', '\\'], '', $valeur));
        }

        return implode(' AND ', $clauses);
    }
}
