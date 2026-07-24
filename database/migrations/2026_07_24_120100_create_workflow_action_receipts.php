<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_workflow_action_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('automation_workflow_id');
            $table->unsignedBigInteger('automation_workflow_version_id');
            $table->unsignedBigInteger('automation_workflow_run_item_id');
            $table->string('step_id', 100);
            $table->string('component_key', 120);
            $table->string('idempotency_key', 128);
            $table->string('payload_hash', 64);
            $table->string('status', 30)->default('dispatching');
            $table->string('target_type', 100)->nullable();
            $table->string('target_id', 191)->nullable();
            $table->longText('result')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamp('reserved_at');
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id', 'awar_tenant_fk')
                ->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('automation_workflow_id', 'awar_workflow_fk')
                ->references('id')->on('automation_workflows')->cascadeOnDelete();
            $table->foreign('automation_workflow_version_id', 'awar_version_fk')
                ->references('id')->on('automation_workflow_versions')->cascadeOnDelete();
            $table->foreign('automation_workflow_run_item_id', 'awar_item_fk')
                ->references('id')->on('automation_workflow_run_items')->cascadeOnDelete();

            $table->unique('idempotency_key', 'awar_idempotency_uq');
            $table->index(
                ['tenant_id', 'automation_workflow_id', 'status'],
                'awar_tenant_workflow_status_idx'
            );
            $table->index(
                ['automation_workflow_run_item_id', 'step_id'],
                'awar_item_step_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_workflow_action_receipts');
    }
};
