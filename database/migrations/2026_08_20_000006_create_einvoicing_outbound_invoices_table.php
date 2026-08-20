<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('einvoicing_outbound_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('tenant_id');
            $table->string('provider');

            // Null until the platform accepts the document and names it. A send
            // that failed is kept all the same: knowing what was refused, and
            // why, matters more than a tidy table.
            $table->string('provider_invoice_id')->nullable();

            // SHA-256 of the file sent. The send endpoint takes no idempotency
            // key, so this is what tells a retry from a genuine second invoice.
            $table->string('file_hash', 64);
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');

            $table->string('status');
            $table->text('failure_reason')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // Sending the same document twice for one tenant is a retry, never a
            // second invoice. The constraint carries it, not a read-then-write.
            $table->unique(['tenant_id', 'file_hash']);

            $table->index(['provider', 'provider_invoice_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoicing_outbound_invoices');
    }
};
