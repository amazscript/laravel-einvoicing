<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sans worker sur la bonne file, tout le reste paraît sain : la route répond
 * 202, les livraisons sont enregistrées, et aucune facture n'est traitée.
 * C'est le diagnostic le plus utile du lot, parce que c'est le plus silencieux.
 */
beforeEach(function (): void {
    // testbench ne publie pas la table des jobs : le contrôle porte dessus.
    Schema::create('jobs', function ($table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    config()->set('einvoicing.queue.name', 'einvoicing');
    config()->set('einvoicing.queue.connection', 'database');
});

it('signale des jobs qui attendent depuis plus d\'une minute', function (): void {
    DB::table('jobs')->insert([
        'queue' => 'einvoicing',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time() - 120,
        'created_at' => time() - 120,
    ]);

    $this->artisan('einvoicing:doctor', ['--no-network' => true])
        ->expectsOutputToContain('jobs en souffrance')
        ->expectsOutputToContain('aucun worker ne consomme cette file')
        ->run();
});

it('ne signale rien quand un job vient tout juste d\'être déposé', function (): void {
    // Un job d'une seconde n'est pas en souffrance : le worker n'a pas eu le temps.
    DB::table('jobs')->insert([
        'queue' => 'einvoicing',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time(),
        'created_at' => time(),
    ]);

    $this->artisan('einvoicing:doctor', ['--no-network' => true])
        ->doesntExpectOutputToContain('aucun worker ne consomme cette file')
        ->run();
});

it('ne signale rien quand la file est vide', function (): void {
    $this->artisan('einvoicing:doctor', ['--no-network' => true])
        ->expectsOutputToContain('aucun')
        ->run();
});
