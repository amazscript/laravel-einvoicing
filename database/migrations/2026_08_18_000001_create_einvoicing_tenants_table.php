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

            // The host model's key type is unknown to the package: auto-increment
            // integer, uuid or ulid depending on the application. The identifier
            // is therefore stored as a string.
            $table->string('tenantable_type');
            $table->string('tenantable_id');

            // Encrypted by the model on read and write: the customer-id must never
            // appear in clear, neither in the database nor in the logs.
            $table->text('customer_id');

            // The webhook's routing keys. SIRET takes precedence over SIREN.
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
