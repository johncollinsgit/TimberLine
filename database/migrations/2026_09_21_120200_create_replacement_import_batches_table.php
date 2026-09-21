<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('replacement_import_batches')) {
            return;
        }

        Schema::create('replacement_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('replacement_module_id');
            $table->unsignedBigInteger('replacement_source_snapshot_id')->nullable();
            $table->string('idempotency_key', 120);
            $table->string('mode', 24)->default('import');
            $table->string('status', 40)->default('pending');
            $table->unsignedInteger('source_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->json('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['replacement_module_id', 'idempotency_key'], 'replacement_imports_module_key_uq');
            $table->index(['tenant_id', 'status'], 'replacement_imports_tenant_status_idx');
            $table->foreign('tenant_id', 'replacement_imports_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('replacement_module_id', 'replacement_imports_module_fk')->references('id')->on('replacement_modules')->cascadeOnDelete();
            $table->foreign('replacement_source_snapshot_id', 'replacement_imports_snapshot_fk')->references('id')->on('replacement_source_snapshots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replacement_import_batches');
    }
};
