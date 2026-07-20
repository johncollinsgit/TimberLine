<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_equipment', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('marketing_profile_id')->constrained('marketing_profiles')->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('equipment_type', 80)->default('generator');
            $table->string('name');
            $table->string('manufacturer')->nullable();
            $table->string('model_number')->nullable();
            $table->string('serial_number')->nullable();
            $table->date('installed_at')->nullable();
            $table->unsignedInteger('maintenance_interval_days')->default(365);
            $table->date('last_serviced_at')->nullable();
            $table->date('next_service_due_at')->nullable()->index();
            $table->string('status', 40)->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'marketing_profile_id'], 'equipment_tenant_customer_idx');
            $table->index(['tenant_id', 'status', 'next_service_due_at'], 'equipment_tenant_due_idx');
        });

        Schema::table('field_service_jobs', function (Blueprint $table): void {
            $table->foreignId('customer_equipment_id')->nullable()->after('marketing_profile_id')->constrained('customer_equipment')->nullOnDelete();
            $table->index(['tenant_id', 'customer_equipment_id', 'completed_at'], 'fs_jobs_tenant_equipment_idx');
        });

        Schema::create('field_service_time_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('field_service_job_id')->nullable()->constrained('field_service_jobs')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('work_date');
            $table->time('started_at');
            $table->time('ended_at');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedInteger('duration_minutes');
            $table->string('status', 30)->default('submitted')->index();
            $table->text('notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'work_date', 'status'], 'fs_time_tenant_date_status_idx');
            $table->index(['tenant_id', 'user_id', 'work_date'], 'fs_time_tenant_user_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_service_time_entries');
        Schema::table('field_service_jobs', function (Blueprint $table): void {
            $table->dropIndex('fs_jobs_tenant_equipment_idx');
            $table->dropConstrainedForeignId('customer_equipment_id');
        });
        Schema::dropIfExists('customer_equipment');
    }
};
