<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Console;

use AmazScript\Einvoicing\Drivers\Iopole\Client;
use AmazScript\Einvoicing\Drivers\Iopole\Endpoints;
use AmazScript\Einvoicing\Exceptions\EinvoicingException;
use Illuminate\Console\Command;

/**
 * Compares the local webhook configuration with the platform's.
 *
 * The command only ever reads. Declaring or moving a webhook redirects a stream
 * of invoices, and getting it wrong sends deliveries into the void, so that call
 * stays with a human — the command shows the gap and the URL to declare.
 */
final class SyncWebhooksCommand extends Command
{
    protected $signature = 'einvoicing:webhooks:sync
                            {--url= : URL de rappel à comparer (sinon déduite de la configuration)}';

    protected $description = 'Compare la déclaration du webhook à la configuration locale';

    public function handle(Client $client): int
    {
        try {
            $declares = $client->get(Endpoints::webhooks());
        } catch (EinvoicingException $e) {
            $this->error('Impossible de lire la configuration : '.$e->getMessage());

            return self::FAILURE;
        }

        $attendue = $this->expectedUrl();
        $direction = (string) config('einvoicing.webhook.direction', 'INBOUND');

        $this->newLine();
        $this->line('  <options=bold>Déclaré côté plateforme</>');

        $correspondant = null;

        foreach ($declares as $webhook) {
            if (! is_array($webhook)) {
                continue;
            }

            $endpoints = $this->endpoints($webhook);
            $url = $this->callbackUrl($endpoints);
            $signe = isset($endpoints['authentication']);

            $this->line(sprintf(
                '   %-9s %-14s %s %s',
                (string) ($webhook['status'] ?? '?'),
                (string) ($webhook['filterStreamDirection'] ?? 'bidirectionnel'),
                $signe ? '<fg=green>signé</>' : '<fg=red>non signé</>',
                $url ?? '—',
            ));

            if ($url === $attendue) {
                $correspondant = $webhook;
            }
        }

        $this->newLine();
        $this->line('  <options=bold>Attendu localement</>');
        $this->line("   {$direction} → ".($attendue ?? '<fg=red>aucune URL déterminable</>'));
        $this->newLine();

        if ($correspondant !== null) {
            $this->info('La plateforme livre bien sur cette application.');

            return self::SUCCESS;
        }

        $this->warn('Aucun webhook déclaré ne pointe sur cette application.');
        $this->newLine();
        $this->line('  Déclarez l\'URL ci-dessus dans la console de la plateforme, en');
        $this->line('  fournissant le secret HMAC de votre .env comme clé d\'authentification.');
        $this->newLine();
        $this->comment('  La création automatique n\'est pas assurée par cette commande : elle');
        $this->comment('  redirigerait un flux de factures sur la seule foi d\'une configuration locale.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $webhook
     * @return array<string, mixed>
     */
    private function endpoints(array $webhook): array
    {
        $interop = $webhook['interopData'] ?? null;
        $endpoints = is_array($interop) ? ($interop['endpoints'] ?? null) : null;

        return is_array($endpoints) ? $endpoints : [];
    }

    /**
     * @param  array<string, mixed>  $endpoints
     */
    private function callbackUrl(array $endpoints): ?string
    {
        foreach (['invoice', 'status'] as $clef) {
            $bloc = $endpoints[$clef] ?? null;
            $url = is_array($bloc) ? ($bloc['callbackUrl'] ?? null) : null;

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    private function expectedUrl(): ?string
    {
        $fournie = $this->option('url');

        if (is_string($fournie) && $fournie !== '') {
            return $fournie;
        }

        $chemin = (string) config('einvoicing.webhook.path', 'einvoicing/webhook');
        $base = config('app.url');

        return is_string($base) && $base !== '' ? rtrim($base, '/').'/'.ltrim($chemin, '/') : null;
    }
}
