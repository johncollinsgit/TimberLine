<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_service_task_assignees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('field_service_task_id')->constrained('field_service_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'field_service_task_id', 'user_id'], 'fs_task_assignees_unique');
            $table->index(['tenant_id', 'user_id', 'field_service_task_id'], 'fs_task_assignees_user_idx');
        });

        Schema::create('field_service_task_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('field_service_task_id')->constrained('field_service_tasks')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40)->index();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->text('note')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key'], 'fs_task_events_idempotency_unique');
            $table->index(['tenant_id', 'field_service_task_id', 'created_at'], 'fs_task_events_task_idx');
        });

        DB::table('field_service_tasks')
            ->whereNotNull('assigned_user_id')
            ->orderBy('id')
            ->chunkById(500, function ($tasks): void {
                $now = now();
                DB::table('field_service_task_assignees')->insertOrIgnore($tasks->map(fn ($task): array => [
                    'tenant_id' => (int) $task->tenant_id,
                    'field_service_task_id' => (int) $task->id,
                    'user_id' => (int) $task->assigned_user_id,
                    'assigned_by_user_id' => $task->created_by_user_id ? (int) $task->created_by_user_id : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_service_task_events');
        Schema::dropIfExists('field_service_task_assignees');
    }
};
