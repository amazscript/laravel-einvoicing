<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Console;

use AmazScript\Einvoicing\Drivers\Iopole\AccessTokenProvider;
use AmazScript\Einvoicing\Drivers\Iopole\Client;
use AmazScript\Einvoicing\Drivers\Iopole\Endpoints;
use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Models\WebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Diagnostic du raccordement.
 *
 * Chaque contrôle correspond à une panne déjà vue, et répond à la question
 * « pourquoi je ne reçois rien ? » sans ouvrir de ticket. Aucun secret n'est
 * affiché : la sortie est faite pour être collée dans une conversation.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'einvoicing:doctor {--no-network : Ne contacte pas la plateforme}';

    protected $description = 'Diagnostique la configuration et le raccordement à la plateforme';

    private int $problemes = 0;

    public function handle(Client $client, AccessTokenProvider $tokens): int
    {
        $this->newLine();
        $this->section('Configuration');
        $this->checkConfiguration();

        $this->section('Base de données');
        $this->checkDatabase();

        $this->section('Webhook');
        $this->checkWebhookRoute();

        if (! $this->option('no-network')) {
            $this->section('Plateforme');
            $this->checkPlatform($client, $tokens);
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
                // Les identifiants ne sont jamais affichés : cette sortie circule.
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
            // Un 429 renvoyé à la plateforme la ferait relancer sans raison.
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
