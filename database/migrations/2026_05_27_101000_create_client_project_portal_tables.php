<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('title', 190);
            $table->text('summary')->nullable();
            $table->string('status', 80)->default('planning');
            $table->string('health', 80)->default('on_track');
            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'sort_order'], 'client_projects_tenant_status_sort_idx');
            $table->index(['tenant_id', 'due_on'], 'client_projects_tenant_due_idx');
        });

        Schema::create('client_project_phases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_project_id')->constrained('client_projects')->cascadeOnDelete();
            $table->string('name', 190);
            $table->text('summary')->nullable();
            $table->string('status', 80)->default('not_started');
            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedTinyInteger('percent_complete')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'client_project_id', 'sort_order'], 'client_project_phases_scope_sort_idx');
        });

        Schema::create('client_project_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_project_id')->constrained('client_projects')->cascadeOnDelete();
            $table->foreignId('client_project_phase_id')->nullable()->constrained('client_project_phases')->nullOnDelete();
            $table->string('title', 190);
            $table->text('summary')->nullable();
            $table->string('status', 80)->default('upcoming');
            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'client_project_id', 'due_on'], 'client_project_milestones_scope_due_idx');
        });

        Schema::create('client_project_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_project_id')->constrained('client_projects')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 190);
            $table->text('body')->nullable();
            $table->string('visibility', 80)->default('client');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'client_project_id', 'published_at'], 'client_project_updates_scope_published_idx');
        });

        Schema::create('client_project_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_project_id')->constrained('client_projects')->cascadeOnDelete();
            $table->string('label', 190);
            $table->string('url', 500);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'client_project_id', 'sort_order'], 'client_project_links_scope_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_project_links');
        Schema::dropIfExists('client_project_updates');
        Schema::dropIfExists('client_project_milestones');
        Schema::dropIfExists('client_project_phases');
        Schema::dropIfExists('client_projects');
    }
};
