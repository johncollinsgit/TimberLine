<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quickbooks_source_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('integration_connection_id')->constrained('integration_connections')->cascadeOnDelete();
            $table->string('entity_type', 80);
            $table->string('external_id', 180);
            $table->longText('payload');
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'integration_connection_id', 'entity_type', 'external_id'],
                'qbo_source_tenant_connection_entity_external_unique'
            );
            $table->index(['tenant_id', 'entity_type'], 'qbo_source_tenant_entity_idx');
        });

        Schema::create('quickbooks_audit_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('integration_connection_id')->constrained('integration_connections')->cascadeOnDelete();
            $table->string('status', 40)->default('running')->index();
            $table->boolean('dry_run')->default(true);
            $table->longText('summary')->nullable();
            $table->longText('errors')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'started_at'], 'qbo_audit_tenant_started_idx');
        });

        Schema::create('field_service_financial_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('marketing_profile_id')->nullable()->constrained('marketing_profiles')->nullOnDelete();
            $table->foreignId('field_service_job_id')->nullable()->constrained('field_service_jobs')->nullOnDelete();
            $table->string('source', 40)->default('quickbooks');
            $table->string('document_type', 40);
            $table->string('external_id', 180);
            $table->string('document_number', 120)->nullable();
            $table->string('status', 80)->nullable();
            $table->date('transaction_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('total_amount', 14, 2)->nullable();
            $table->decimal('balance', 14, 2)->nullable();
            $table->string('currency', 12)->nullable();
            $table->text('private_note')->nullable();
            $table->text('customer_memo')->nullable();
            $table->json('linked_transactions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'source', 'document_type', 'external_id'],
                'fs_fin_docs_tenant_source_type_external_unique'
            );
            $table->index(['tenant_id', 'document_type', 'transaction_date'], 'fs_fin_docs_tenant_type_date_idx');
            $table->index(['tenant_id', 'field_service_job_id'], 'fs_fin_docs_tenant_job_idx');
        });

        Schema::create('field_service_financial_document_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('field_service_financial_document_id')
                ->constrained('field_service_financial_documents')
                ->cascadeOnDelete();
            $table->string('source_line_id', 180)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('detail_type', 80)->nullable();
            $table->string('item_external_id', 180)->nullable();
            $table->string('item_name')->nullable();
            $table->text('description')->nullable();
            $table->decimal('quantity', 14, 4)->nullable();
            $table->decimal('unit_price', 14, 4)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'item_external_id'], 'fs_fin_lines_tenant_item_idx');
            $table->index(['field_service_financial_document_id', 'sort_order'], 'fs_fin_lines_document_sort_idx');
        });

        Schema::create('field_service_financial_document_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('field_service_financial_document_id')
                ->constrained('field_service_financial_documents')
                ->cascadeOnDelete();
            $table->string('external_id', 180);
            $table->string('file_name')->nullable();
            $table->string('content_type', 160)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['field_service_financial_document_id', 'external_id'],
                'fs_fin_attachments_document_external_unique'
            );
        });

        Schema::create('field_service_price_book_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('source', 40)->default('quickbooks');
            $table->string('external_id', 180);
            $table->string('name');
            $table->string('item_type', 80)->nullable();
            $table->string('sku', 120)->nullable();
            $table->text('description')->nullable();
            $table->decimal('unit_price', 14, 4)->nullable();
            $table->decimal('purchase_cost', 14, 4)->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('taxable')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'source', 'external_id'], 'fs_price_book_tenant_source_external_unique');
            $table->index(['tenant_id', 'item_type', 'active'], 'fs_price_book_tenant_type_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_service_price_book_items');
        Schema::dropIfExists('field_service_financial_document_attachments');
        Schema::dropIfExists('field_service_financial_document_lines');
        Schema::dropIfExists('field_service_financial_documents');
        Schema::dropIfExists('quickbooks_audit_runs');
        Schema::dropIfExists('quickbooks_source_records');
    }
};
