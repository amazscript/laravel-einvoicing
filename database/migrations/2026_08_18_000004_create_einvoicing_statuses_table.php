<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('einvoicing_statuses', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Nullable : un statut peut arriver avant la facture qu'il concerne, ou porter
            // sur une facture que le package ne connaît pas. Il est conservé quand même.
            $table->uuid('invoice_id')->nullable();

            $table->string('provider');
            $table->string('provider_status_id');

            $table->string('code');
            // Constaté sur un statut réel : seul `code` est systématiquement présent.
            // La documentation montre un trio code/value/desc, la plateforme n'en
            // envoie parfois qu'un tiers.
            $table->string('value')->nullable();
            $table->text('description')->nullable();
            $table->string('dest_type')->nullable();

            $table->timestamp('occurred_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_status_id']);
            $table->index('invoice_id');

            $table->foreign('invoice_id')
                ->references('id')
                ->on('einvoicing_inbound_invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoicing_statuses');
    }
};
