<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('crée les cinq tables du package', function (): void {
    foreach ([
        'einvoicing_tenants',
        'einvoicing_inbound_invoices',
        'einvoicing_invoice_files',
        'einvoicing_statuses',
        'einvoicing_webhook_events',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("table manquante : {$table}");
    }
});

it('refuse deux factures portant le même identifiant chez le même fournisseur', function (): void {
    $insert = fn () => DB::table('einvoicing_inbound_invoices')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => null,
        'provider' => 'iopole',
        'provider_invoice_id' => 'INV-001',
    ]);

    $insert();

    expect($insert)->toThrow(QueryException::class);
});

it('accepte le même identifiant de facture chez deux fournisseurs différents', function (): void {
    foreach (['iopole', 'autre-pa'] as $provider) {
        DB::table('einvoicing_inbound_invoices')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => null,
            'provider' => $provider,
            'provider_invoice_id' => 'INV-001',
        ]);
    }

    expect(DB::table('einvoicing_inbound_invoices')->count())->toBe(2);
});

it('refuse deux statuts portant le même identifiant chez le même fournisseur', function (): void {
    $insert = fn () => DB::table('einvoicing_statuses')->insert([
        'id' => (string) Str::uuid(),
        'provider' => 'iopole',
        'provider_status_id' => 'STA-001',
        'code' => 'RECEIVED',
        'value' => '202',
    ]);

    $insert();

    expect($insert)->toThrow(QueryException::class);
});

it('refuse deux événements webhook portant le même event_id', function (): void {
    $insert = fn () => DB::table('einvoicing_webhook_events')->insert([
        'id' => (string) Str::uuid(),
        'event_id' => 'EVT-001',
        'event_type' => 'INVOICE_INBOUND',
        'status' => 'RECEIVED',
        'received_at' => now(),
    ]);

    $insert();

    // Une violation ici signifie « déjà reçu ». Le traitement applicatif la considère
    // comme un succès ; ce qui compte est que la base la détecte, pas le code.
    expect($insert)->toThrow(QueryException::class);
});

it('conserve une facture dont le tenant n\'a pas pu être résolu', function (): void {
    DB::table('einvoicing_inbound_invoices')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => null,
        'provider' => 'iopole',
        'provider_invoice_id' => 'INV-UNROUTED',
    ]);

    expect(DB::table('einvoicing_inbound_invoices')->whereNull('tenant_id')->count())->toBe(1);
});

it('refuse un fichier rattaché à une facture inexistante', function (): void {
    $insert = fn () => DB::table('einvoicing_invoice_files')->insert([
        'id' => (string) Str::uuid(),
        'invoice_id' => (string) Str::uuid(),
        'kind' => 'XML',
        'disk' => 'local',
        'path' => 'einvoicing/inconnu.xml',
        'checksum' => str_repeat('a', 64),
    ]);

    expect($insert)->toThrow(QueryException::class);
});

it('redescend intégralement le schéma', function (): void {
    // Le chemin doit être explicite : les migrations du package ne vivent pas dans le
    // dossier database/migrations de l'application hôte tant qu'elles ne sont pas publiées.
    $this->artisan('migrate:rollback', [
        '--path' => realpath(__DIR__.'/../../database/migrations'),
        '--realpath' => true,
    ])->assertSuccessful();

    foreach ([
        'einvoicing_tenants',
        'einvoicing_inbound_invoices',
        'einvoicing_invoice_files',
        'einvoicing_statuses',
        'einvoicing_webhook_events',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("table encore présente : {$table}");
    }
});
