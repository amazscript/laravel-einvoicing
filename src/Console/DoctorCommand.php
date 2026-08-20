<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Console;

use AmazScript\Einvoicing\Contracts\BusinessEntityGateway;
use AmazScript\Einvoicing\Drivers\Iopole\AccessTokenProvider;
use AmazScript\Einvoicing\Drivers\Iopole\Client;
use AmazScript\Einvoicing\Drivers\Iopole\Endpoints;
use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Models\WebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Diagnoses the connection to the platform.
 *
 * Every check maps to a failure already seen, and answers "why am I receiving
 * nothing?" before a ticket is opened. No secret is printed: the output is meant
 * to be pasted into a conversation.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'einvoicing:doctor {--no-network : Ne contacte pas la plateforme}';

    protected $description = 'Diagnostique la configuration et le raccordement à la plateforme';

    private int $problemes = 0;

    public function handle(Client $client, AccessTokenProvider $tokens, BusinessEntityGateway $entities): int
    {
        $this->newLine();
        $this->section('Configuration');
        $this->checkConfiguration();

        $this->section('Base de données');
        $this->checkDatabase();

        $this->section('Webhook');
        $this->checkWebhookRoute();

        $this->section('Traitement');
        $this->checkQueueWorker();

        if (! $this->option('no-network')) {
            $this->section('Plateforme');
            $this->checkPlatform($client, $tokens);

            $this->section('Entreprises');
            $this->checkEntities($entities);
        }

        $this->newLine();

        if ($this->problemes === 0) {
            $this->info('  Aucun problème détecté.');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->error("  {$this->problemes} point(s) à corriger.");
        $this->newLine();

        return self::FAILURE;
    }

    private function checkConfiguration(): void
    {
        $driver = (string) config('einvoicing.default', 'iopole');
        $this->ok('driver actif', $driver);

        foreach (['base_url', 'token_url', 'client_id', 'client_secret', 'customer_id'] as $clef) {
            $valeur = config("einvoicing.drivers.{$driver}.{$clef}");
            $rempli = is_string($valeur) && $valeur !== '';

            $rempli
                // Credentials are never printed: this output travels.
                ? $this->ok($clef, str_contains($clef, 'secret') || str_contains($clef, 'client_id')
                    ? 'renseigné'
                    : (string) $valeur)
                : $this->ko($clef, 'absent du .env');
        }

        $secret = config('einvoicing.webhook.secret');

        match (true) {
            ! is_string($secret) || $secret === '' => $this->ko(
                'secret du webhook',
                'absent — toute livraison sera rejetée en 401',
            ),
            strlen($secret) < 32 => $this->ko(
                'secret du webhook',
                'trop court ('.strlen($secret).' caractères, 32 minimum recommandés)',
            ),
            default => $this->ok('secret du webhook', strlen($secret).' caractères'),
        };
    }

    /**
     * Whether anything is actually consuming the package's queue.
     *
     * Without a worker on the right queue, everything upstream looks healthy:
     * the route answers 202, deliveries are recorded, and not a single invoice
     * is ever processed. Jobs sitting past their due time are the tell.
     */
    private function checkQueueWorker(): void
    {
        $file = $this->configString('einvoicing.queue.name') ?? 'default';
        $connexion = $this->configString('einvoicing.queue.connection');
        $pilote = $this->configString('queue.connections.'.($connexion ?? (string) config('queue.default')).'.driver');

        $this->ok('file', $file);

        if ($pilote !== 'database') {
            // Only the database driver can be inspected from here; anything
            // else would need to talk to Redis or SQS, which doctor will not do.
            $this->line('     <fg=gray>file '.($pilote ?? 'inconnue').' : en attente non vérifiable depuis ici</>');

            return;
        }

        try {
            $enRetard = DB::table('jobs')
                ->where('queue', $file)
                ->where('available_at', '<=', time() - 60)
                ->count();
        } catch (Throwable) {
            $this->line('     <fg=gray>table des jobs absente : file non vérifiable</>');

            return;
        }

        if ($enRetard === 0) {
            $this->ok('jobs en souffrance', 'aucun');

            return;
        }

        $this->ko('jobs en souffrance', $enRetard.' depuis plus d\'une minute');
        $this->line('     <fg=yellow>·</> aucun worker ne consomme cette file');
        $this->line('     <fg=gray>php artisan queue:work --queue='.$file.'</>');
    }

    private function configString(string $cle): ?string
    {
        $valeur = config($cle);

        return is_string($valeur) && $valeur !== '' ? $valeur : null;
    }

    private function checkDatabase(): void
    {
        $tables = [
            'einvoicing_tenants', 'einvoicing_inbound_invoices', 'einvoicing_invoice_files',
            'einvoicing_statuses', 'einvoicing_webhook_events',
        ];

        foreach ($tables as $table) {
            Schema::hasTable($table)
                ? $this->ok($table, 'présente')
                : $this->ko($table, 'absente — lancez php artisan migrate');
        }

        if (! Schema::hasTable('einvoicing_tenants')) {
            return;
        }

        $actifs = Tenant::query()->where('active', true)->count();

        $actifs === 0
            ? $this->ko('dossiers actifs', 'aucun — toute livraison partira en UNROUTED')
            : $this->ok('dossiers actifs', (string) $actifs);

        if (Schema::hasTable('einvoicing_webhook_events')) {
            $enSouffrance = WebhookEvent::query()
                ->whereIn('status', [WebhookEventStatus::Unrouted->value, WebhookEventStatus::Failed->value])
                ->count();

            $enSouffrance === 0
                ? $this->ok('événements en souffrance', 'aucun')
                : $this->ko('événements en souffrance', $enSouffrance.' — voir einvoicing:events:retry');
        }
    }

    private function checkWebhookRoute(): void
    {
        $chemin = (string) config('einvoicing.webhook.path', '');

        $chemin === ''
            ? $this->ko('route', 'aucun chemin configuré')
            : $this->ok('route', 'POST /'.ltrim($chemin, '/'));

        $middleware = config('einvoicing.webhook.middleware', []);
        $middleware = is_array($middleware) ? $middleware : [];

        $limiteur = array_filter(
            $middleware,
            static fn (mixed $m): bool => is_string($m) && str_contains($m, 'throttle'),
        );

        $limiteur === []
            ? $this->ok('limitation de débit', 'absente, comme il se doit')
            // A 429 returned to the platform would make it retry for nothing.
            : $this->ko('limitation de débit', 'présente — un 429 déclencherait des relances inutiles');

        $canonique = config('einvoicing.webhook.canonical_path');

        if (is_string($canonique) && $canonique !== '') {
            $this->ok('chemin canonique', $canonique);
        }

        $tolerance = config('einvoicing.webhook.tolerance');
        $this->ok('tolérance anti-rejeu', (is_numeric($tolerance) ? (int) $tolerance : 300).' s');
    }

    private function checkPlatform(Client $client, AccessTokenProvider $tokens): void
    {
        try {
            $tokens->token();
            $this->ok('authentification', 'jeton obtenu');
        } catch (Throwable $e) {
            $this->ko('authentification', $e->getMessage());

            return;
        }

        try {
            $identifiant = trim($client->raw(Endpoints::customerId()));
            $configure = (string) config('einvoicing.drivers.'.config('einvoicing.default', 'iopole').'.customer_id');

            $identifiant === $configure
                ? $this->ok('customer-id', 'conforme à la configuration')
                : $this->ko('customer-id', 'la plateforme en annonce un autre que celui configuré');
        } catch (Throwable $e) {
            $this->ko('customer-id', $e->getMessage());
        }

        try {
            $webhooks = $client->get(Endpoints::webhooks());
            $actifs = array_filter(
                $webhooks,
                static fn (mixed $w): bool => is_array($w) && ($w['status'] ?? null) === 'ACTIVE',
            );

            $actifs === []
                ? $this->ko('webhooks', 'aucun webhook actif : rien ne sera livré')
                : $this->ok('webhooks', count($actifs).' actif(s)');

            $signes = array_filter($actifs, function (mixed $w): bool {
                $interop = is_array($w) ? ($w['interopData'] ?? null) : null;
                $endpoints = is_array($interop) ? ($interop['endpoints'] ?? null) : null;

                return is_array($endpoints) && isset($endpoints['authentication']);
            });

            if ($actifs !== [] && $signes === []) {
                $this->ko('signature des webhooks', 'aucun webhook actif ne porte de secret HMAC');
            }
        } catch (Throwable $e) {
            $this->ko('webhooks', $e->getMessage());
        }
    }

    /**
     * A company can be declared without holding an active directory entry, in
     * which case invoices addressed to it bounce at the sender with "No route
     * found". Nothing else in the setup reveals that, so it is checked here.
     */
    private function checkEntities(BusinessEntityGateway $entities): void
    {
        try {
            $declarees = $entities->all()->take(200)->all();
        } catch (Throwable $e) {
            $this->ko('entreprises', $e->getMessage());

            return;
        }

        if ($declarees === []) {
            $this->ko('entreprises déclarées', 'aucune — personne ne peut vous adresser de facture');

            return;
        }

        $joignables = array_filter($declarees, static fn ($e): bool => $e->isReachable());

        $this->ok('entreprises déclarées', (string) count($declarees));

        count($joignables) === count($declarees)
            ? $this->ok('joignables', 'toutes')
            : $this->ko('joignables', count($joignables).'/'.count($declarees));

        foreach ($declarees as $entite) {
            $raison = $entite->unreachableReason();

            if ($raison !== null) {
                $this->line('     <fg=yellow>·</> '
                    .str_pad(mb_substr($entite->name, 0, 28), 30)
                    .'<fg=gray>'.$this->explain($raison).'</>');
            }
        }
    }

    /**
     * Traduit un code de non-joignabilité en explication, avec le geste à faire.
     */
    private function explain(string $raison): string
    {
        return match ($raison) {
            'no-identifier' => 'aucun identifiant déclaré',
            'no-registration' => 'aucun identifiant inscrit à l\'annuaire',
            'registration-not-yet-active' => 'inscrite à l\'annuaire, mais pas avant sa date d\'effet',
            default => $raison,
        };
    }

    private function section(string $titre): void
    {
        $this->line('  <options=bold;fg=cyan>'.$titre.'</>');
    }

    private function ok(string $quoi, string $valeur): void
    {
        $this->line('   <fg=green>✓</> '.str_pad($quoi, 30).' <fg=gray>'.$valeur.'</>');
    }

    private function ko(string $quoi, string $valeur): void
    {
        $this->problemes++;
        $this->line('   <fg=red>✗</> '.str_pad($quoi, 30).' <fg=yellow>'.$valeur.'</>');
    }
}
