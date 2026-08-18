<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('einvoicing_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Clé d'idempotence. La contrainte est portée par la base : une violation
            // signifie « déjà reçu », ce qui est un succès, pas une erreur.
            $table->string('event_id')->unique();

            $table->string('event_type');
            $table->uuid('tenant_id')->nullable();
            $table->string('status');

            // Payload brut intégral : seule source permettant de rejouer un événement
            // dont le traitement a échoué ou dont le tenant n'a pas été résolu.
            $table->json('payload')->nullable();

            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('failed_reason')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('received_at');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('einvoicing_tenants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoicing_webhook_events');
    }
};
