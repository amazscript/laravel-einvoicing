<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Console;

use Illuminate\Console\Command;

/**
 * Publishes what the application must be able to edit: configuration and
 * migrations.
 */
final class InstallCommand extends Command
{
    protected $signature = 'einvoicing:install {--force : Écrase les fichiers déjà publiés}';

    protected $description = 'Publie la configuration et les migrations du package';

    public function handle(): int
    {
        foreach (['einvoicing-config' => 'configuration', 'einvoicing-migrations' => 'migrations'] as $tag => $libelle) {
            $this->components->task("Publication des {$libelle}", function () use ($tag): bool {
                $this->callSilently('vendor:publish', array_filter([
                    '--tag' => $tag,
                    '--force' => $this->option('force') ? true : null,
                ]));

                return true;
            });
        }

        $this->newLine();
        $this->line('  Étapes suivantes :');
        $this->line('   1. <fg=yellow>php artisan migrate</>');
        $this->line('   2. <fg=yellow>php artisan einvoicing:secret</>, puis renseigner le .env');
        $this->line('   3. <fg=yellow>php artisan einvoicing:doctor</> pour vérifier le raccordement');
        $this->newLine();

        // Updating the package does not update migrations already published:
        // saying so here spares a long hunt for the cause.
        $this->comment('  Après une mise à jour du package, republiez les migrations avec --force.');
        $this->newLine();

        return self::SUCCESS;
    }
}
