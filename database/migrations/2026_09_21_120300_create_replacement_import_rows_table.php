<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('replacement_import_rows')) {
            return;
        }

        Schema::create('replacement_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('replacement_import_batch_id');
            $table->unsignedInteger('row_number');
            $table->string('source_key', 190);
            $table->string('status', 40)->default('pending');
            $table->string('target_type', 120)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('messages')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['replacement_import_batch_id', 'source_key'], 'replacement_import_rows_source_uq');
            $table->index(['tenant_id', 'status'], 'replacement_import_rows_tenant_status_idx');
            $table->foreign('tenant_id', 'replacement_import_rows_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('replacement_import_batch_id', 'replacement_import_rows_batch_fk')->references('id')->on('replacement_import_batches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replacement_import_rows');
    }
};
