<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('team_work_locks')) {
            Schema::create('team_work_locks', function (Blueprint $table): void {
                $table->unsignedBigInteger('tenant_id')->primary();
            });
        }
        if (! Schema::hasTable('team_projects')) {
            Schema::create('team_projects', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('owner_user_id');
                $table->string('name', 500);
                $table->string('privacy', 20);
                $table->boolean('archived')->default(false);
                $table->unsignedInteger('version');
                $table->json('payload');
                $table->index(['tenant_id', 'archived'], 'team_project_tenant_archive_idx');
            });
        }
        if (! Schema::hasTable('team_tasks')) {
            Schema::create('team_tasks', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('assigned_user_id')->nullable();
                $table->uuid('parent_id')->nullable();
                $table->string('title', 500);
                $table->date('due_on')->nullable();
                $table->dateTime('due_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->boolean('deleted')->default(false);
                $table->unsignedInteger('version');
                $table->json('payload');
                $table->index(['tenant_id', 'assigned_user_id', 'deleted'], 'team_task_assignment_idx');
                $table->index(['tenant_id', 'parent_id'], 'team_task_parent_idx');
            });
        }
        if (! Schema::hasTable('team_project_members')) {
            Schema::create('team_project_members', function (Blueprint $table): void {
                $table->unsignedBigInteger('tenant_id');
                $table->uuid('project_id');
                $table->unsignedBigInteger('user_id');
                $table->string('access', 20);
                $table->primary(['tenant_id', 'project_id', 'user_id'], 'team_project_member_pk');
            });
        }
        if (! Schema::hasTable('team_task_projects')) {
            Schema::create('team_task_projects', function (Blueprint $table): void {
                $table->unsignedBigInteger('tenant_id');
                $table->uuid('task_id');
                $table->uuid('project_id');
                $table->string('section_id', 120)->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->primary(['tenant_id', 'task_id', 'project_id'], 'team_task_project_pk');
                $table->index(['tenant_id', 'project_id', 'position'], 'team_project_order_idx');
            });
        }
        if (! Schema::hasTable('team_work_events')) {
            Schema::create('team_work_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('entity_type', 20);
                $table->uuid('entity_id');
                $table->json('payload');
                $table->index(['tenant_id', 'entity_type', 'entity_id'], 'team_work_event_entity_idx');
            });
        }
        if (! Schema::hasTable('team_work_receipts')) {
            Schema::create('team_work_receipts', function (Blueprint $table): void {
                $table->unsignedBigInteger('tenant_id');
                $table->char('receipt_hash', 64);
                $table->text('receipt_key');
                $table->json('payload');
                $table->primary(['tenant_id', 'receipt_hash'], 'team_work_receipt_pk');
            });
        }
        // MySQL may retain CREATE TABLE before later key/index statements fail.
        foreach ([
            ['team_work_locks', ['tenant_id'], 'primary', 'primary'],
            ['team_projects', ['id'], 'primary', 'primary'],
            ['team_projects', ['tenant_id', 'archived'], 'team_project_tenant_archive_idx', 'index'],
            ['team_tasks', ['id'], 'primary', 'primary'],
            ['team_tasks', ['tenant_id', 'assigned_user_id', 'deleted'], 'team_task_assignment_idx', 'index'],
            ['team_tasks', ['tenant_id', 'parent_id'], 'team_task_parent_idx', 'index'],
            ['team_project_members', ['tenant_id', 'project_id', 'user_id'], 'team_project_member_pk', 'primary'],
            ['team_task_projects', ['tenant_id', 'task_id', 'project_id'], 'team_task_project_pk', 'primary'],
            ['team_task_projects', ['tenant_id', 'project_id', 'position'], 'team_project_order_idx', 'index'],
            ['team_work_events', ['tenant_id', 'entity_type', 'entity_id'], 'team_work_event_entity_idx', 'index'],
            ['team_work_receipts', ['tenant_id', 'receipt_hash'], 'team_work_receipt_pk', 'primary'],
        ] as [$name, $columns, $key, $type]) {
            if (! Schema::hasIndex($name, $type === 'primary' ? $columns : $key, $type === 'primary' ? 'primary' : null)) {
                Schema::table($name, fn (Blueprint $table) => $type === 'primary' ? $table->primary($columns, $key) : $table->index($columns, $key));
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: work/history/receipts survive rollback of application code.
    }
};
