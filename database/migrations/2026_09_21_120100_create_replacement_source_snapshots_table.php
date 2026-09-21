<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('replacement_source_snapshots')) {
            return;
        }

        Schema::create('replacement_source_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('replacement_module_id');
            $table->string('snapshot_type', 60);
            $table->string('source_reference', 255);
            $table->string('checksum', 64);
            $table->unsignedInteger('record_count')->default(0);
            $table->json('payload')->nullable();
            $table->json('provenance')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(['replacement_module_id', 'checksum'], 'replacement_snapshots_module_checksum_uq');
            $table->index(['tenant_id', 'captured_at'], 'replacement_snapshots_tenant_captured_idx');
            $table->foreign('tenant_id', 'replacement_snapshots_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('replacement_module_id', 'replacement_snapshots_module_fk')->references('id')->on('replacement_modules')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replacement_source_snapshots');
    }
};
