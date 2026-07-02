<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_service_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('marketing_profile_id')->nullable()->constrained('marketing_profiles')->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('status', 40)->default('open')->index();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable()->index();
            $table->string('customer_phone')->nullable();
            $table->string('service_address_line_1')->nullable();
            $table->string('service_address_line_2')->nullable();
            $table->string('service_city')->nullable();
            $table->string('service_state', 80)->nullable();
            $table->string('service_postal_code', 40)->nullable();
            $table->string('service_country', 80)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'scheduled_for'], 'fs_jobs_tenant_status_schedule_idx');
            $table->index(['tenant_id', 'marketing_profile_id'], 'fs_jobs_tenant_profile_idx');
        });

        Schema::create('field_service_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('field_service_job_id')->constrained('field_service_jobs')->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('status', 40)->default('open')->index();
            $table->timestamp('due_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'due_at'], 'fs_tasks_tenant_status_due_idx');
        });

        Schema::create('field_service_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('field_service_job_id')->nullable()->constrained('field_service_jobs')->nullOnDelete();
            $table->string('name');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 40)->nullable();
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->string('status', 40)->default('needed')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status'], 'fs_materials_tenant_status_idx');
        });

        Schema::create('field_service_job_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('field_service_job_id')->constrained('field_service_jobs')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('caption')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'field_service_job_id'], 'fs_photos_tenant_job_idx');
        });

        Schema::create('field_service_vehicles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('identifier')->nullable();
            $table->string('status', 40)->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status'], 'fs_vehicles_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_service_vehicles');
        Schema::dropIfExists('field_service_job_photos');
        Schema::dropIfExists('field_service_materials');
        Schema::dropIfExists('field_service_tasks');
        Schema::dropIfExists('field_service_jobs');
    }
};
