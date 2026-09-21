<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('replacement_evidence')) {
            return;
        }

        Schema::create('replacement_evidence', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('replacement_module_id');
            $table->string('evidence_type', 40);
            $table->string('evidence_key', 120);
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 40)->default('pending');
            $table->boolean('required')->default(true);
            $table->string('checksum', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_by', 190)->nullable();
            $table->timestamps();

            $table->unique(['replacement_module_id', 'evidence_type', 'evidence_key', 'version'], 'replacement_evidence_version_uq');
            $table->index(['replacement_module_id', 'required', 'status'], 'replacement_evidence_gate_idx');
            $table->foreign('tenant_id', 'replacement_evidence_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('replacement_module_id', 'replacement_evidence_module_fk')->references('id')->on('replacement_modules')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replacement_evidence');
    }
};
