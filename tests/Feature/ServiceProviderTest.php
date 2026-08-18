<?php

declare(strict_types=1);
use Illuminate\Support\ServiceProvider;

it('charge la configuration du package sans publication préalable', function (): void {
    expect(config('einvoicing.default'))->toBe('iopole')
        ->and(config('einvoicing.webhook.path'))->toBe('einvoicing/webhook')
        ->and(config('einvoicing.webhook.tolerance'))->toBe(300);
});

it('ne fournit aucun secret de webhook par défaut', function (): void {
    // Un secret absent doit rester absent : le middleware de signature (D05)
    // refusera toute requête plutôt que de laisser passer sans vérification.
    expect(config('einvoicing.webhook.secret'))->toBeNull();
});

it('expose les ressources publiables attendues', function (): void {
    $groups = ServiceProvider::$publishGroups;

    expect($groups)->toHaveKeys(['einvoicing-config', 'einvoicing-migrations']);
});
