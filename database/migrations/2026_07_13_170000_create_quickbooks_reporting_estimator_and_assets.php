<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quickbooks_reporting_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique();
            $table->foreignId('integration_connection_id')->nullable();
            $table->boolean('scheduled_sync_enabled')->default(false)->index();
            $table->string('sync_cadence', 40)->default('hourly');
            $table->longText('supplies_account_mappings')->nullable();
            $table->longText('wage_account_mappings')->nullable();
            $table->longText('contract_labor_account_mappings')->nullable();
            $table->longText('owner_compensation_account_mappings')->nullable();
            $table->longText('owner_compensation_adjustments')->nullable();
            $table->timestamp('mappings_reviewed_at')->nullable();
            $table->foreignId('mappings_reviewed_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id', 'qbo_report_settings_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('integration_connection_id', 'qbo_report_settings_connection_fk')->references('id')->on('integration_connections')->nullOnDelete();
            $table->foreign('mappings_reviewed_by_user_id', 'qbo_report_settings_reviewer_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('quickbooks_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('integration_connection_id');
            $table->string('mode', 40)->default('incremental');
            $table->string('status', 40)->default('running')->index();
            $table->timestamp('checkpoint_started_at')->nullable();
            $table->timestamp('checkpoint_finished_at')->nullable();
            $table->longText('summary')->nullable();
            $table->longText('errors')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'started_at'], 'qbo_sync_runs_tenant_started_idx');
            $table->foreign('tenant_id', 'qbo_sync_runs_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('integration_connection_id', 'qbo_sync_runs_connection_fk')->references('id')->on('integration_connections')->cascadeOnDelete();
        });

        Schema::create('quickbooks_reporting_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('integration_connection_id');
            $table->string('range_key', 40);
            $table->date('period_start');
            $table->date('period_end');
            $table->longText('metrics');
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'range_key', 'period_start', 'period_end'], 'qbo_report_snapshot_period_unique');
            $table->index(['tenant_id', 'observed_at'], 'qbo_report_snapshot_observed_idx');
            $table->foreign('tenant_id', 'qbo_report_snapshots_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('integration_connection_id', 'qbo_report_snapshots_connection_fk')->references('id')->on('integration_connections')->cascadeOnDelete();
        });

        Schema::create('workspace_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('uploaded_by_user_id')->nullable();
            $table->string('source', 40)->default('upload');
            $table->string('external_id', 180)->nullable();
            $table->string('visibility', 40)->default('team')->index();
            $table->string('storage_disk', 80)->default('local');
            $table->string('storage_path', 1024);
            $table->string('file_name');
            $table->string('mime_type', 160)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('checksum', 128)->nullable();
            $table->string('caption')->nullable();
            $table->json('tags')->nullable();
            $table->longText('search_text')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'source', 'external_id'], 'workspace_assets_source_unique');
            $table->index(['tenant_id', 'created_at'], 'workspace_assets_tenant_created_idx');
            $table->foreign('tenant_id', 'workspace_assets_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('uploaded_by_user_id', 'workspace_assets_uploader_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('field_service_job_workspace_asset', function (Blueprint $table): void {
            $table->foreignId('tenant_id');
            $table->foreignId('field_service_job_id');
            $table->foreignId('workspace_asset_id');
            $table->foreignId('linked_by_user_id')->nullable();
            $table->timestamps();

            $table->primary(['field_service_job_id', 'workspace_asset_id'], 'fs_job_asset_primary');
            $table->index(['tenant_id', 'workspace_asset_id'], 'fs_job_asset_tenant_asset_idx');
            $table->foreign('tenant_id', 'fs_job_asset_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('field_service_job_id', 'fs_job_asset_job_fk')->references('id')->on('field_service_jobs')->cascadeOnDelete();
            $table->foreign('workspace_asset_id', 'fs_job_asset_asset_fk')->references('id')->on('workspace_assets')->cascadeOnDelete();
            $table->foreign('linked_by_user_id', 'fs_job_asset_linker_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('workspace_asset_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('workspace_asset_id')->nullable();
            $table->foreignId('actor_user_id')->nullable();
            $table->string('action', 80)->index();
            $table->longText('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'occurred_at'], 'workspace_asset_events_tenant_time_idx');
            $table->foreign('tenant_id', 'workspace_asset_events_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('workspace_asset_id', 'workspace_asset_events_asset_fk')->references('id')->on('workspace_assets')->nullOnDelete();
            $table->foreign('actor_user_id', 'workspace_asset_events_actor_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('financial_document_workspace_asset', function (Blueprint $table): void {
            $table->foreignId('tenant_id');
            $table->foreignId('field_service_financial_document_id');
            $table->foreignId('workspace_asset_id');
            $table->timestamps();

            $table->primary(['field_service_financial_document_id', 'workspace_asset_id'], 'fin_doc_asset_primary');
            $table->foreign('tenant_id', 'fin_doc_asset_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('field_service_financial_document_id', 'fin_doc_asset_document_fk')->references('id')->on('field_service_financial_documents')->cascadeOnDelete();
            $table->foreign('workspace_asset_id', 'fin_doc_asset_asset_fk')->references('id')->on('workspace_assets')->cascadeOnDelete();
        });

        Schema::create('field_service_price_book_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->string('source', 40)->default('quickbooks');
            $table->string('normalized_key', 180);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 40)->default('suggested')->index();
            $table->unsignedInteger('sample_count')->default(0);
            $table->decimal('median_unit_price', 14, 4)->nullable();
            $table->decimal('minimum_unit_price', 14, 4)->nullable();
            $table->decimal('maximum_unit_price', 14, 4)->nullable();
            $table->decimal('recent_unit_price', 14, 4)->nullable();
            $table->boolean('high_variance')->default(false);
            $table->date('last_invoiced_at')->nullable();
            $table->foreignId('approved_price_book_item_id')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'source', 'normalized_key'], 'fs_price_candidates_source_key_unique');
            $table->foreign('tenant_id', 'fs_price_candidates_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('approved_price_book_item_id', 'fs_price_candidates_item_fk')->references('id')->on('field_service_price_book_items')->nullOnDelete();
            $table->foreign('reviewed_by_user_id', 'fs_price_candidates_reviewer_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('field_service_estimates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('marketing_profile_id')->nullable();
            $table->foreignId('field_service_job_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable();
            $table->string('estimate_number', 120);
            $table->string('status', 40)->default('draft')->index();
            $table->string('title')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'estimate_number'], 'fs_estimates_tenant_number_unique');
            $table->foreign('tenant_id', 'fs_estimates_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('marketing_profile_id', 'fs_estimates_profile_fk')->references('id')->on('marketing_profiles')->nullOnDelete();
            $table->foreign('field_service_job_id', 'fs_estimates_job_fk')->references('id')->on('field_service_jobs')->nullOnDelete();
            $table->foreign('created_by_user_id', 'fs_estimates_creator_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('field_service_estimate_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('field_service_estimate_id');
            $table->foreignId('field_service_price_book_item_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('description');
            $table->decimal('quantity', 14, 4)->default(1);
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->json('source_snapshot')->nullable();
            $table->timestamps();

            $table->index(['field_service_estimate_id', 'sort_order'], 'fs_estimate_lines_sort_idx');
            $table->foreign('tenant_id', 'fs_estimate_lines_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('field_service_estimate_id', 'fs_estimate_lines_estimate_fk')->references('id')->on('field_service_estimates')->cascadeOnDelete();
            $table->foreign('field_service_price_book_item_id', 'fs_estimate_lines_item_fk')->references('id')->on('field_service_price_book_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_service_estimate_lines');
        Schema::dropIfExists('field_service_estimates');
        Schema::dropIfExists('field_service_price_book_candidates');
        Schema::dropIfExists('financial_document_workspace_asset');
        Schema::dropIfExists('workspace_asset_events');
        Schema::dropIfExists('field_service_job_workspace_asset');
        Schema::dropIfExists('workspace_assets');
        Schema::dropIfExists('quickbooks_reporting_snapshots');
        Schema::dropIfExists('quickbooks_sync_runs');
        Schema::dropIfExists('quickbooks_reporting_settings');
    }
};
