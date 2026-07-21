<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->boolean('membership_active')->default(true)->after('role')->index();
        });

        Schema::create('field_service_work_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('field_service_financial_document_id')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable();
            $table->foreignId('converted_job_id')->nullable();
            $table->string('source', 40)->default('quickbooks');
            $table->string('source_type', 40);
            $table->string('external_id', 180);
            $table->string('status', 30)->default('pending');
            $table->string('title')->nullable();
            $table->string('customer_name')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->decimal('balance', 14, 2)->nullable();
            $table->longText('description')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'source', 'source_type', 'external_id'], 'fs_candidate_source_unique');
            $table->index(['tenant_id', 'status', 'updated_at'], 'fs_candidate_status_idx');
            $table->foreign('tenant_id', 'fs_candidate_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('field_service_financial_document_id', 'fs_candidate_doc_fk')->references('id')->on('field_service_financial_documents')->nullOnDelete();
            $table->foreign('reviewed_by_user_id', 'fs_candidate_reviewer_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('converted_job_id', 'fs_candidate_job_fk')->references('id')->on('field_service_jobs')->nullOnDelete();
        });

        Schema::create('field_service_job_vehicle_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('field_service_job_id');
            $table->foreignId('field_service_vehicle_id');
            $table->foreignId('assigned_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['field_service_job_id', 'field_service_vehicle_id'], 'fs_job_vehicle_unique');
            $table->index(['tenant_id', 'field_service_vehicle_id'], 'fs_job_vehicle_tenant_idx');
            $table->foreign('tenant_id', 'fs_job_vehicle_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('field_service_job_id', 'fs_job_vehicle_job_fk')->references('id')->on('field_service_jobs')->cascadeOnDelete();
            $table->foreign('field_service_vehicle_id', 'fs_job_vehicle_vehicle_fk')->references('id')->on('field_service_vehicles')->cascadeOnDelete();
            $table->foreign('assigned_by_user_id', 'fs_job_vehicle_actor_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('field_service_time_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('field_service_job_id');
            $table->foreignId('user_id');
            $table->foreignId('reviewed_by_user_id')->nullable();
            $table->uuid('client_uuid');
            $table->unsignedBigInteger('active_user_key')->nullable();
            $table->string('status', 30)->default('running');
            $table->timestamp('clocked_in_at');
            $table->timestamp('clocked_out_at')->nullable();
            $table->unsignedInteger('break_seconds')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('clock_out_notes')->nullable();
            $table->string('source', 30)->default('mobile');
            $table->json('device_context')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'client_uuid'], 'fs_time_session_idempotency_unique');
            $table->unique(['tenant_id', 'active_user_key'], 'fs_time_session_one_active_unique');
            $table->index(['tenant_id', 'user_id', 'status'], 'fs_time_session_active_idx');
            $table->index(['tenant_id', 'field_service_job_id', 'clocked_in_at'], 'fs_time_session_job_idx');
            $table->foreign('tenant_id', 'fs_time_session_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('field_service_job_id', 'fs_time_session_job_fk')->references('id')->on('field_service_jobs')->cascadeOnDelete();
            $table->foreign('user_id', 'fs_time_session_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('reviewed_by_user_id', 'fs_time_session_reviewer_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('field_service_time_breaks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('field_service_time_session_id');
            $table->uuid('client_uuid');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();
            $table->unique(['field_service_time_session_id', 'client_uuid'], 'fs_time_break_idempotency_unique');
            $table->foreign('tenant_id', 'fs_time_break_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('field_service_time_session_id', 'fs_time_break_session_fk')->references('id')->on('field_service_time_sessions')->cascadeOnDelete();
        });

        Schema::create('tenant_employee_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('invited_by_user_id')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('role', 40)->default('member');
            $table->string('token_hash', 64)->unique();
            $table->string('status', 30)->default('pending');
            $table->string('delivery_status', 30)->default('not_sent');
            $table->string('provider_message_id')->nullable();
            $table->text('delivery_error')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'expires_at'], 'tenant_employee_invite_status_idx');
            $table->foreign('tenant_id', 'tenant_employee_invite_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('invited_by_user_id', 'tenant_employee_invite_actor_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('accepted_by_user_id', 'tenant_employee_invite_user_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('team_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('field_service_job_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable();
            $table->string('kind', 30);
            $table->string('name')->nullable();
            $table->string('direct_key')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'field_service_job_id'], 'team_channel_job_unique');
            $table->unique(['tenant_id', 'direct_key'], 'team_channel_direct_unique');
            $table->index(['tenant_id', 'kind', 'updated_at'], 'team_channel_tenant_kind_idx');
            $table->foreign('tenant_id', 'team_channel_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('field_service_job_id', 'team_channel_job_fk')->references('id')->on('field_service_jobs')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'team_channel_actor_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('team_channel_members', function (Blueprint $table): void {
            $table->foreignId('tenant_id');
            $table->foreignId('team_channel_id');
            $table->foreignId('user_id');
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('muted_at')->nullable();
            $table->timestamps();
            $table->primary(['team_channel_id', 'user_id'], 'team_channel_member_primary');
            $table->index(['tenant_id', 'user_id'], 'team_channel_member_tenant_idx');
            $table->foreign('tenant_id', 'team_channel_member_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('team_channel_id', 'team_channel_member_channel_fk')->references('id')->on('team_channels')->cascadeOnDelete();
            $table->foreign('user_id', 'team_channel_member_user_fk')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('team_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('team_channel_id');
            $table->foreignId('created_by_user_id')->nullable();
            $table->foreignId('parent_message_id')->nullable();
            $table->uuid('client_uuid');
            $table->longText('body');
            $table->json('mention_user_ids')->nullable();
            $table->json('reactions')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'created_by_user_id', 'client_uuid'], 'team_message_idempotency_unique');
            $table->index(['team_channel_id', 'created_at'], 'team_message_channel_time_idx');
            $table->foreign('tenant_id', 'team_message_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('team_channel_id', 'team_message_channel_fk')->references('id')->on('team_channels')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'team_message_actor_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('parent_message_id', 'team_message_parent_fk')->references('id')->on('team_messages')->nullOnDelete();
        });

        Schema::create('field_material_catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->string('name');
            $table->string('sku', 120)->nullable();
            $table->string('unit', 40)->nullable();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'active', 'name'], 'field_material_catalog_active_idx');
            $table->foreign('tenant_id', 'field_material_catalog_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('workspace_asset_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('uploaded_by_user_id');
            $table->foreignId('field_service_job_id')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->string('storage_disk', 80);
            $table->string('storage_path', 1024);
            $table->string('file_name');
            $table->string('mime_type', 160);
            $table->unsignedBigInteger('max_file_size');
            $table->string('visibility', 40)->default('team');
            $table->string('caption')->nullable();
            $table->string('status', 30)->default('initialized');
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'expires_at'], 'workspace_upload_status_idx');
            $table->foreign('tenant_id', 'workspace_upload_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('uploaded_by_user_id', 'workspace_upload_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('field_service_job_id', 'workspace_upload_job_fk')->references('id')->on('field_service_jobs')->cascadeOnDelete();
        });

        Schema::table('field_service_materials', function (Blueprint $table): void {
            $table->foreignId('field_material_catalog_item_id')->nullable()->after('field_service_job_id');
            $table->decimal('pulled_quantity', 10, 2)->default(0)->after('quantity');
            $table->decimal('loaded_quantity', 10, 2)->default(0)->after('pulled_quantity');
            $table->decimal('used_quantity', 10, 2)->default(0)->after('loaded_quantity');
            $table->foreign('field_material_catalog_item_id', 'fs_material_catalog_fk')->references('id')->on('field_material_catalog_items')->nullOnDelete();
        });

        Schema::table('workspace_assets', function (Blueprint $table): void {
            $table->string('thumbnail_disk', 80)->nullable()->after('storage_path');
            $table->string('thumbnail_path', 1024)->nullable()->after('thumbnail_disk');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_assets', fn (Blueprint $table) => $table->dropColumn(['thumbnail_disk', 'thumbnail_path']));
        Schema::table('field_service_materials', function (Blueprint $table): void {
            $table->dropForeign('fs_material_catalog_fk');
            $table->dropColumn(['field_material_catalog_item_id', 'pulled_quantity', 'loaded_quantity', 'used_quantity']);
        });
        Schema::dropIfExists('field_material_catalog_items');
        Schema::dropIfExists('workspace_asset_uploads');
        Schema::dropIfExists('team_messages');
        Schema::dropIfExists('team_channel_members');
        Schema::dropIfExists('team_channels');
        Schema::dropIfExists('tenant_employee_invitations');
        Schema::dropIfExists('field_service_time_breaks');
        Schema::dropIfExists('field_service_time_sessions');
        Schema::dropIfExists('field_service_job_vehicle_assignments');
        Schema::dropIfExists('field_service_work_candidates');
        Schema::table('tenant_user', fn (Blueprint $table) => $table->dropColumn('membership_active'));
    }
};
