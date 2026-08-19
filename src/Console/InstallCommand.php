<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Console;

use Illuminate\Console\Command;

/**
 * Publie ce que l'application doit pouvoir modifier : configuration et migrations.
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

        // Une mise à jour du package ne met pas à jour les migrations déjà
        // publiées : le rappeler évite de longues recherches de panne.
        $this->comment('  Après une mise à jour du package, republiez les migrations avec --force.');
        $this->newLine();

        return self::SUCCESS;
    }
}
