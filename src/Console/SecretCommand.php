<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Console;

use Illuminate\Console\Command;

/**
 * Produces the secret shared with the platform.
 *
 * The integrator supplies this secret when declaring the webhook; the platform
 * does not generate it. It therefore has to exist before anything is declared.
 */
final class SecretCommand extends Command
{
    protected $signature = 'einvoicing:secret {--show : Affiche le secret au lieu de rappeler où le placer}';

    protected $description = 'Génère un secret HMAC pour la vérification des webhooks';

    public function handle(): int
    {
        // 32 random bytes rendered in hexadecimal: the platform recommends at
        // least that length.
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
