<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_project_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_project_id')->constrained('client_projects')->cascadeOnDelete();
            $table->foreignId('client_project_phase_id')->nullable()->constrained('client_project_phases')->nullOnDelete();
            $table->foreignId('client_project_milestone_id')->nullable()->constrained('client_project_milestones')->nullOnDelete();
            $table->foreignId('custom_module_request_id')->nullable()->constrained('custom_module_requests')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 80)->default('feature');
            $table->string('title', 190);
            $table->text('problem_summary');
            $table->text('desired_outcome')->nullable();
            $table->text('scope_notes')->nullable();
            $table->string('urgency', 80)->default('normal');
            $table->string('priority', 80)->default('normal');
            $table->string('status', 80)->default('new');
            $table->boolean('customer_visible')->default(true);
            $table->text('landlord_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'client_project_id', 'status'], 'cp_tickets_scope_status_idx');
            $table->index(['tenant_id', 'status', 'priority'], 'cp_tickets_status_priority_idx');
        });

        Schema::create('client_project_ticket_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_project_ticket_id')->constrained('client_project_tickets')->cascadeOnDelete();
            $table->foreignId('client_project_phase_id')->nullable()->constrained('client_project_phases')->nullOnDelete();
            $table->string('title', 190);
            $table->text('details')->nullable();
            $table->string('owner_type', 80)->default('evergrove');
            $table->string('status', 80)->default('open');
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'client_project_ticket_id', 'sort_order'], 'cp_ticket_tasks_scope_sort_idx');
        });

        Schema::create('client_project_ticket_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('client_project_ticket_id');
            $table->string('label', 190);
            $table->string('url', 500)->nullable();
            $table->string('reference_type', 80)->default('link');
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('client_project_ticket_id', 'cp_ticket_refs_ticket_fk')
                ->references('id')
                ->on('client_project_tickets')
                ->cascadeOnDelete();
            $table->index(['tenant_id', 'client_project_ticket_id', 'sort_order'], 'cp_ticket_refs_scope_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_project_ticket_references');
        Schema::dropIfExists('client_project_ticket_tasks');
        Schema::dropIfExists('client_project_tickets');
    }
};
