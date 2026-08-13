<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_service_jobs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('dispatch_duration_minutes')->nullable()->after('scheduled_end_at');
            $table->timestamp('arrival_window_starts_at')->nullable()->after('dispatch_duration_minutes');
            $table->timestamp('arrival_window_ends_at')->nullable()->after('arrival_window_starts_at');
            $table->json('dispatch_requirements')->nullable()->after('arrival_window_ends_at');
            $table->index(['tenant_id', 'assigned_user_id', 'scheduled_for'], 'fsj_dispatch_schedule_idx');
        });

        Schema::create('field_service_dispatch_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->json('business_hours')->nullable();
            $table->unsignedSmallInteger('default_travel_buffer_minutes')->default(15);
            $table->json('customer_notification_settings')->nullable();
            $table->json('escalation_settings')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id', 'fsds_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('field_service_service_areas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->json('postal_prefixes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name'], 'fssa_tenant_name_uq');
            $table->foreign('tenant_id', 'fssa_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('field_service_technician_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->json('skills')->nullable();
            $table->unsignedSmallInteger('daily_capacity_minutes')->default(480);
            $table->json('service_area_ids')->nullable();
            $table->json('vehicle_ids')->nullable();
            $table->boolean('dispatch_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id'], 'fstp_tenant_user_uq');
            $table->foreign('tenant_id', 'fstp_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id', 'fstp_user_fk')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('field_service_availability_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'starts_at'], 'fsae_tenant_user_idx');
            $table->foreign('tenant_id', 'fsae_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id', 'fsae_user_fk')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('field_service_dispatch_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('field_service_job_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('event_type', 80);
            $table->json('before');
            $table->json('after');
            $table->json('explanation')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'field_service_job_id', 'created_at'], 'fsde_tenant_job_idx');
            $table->foreign('tenant_id', 'fsde_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('field_service_job_id', 'fsde_job_fk')->references('id')->on('field_service_jobs')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'fsde_actor_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_service_dispatch_events');
        Schema::dropIfExists('field_service_availability_exceptions');
        Schema::dropIfExists('field_service_technician_profiles');
        Schema::dropIfExists('field_service_service_areas');
        Schema::dropIfExists('field_service_dispatch_settings');
        Schema::table('field_service_jobs', function (Blueprint $table): void {
            $table->dropIndex('fsj_dispatch_schedule_idx');
            $table->dropColumn(['dispatch_duration_minutes', 'arrival_window_starts_at', 'arrival_window_ends_at', 'dispatch_requirements']);
        });
    }
};
