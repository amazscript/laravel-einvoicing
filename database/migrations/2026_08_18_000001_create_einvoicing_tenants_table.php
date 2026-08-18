<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('einvoicing_tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Le type de clé du modèle hôte est inconnu du package : entier auto-incrémenté,
            // uuid ou ulid selon l'application. On stocke donc l'identifiant en chaîne.
            $table->string('tenantable_type');
            $table->string('tenantable_id');

            // Chiffré à la lecture/écriture par le modèle : le customer-id ne doit jamais
            // apparaître en clair, ni en base ni dans les logs.
            $table->text('customer_id');

            // Clés de routage du webhook. Le SIRET prime sur le SIREN.
            $table->string('siren', 9);
            $table->string('siret', 14)->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['tenantable_type', 'tenantable_id']);
            $table->index('siren');
            $table->index('siret');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoicing_tenants');
    }
};
