<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Console;

use AmazScript\Einvoicing\Drivers\Iopole\Client;
use AmazScript\Einvoicing\Drivers\Iopole\Endpoints;
use AmazScript\Einvoicing\Exceptions\EinvoicingException;
use Illuminate\Console\Command;

/**
 * Affiche la stratégie de relance appliquée par la plateforme.
 *
 * Elle détermine combien de fois une livraison sera retentée si l'application
 * est indisponible : c'est la marge dont on dispose pour redémarrer avant de
 * perdre une facture.
 */
final class SyncRetryStrategyCommand extends Command
{
    protected $signature = 'einvoicing:retry:sync';

    protected $description = 'Affiche la stratégie de relance des webhooks côté plateforme';

    public function handle(Client $client): int
    {
        try {
            $strategie = $client->get(Endpoints::retryStrategy());
        } catch (EinvoicingException $e) {
            $this->error('Lecture impossible : '.$e->getMessage());

            return self::FAILURE;
        }

        if ($strategie === []) {
            $this->warn('Aucune stratégie déclarée : la plateforme appliquera la sienne par défaut.');

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($strategie as $clef => $valeur) {
            $this->line(sprintf(
                '   %-26s <fg=gray>%s</>',
                (string) $clef,
                is_scalar($valeur) ? (string) $valeur : json_encode($valeur, JSON_UNESCAPED_UNICODE),
            ));
        }

        $this->newLine();
        $this->comment('  Les relances sont pilotées par la plateforme : le package ne les provoque pas.');
        $this->newLine();

        return self::SUCCESS;
    }
}
