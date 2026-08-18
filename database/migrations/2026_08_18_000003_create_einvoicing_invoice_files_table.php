<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('einvoicing_invoice_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');

            $table->string('provider_file_id')->nullable();
            $table->string('kind');

            $table->string('disk');
            $table->string('path');
            $table->string('checksum', 64);

            $table->timestamps();

            // Un re-téléchargement du même contenu ne doit pas créer un second fichier.
            $table->unique(['invoice_id', 'kind', 'checksum']);

            $table->foreign('invoice_id')
                ->references('id')
                ->on('einvoicing_inbound_invoices')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoicing_invoice_files');
    }
};
