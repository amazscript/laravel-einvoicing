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

            // Nullable: a status may arrive before the invoice it concerns, or
            // refer to one the package does not know. It is kept regardless.
            $table->uuid('invoice_id')->nullable();

            $table->string('provider');
            $table->string('provider_status_id');

            $table->string('code');
            // Observed on a real status: only `code` is always present. The
            // documentation shows a code/value/desc triple, of which the platform
            // sometimes sends a third.
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
