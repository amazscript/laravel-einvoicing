<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Console;

use AmazScript\Einvoicing\Einvoicing;
use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Models\WebhookEvent;
use AmazScript\Einvoicing\Webhook\InboundEventDispatcher;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Repêche ce que les webhooks auraient manqué.
 *
 * Un webhook peut se perdre : coupure réseau, application arrêtée, tunnel fermé.
 * Cette commande demande à la plateforme ce qu'elle n'a pas vu acquitté et
 * réinjecte le manquant dans le même circuit que les livraisons, déduplication
 * comprise — une facture déjà reçue par webhook ne sera donc pas traitée deux fois.
 */
final class PollCommand extends Command
{
    protected $signature = 'einvoicing:poll
                            {--tenant= : SIREN, SIRET ou identifiant d\'un dossier précis}
                            {--dry-run : Montre ce qui serait repris, sans rien écrire}';

    protected $description = 'Récupère les factures et statuts non acquittés (repli sur les webhooks)';

    public function handle(Einvoicing $einvoicing, InboundEventDispatcher $dispatcher): int
    {
        $tenants = $this->tenants();

        if ($tenants->isEmpty()) {
            $this->error('Aucun dossier actif à interroger.');

            return self::FAILURE;
        }

        $reprises = 0;

        foreach ($tenants as $tenant) {
            $passerelle = $einvoicing->for($tenant)->invoices();

            $reprises += $this->ingest(
                $passerelle->remoteNotSeen(),
                'INVOICE_INBOUND',
                $tenant,
                $dispatcher,
            );

            $reprises += $this->ingest(
                $passerelle->remoteStatusesNotSeen(),
                'INVOICE_STATUS',
                $tenant,
                $dispatcher,
            );
        }

        $this->info($this->option('dry-run')
            ? "{$reprises} élément(s) seraient repris."
            : "{$reprises} élément(s) repris.");

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     */
    private function ingest(array $elements, string $type, Tenant $tenant, InboundEventDispatcher $dispatcher): int
    {
        $nouveaux = 0;

        foreach ($elements as $element) {
            $cle = $this->key($element, $type);

            if ($cle === null) {
                continue;
            }

            if ($this->alreadyKnown($cle, $element, $type)) {
                continue;
            }

            if ($this->option('dry-run')) {
                $nouveaux++;

                continue;
            }

            try {
                $evenement = WebhookEvent::query()->create([
                    'event_id' => $cle,
                    'event_type' => $type,
                    'tenant_id' => $tenant->id,
                    'status' => WebhookEventStatus::Received,
                    'payload' => $element,
                    'received_at' => Carbon::now(),
                ]);
            } catch (QueryException) {
                // Déjà reçu par webhook : c'est précisément le but de la clé.
                continue;
            }

            $dispatcher->dispatch($evenement);
            $nouveaux++;
        }

        return $nouveaux;
    }

    /**
     * Un objet déjà reçu par webhook ne doit pas être réintroduit par le repli.
     *
     * La difficulté : un webhook se déduplique sur la clé d'idempotence fournie
     * par la plateforme, tandis que le repli ne dispose que de l'identifiant
     * métier. Les deux clés diffèrent pour un même objet. On regarde donc aussi
     * dans le payload conservé.
     *
     * Cette vérification est applicative, contrairement à celle du webhook qui
     * s'appuie sur la base : c'est acceptable ici, le repli étant une commande
     * qu'on ne lance pas en parallèle d'elle-même.
     *
     * @param  array<string, mixed>  $element
     */
    private function alreadyKnown(string $cle, array $element, string $type): bool
    {
        if (WebhookEvent::query()->where('event_id', $cle)->exists()) {
            return true;
        }

        $champ = $type === 'INVOICE_STATUS' ? 'statusId' : 'invoiceId';
        $valeur = $element[$champ] ?? null;

        if (! is_string($valeur) || $valeur === '') {
            return false;
        }

        return WebhookEvent::query()->where('payload->'.$champ, $valeur)->exists();
    }

    /**
     * La clé doit coïncider avec celle d'un webhook portant le même objet, sans
     * quoi le repli créerait des doublons de ce qui est déjà arrivé.
     *
     * @param  array<string, mixed>  $element
     */
    private function key(array $element, string $type): ?string
    {
        $champ = $type === 'INVOICE_STATUS' ? 'statusId' : 'invoiceId';
        $valeur = $element[$champ] ?? null;

        return is_string($valeur) && $valeur !== '' ? strtolower($champ).':'.$valeur : null;
    }

    /**
     * @return Collection<int, Tenant>
     */
    private function tenants(): Collection
    {
        $filtre = $this->option('tenant');

        $query = Tenant::query()->where('active', true);

        if (is_string($filtre) && $filtre !== '') {
            $query->where(function ($q) use ($filtre): void {
                $q->where('id', $filtre)->orWhere('siren', $filtre)->orWhere('siret', $filtre);
            });
        }

        return $query->get();
    }
}
