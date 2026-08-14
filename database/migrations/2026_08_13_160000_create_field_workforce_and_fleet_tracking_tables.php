<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_workforce_settings')) {
            Schema::create('tenant_workforce_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id');
                $table->boolean('enforce_scheduled_clocking')->default(false);
                $table->unsignedSmallInteger('clock_early_minutes')->default(15);
                $table->unsignedSmallInteger('clock_late_minutes')->default(15);
                $table->foreignId('updated_by_user_id')->nullable();
                $table->timestamps();
                $table->unique('tenant_id', 'tws_tenant_unique');
                $table->foreign('tenant_id', 'tws_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('updated_by_user_id', 'tws_updated_by_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('field_service_work_shifts')) {
            Schema::create('field_service_work_shifts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id');
                $table->foreignId('user_id');
                $table->foreignId('field_service_job_id')->nullable();
                $table->foreignId('created_by_user_id')->nullable();
                $table->string('status', 24)->default('scheduled');
                $table->timestamp('starts_at');
                $table->timestamp('ends_at');
                $table->unsignedSmallInteger('unpaid_break_minutes')->default(0);
                $table->text('notes')->nullable();
                $table->timestamp('canceled_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'user_id', 'starts_at'], 'fs_shift_tenant_user_start_idx');
                $table->index(['tenant_id', 'status', 'starts_at'], 'fs_shift_tenant_status_start_idx');
                $table->foreign('tenant_id', 'fs_shift_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('user_id', 'fs_shift_user_fk')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('field_service_job_id', 'fs_shift_job_fk')->references('id')->on('field_service_jobs')->nullOnDelete();
                $table->foreign('created_by_user_id', 'fs_shift_created_by_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('field_service_time_change_requests')) {
            Schema::create('field_service_time_change_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id');
                $table->foreignId('field_service_time_session_id')->nullable();
                $table->foreignId('field_service_time_entry_id')->nullable();
                $table->foreignId('requested_by_user_id');
                $table->foreignId('reviewed_by_user_id')->nullable();
                $table->string('status', 24)->default('pending');
                $table->json('before_snapshot');
                $table->json('requested_snapshot');
                $table->json('resolution_snapshot')->nullable();
                $table->text('reason');
                $table->text('reviewer_note')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'status', 'created_at'], 'fs_time_change_tenant_status_idx');
                $table->index(['tenant_id', 'requested_by_user_id', 'created_at'], 'fs_time_change_requester_idx');
                $table->foreign('tenant_id', 'fs_time_change_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('field_service_time_session_id', 'fs_time_change_session_fk')->references('id')->on('field_service_time_sessions')->cascadeOnDelete();
                $table->foreign('field_service_time_entry_id', 'fs_time_change_entry_fk')->references('id')->on('field_service_time_entries')->cascadeOnDelete();
                $table->foreign('requested_by_user_id', 'fs_time_change_requester_fk')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('reviewed_by_user_id', 'fs_time_change_reviewer_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('tenant_fleet_tracking_settings')) {
            Schema::create('tenant_fleet_tracking_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id');
                $table->boolean('phone_tracking_enabled')->default(false);
                $table->boolean('bouncie_tracking_enabled')->default(false);
                $table->string('policy_version', 80)->nullable();
                $table->string('policy_sha256', 64)->nullable();
                $table->string('counsel_review_reference', 500)->nullable();
                $table->timestamp('legal_reviewed_at')->nullable();
                $table->foreignId('legal_reviewed_by_user_id')->nullable();
                $table->unsignedSmallInteger('retention_days')->default(30);
                $table->timestamps();
                $table->unique('tenant_id', 'ft_setting_tenant_unique');
                $table->foreign('tenant_id', 'ft_setting_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('legal_reviewed_by_user_id', 'ft_setting_legal_by_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('fleet_tracking_devices')) {
            Schema::create('fleet_tracking_devices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id');
                $table->foreignId('field_service_vehicle_id');
                $table->string('provider', 40)->default('bouncie');
                $table->string('external_device_id', 160);
                $table->string('label', 160)->nullable();
                $table->string('status', 24)->default('active');
                $table->timestamp('installed_at')->nullable();
                $table->timestamp('uninstalled_at')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'provider', 'external_device_id'], 'ft_device_provider_unique');
                $table->unique(['tenant_id', 'field_service_vehicle_id'], 'ft_device_vehicle_unique');
                $table->foreign('tenant_id', 'ft_device_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('field_service_vehicle_id', 'ft_device_vehicle_fk')->references('id')->on('field_service_vehicles')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('fleet_tracking_policy_acknowledgements')) {
            Schema::create('fleet_tracking_policy_acknowledgements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id');
                $table->foreignId('user_id');
                $table->string('policy_version', 80);
                $table->string('policy_sha256', 64);
                $table->timestamp('accepted_at');
                $table->string('acceptance_source', 40)->default('mobile');
                $table->json('device_context')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'user_id', 'policy_version'], 'ft_ack_policy_unique');
                $table->foreign('tenant_id', 'ft_ack_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('user_id', 'ft_ack_user_fk')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('fleet_location_points')) {
            Schema::create('fleet_location_points', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id');
                $table->foreignId('fleet_tracking_device_id')->nullable();
                $table->foreignId('field_service_vehicle_id')->nullable();
                $table->foreignId('user_id')->nullable();
                $table->foreignId('field_service_time_session_id')->nullable();
                $table->string('source', 24);
                $table->string('event_key', 64);
                $table->string('event_type', 80)->nullable();
                $table->decimal('latitude', 10, 7);
                $table->decimal('longitude', 10, 7);
                $table->unsignedInteger('accuracy_meters')->nullable();
                $table->timestamp('recorded_at');
                $table->timestamp('received_at');
                $table->json('safe_payload')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'source', 'event_key'], 'ft_point_event_unique');
                $table->index(['tenant_id', 'recorded_at'], 'ft_point_tenant_time_idx');
                $table->index(['tenant_id', 'field_service_vehicle_id', 'recorded_at'], 'ft_point_vehicle_time_idx');
                $table->index(['tenant_id', 'user_id', 'recorded_at'], 'ft_point_user_time_idx');
                $table->foreign('tenant_id', 'ft_point_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('fleet_tracking_device_id', 'ft_point_device_fk')->references('id')->on('fleet_tracking_devices')->nullOnDelete();
                $table->foreign('field_service_vehicle_id', 'ft_point_vehicle_fk')->references('id')->on('field_service_vehicles')->nullOnDelete();
                $table->foreign('user_id', 'ft_point_user_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('field_service_time_session_id', 'ft_point_session_fk')->references('id')->on('field_service_time_sessions')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_location_points');
        Schema::dropIfExists('fleet_tracking_policy_acknowledgements');
        Schema::dropIfExists('fleet_tracking_devices');
        Schema::dropIfExists('tenant_fleet_tracking_settings');
        Schema::dropIfExists('field_service_time_change_requests');
        Schema::dropIfExists('field_service_work_shifts');
        Schema::dropIfExists('tenant_workforce_settings');
    }
};
