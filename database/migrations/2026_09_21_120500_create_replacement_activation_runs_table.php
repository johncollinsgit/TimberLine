<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('replacement_activation_runs')) {
            return;
        }

        Schema::create('replacement_activation_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('replacement_module_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_reference', 190)->nullable();
            $table->string('idempotency_key', 120);
            $table->string('operation', 40)->default('activate');
            $table->string('status', 40)->default('pending');
            $table->json('before_payload')->nullable();
            $table->json('after_payload')->nullable();
            $table->json('rollback_payload')->nullable();
            $table->json('smoke_test_results')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['replacement_module_id', 'idempotency_key'], 'replacement_activations_module_key_uq');
            $table->index(['tenant_id', 'status'], 'replacement_activations_tenant_status_idx');
            $table->foreign('tenant_id', 'replacement_activations_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('replacement_module_id', 'replacement_activations_module_fk')->references('id')->on('replacement_modules')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'replacement_activations_actor_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replacement_activation_runs');
    }
};
