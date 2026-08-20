<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('einvoicing_statuses', function (Blueprint $table): void {
            // A status belongs to a received invoice or to a sent one, never
            // both. Two nullable columns rather than a polymorphic pair: which
            // side a status is on is the first thing anyone reads off the row,
            // and a type string would hide it.
            $table->uuid('outbound_invoice_id')->nullable()->after('invoice_id');
            $table->index('outbound_invoice_id');

            $table->foreign('outbound_invoice_id')
                ->references('id')
                ->on('einvoicing_outbound_invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('einvoicing_statuses', function (Blueprint $table): void {
            $table->dropForeign(['outbound_invoice_id']);
            $table->dropIndex(['outbound_invoice_id']);
            $table->dropColumn('outbound_invoice_id');
        });
    }
};
