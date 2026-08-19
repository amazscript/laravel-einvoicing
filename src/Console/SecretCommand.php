<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Console;

use Illuminate\Console\Command;

/**
 * Produit le secret partagé avec la plateforme.
 *
 * C'est l'intégrateur qui fournit ce secret lors de la déclaration du webhook,
 * la plateforme ne le génère pas. Il faut donc l'obtenir avant de déclarer quoi
 * que ce soit.
 */
final class SecretCommand extends Command
{
    protected $signature = 'einvoicing:secret {--show : Affiche le secret au lieu de rappeler où le placer}';

    protected $description = 'Génère un secret HMAC pour la vérification des webhooks';

    public function handle(): int
    {
        // 32 octets aléatoires rendus en hexadécimal : la plateforme recommande
        // au moins cette longueur.
        $secret = bin2hex(random_bytes(32));

        $this->newLine();
        $this->line('  <fg=green>Secret généré</> <fg=gray>('.strlen($secret).' caractères)</>');
        $this->newLine();
        $this->line('  '.$secret);
        $this->newLine();
        $this->line('  À placer dans votre <fg=yellow>.env</> :');
        $this->line('  <fg=gray>EINVOICING_WEBHOOK_SECRET='.($this->option('show') ? $secret : '…').'</>');
        $this->newLine();
        $this->comment('  Ce secret n\'est pas enregistré : conservez-le maintenant.');
        $this->comment('  Il doit être déclaré à l\'identique côté plateforme (einvoicing:webhooks:sync).');
        $this->newLine();

        return self::SUCCESS;
    }
}
