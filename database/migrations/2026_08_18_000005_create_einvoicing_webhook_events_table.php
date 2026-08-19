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

            // Idempotency key. The constraint is enforced by the database: a
            // violation means "already received", which is a success, not an error.
            $table->string('event_id')->unique();

            $table->string('event_type');
            $table->uuid('tenant_id')->nullable();
            $table->string('status');

            // The complete raw payload: the only material allowing an event to be
            // replayed after a failed processing or an unresolved tenant.
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
