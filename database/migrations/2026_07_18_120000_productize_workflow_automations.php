<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_workflows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('template_key', 100);
            $table->string('name', 160);
            $table->string('status', 30)->default('draft');
            $table->json('draft_definition');
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->json('test_state')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'template_key']);
        });

        Schema::create('automation_workflow_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_workflow_id')->constrained('automation_workflows')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('definition_hash', 64);
            $table->json('definition');
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['automation_workflow_id', 'version'], 'automation_workflow_versions_unique');
            $table->index(['tenant_id', 'published_at']);
        });

        Schema::table('automation_workflows', function (Blueprint $table): void {
            $table->foreign('published_version_id', 'automation_workflows_published_version_fk')
                ->references('id')
                ->on('automation_workflow_versions')
                ->nullOnDelete();
        });

        Schema::create('automation_workflow_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_workflow_id')->constrained('automation_workflows')->cascadeOnDelete();
            $table->foreignId('automation_workflow_version_id')->nullable()->constrained('automation_workflow_versions')->nullOnDelete();
            $table->string('mode', 20)->default('scheduled');
            $table->string('status', 30)->default('queued');
            $table->json('counts')->nullable();
            $table->json('context')->nullable();
            $table->text('error_summary')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at'], 'automation_workflow_runs_tenant_status_idx');
            $table->index(['automation_workflow_id', 'created_at'], 'automation_workflow_runs_workflow_idx');
            $table->unique(['automation_workflow_id', 'idempotency_key'], 'automation_workflow_runs_idempotency_unique');
        });

        Schema::create('automation_workflow_run_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_workflow_run_id')->constrained('automation_workflow_runs')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('step_key', 100);
            $table->string('provider', 60);
            $table->string('kind', 20);
            $table->string('status', 30);
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['automation_workflow_run_id', 'position'], 'automation_run_steps_position_idx');
        });

        Schema::create('automation_workflow_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_workflow_id')->nullable()->constrained('automation_workflows')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 80);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'event_type', 'occurred_at'], 'automation_audit_tenant_type_idx');
        });

        Schema::table('automation_workflow_states', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('automation_workflow_id')->nullable()->after('tenant_id')->constrained('automation_workflows')->nullOnDelete();
            $table->unique('automation_workflow_id', 'automation_states_workflow_unique');
            $table->index(['tenant_id', 'automation_workflow_id'], 'automation_states_tenant_workflow_idx');
        });

        Schema::table('automation_workflow_links', function (Blueprint $table): void {
            $table->dropUnique('automation_workflow_links_source_unique');
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('automation_workflow_id')->nullable()->after('tenant_id')->constrained('automation_workflows')->nullOnDelete();
            $table->unique(['automation_workflow_id', 'source_system', 'source_id'], 'automation_links_workflow_source_unique');
            $table->index(['tenant_id', 'automation_workflow_id'], 'automation_links_tenant_workflow_idx');
        });
    }

    public function down(): void
    {
        Schema::table('automation_workflow_links', function (Blueprint $table): void {
            $table->dropUnique('automation_links_workflow_source_unique');
            $table->dropIndex('automation_links_tenant_workflow_idx');
            $table->dropConstrainedForeignId('automation_workflow_id');
            $table->dropConstrainedForeignId('tenant_id');
            $table->unique(['workflow_key', 'source_system', 'source_id'], 'automation_workflow_links_source_unique');
        });
        Schema::table('automation_workflow_states', function (Blueprint $table): void {
            $table->dropUnique('automation_states_workflow_unique');
            $table->dropIndex('automation_states_tenant_workflow_idx');
            $table->dropConstrainedForeignId('automation_workflow_id');
            $table->dropConstrainedForeignId('tenant_id');
        });
        Schema::dropIfExists('automation_workflow_audit_events');
        Schema::dropIfExists('automation_workflow_run_steps');
        Schema::dropIfExists('automation_workflow_runs');
        Schema::table('automation_workflows', function (Blueprint $table): void {
            $table->dropForeign('automation_workflows_published_version_fk');
        });
        Schema::dropIfExists('automation_workflow_versions');
        Schema::dropIfExists('automation_workflows');
    }
};
