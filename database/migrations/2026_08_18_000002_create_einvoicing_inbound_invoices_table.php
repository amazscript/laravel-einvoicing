<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('einvoicing_inbound_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Nullable : une facture dont le tenant n'a pas pu être résolu est conservée
            // malgré tout. Aucune perte, jamais de 5xx renvoyé à la plateforme.
            $table->uuid('tenant_id')->nullable();

            $table->string('provider');
            $table->string('provider_invoice_id');

            $table->string('invoice_number')->nullable();
            $table->date('invoice_date')->nullable();

            $table->string('sender_siren', 9)->nullable();
            $table->string('sender_siret', 14)->nullable();
            $table->string('sender_name')->nullable();

            $table->decimal('amount_total', 15, 2)->nullable();
            $table->decimal('amount_tax', 15, 2)->nullable();
            $table->string('currency', 3)->nullable();

            $table->string('format')->nullable();

            // Miroir local de markAsSeen côté plateforme.
            $table->timestamp('seen_at')->nullable();

            $table->json('raw_metadata')->nullable();
            $table->timestamps();

            // Clé d'idempotence : un retour de la plateforme sur la même facture met à jour
            // la ligne existante au lieu d'en créer une seconde.
            $table->unique(['provider', 'provider_invoice_id']);

            $table->index(['tenant_id', 'seen_at']);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('einvoicing_tenants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoicing_inbound_invoices');
    }
};
