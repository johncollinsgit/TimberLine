<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique();
            $table->string('preset_key', 80)->nullable();
            $table->string('entity_type', 80)->nullable();
            $table->char('country_code', 2)->default('US');
            $table->string('state_code', 10)->nullable();
            $table->string('tax_year_basis', 30)->default('calendar');
            $table->string('accounting_basis', 30)->default('accrual');
            $table->string('setup_status', 40)->default('needs_review')->index();
            $table->longText('configuration')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id', 'acct_profiles_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('reviewed_by_user_id', 'acct_profiles_reviewer_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('accounting_revenue_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->string('stream_key', 60);
            $table->string('source_system', 60);
            $table->string('matcher_type', 80);
            $table->string('matcher_fingerprint', 64);
            $table->longText('matcher_value');
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'source_system', 'matcher_type', 'matcher_fingerprint'], 'acct_rev_rules_match_unique');
            $table->index(['tenant_id', 'stream_key', 'status'], 'acct_rev_rules_stream_idx');
            $table->foreign('tenant_id', 'acct_rev_rules_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('approved_by_user_id', 'acct_rev_rules_approver_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('accounting_compliance_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('accounting_profile_id')->nullable();
            $table->string('task_key', 120);
            $table->string('period_key', 40)->default('setup');
            $table->string('name');
            $table->text('explanation')->nullable();
            $table->string('jurisdiction', 160)->nullable();
            $table->string('obligation', 160)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->decimal('amount_due', 14, 2)->nullable();
            $table->string('status', 40)->default('needs_setup')->index();
            $table->string('destination_name')->nullable();
            $table->string('destination_url', 1024)->nullable();
            $table->string('source_url', 1024)->nullable();
            $table->boolean('quickbooks_expected')->default(false);
            $table->string('confidence', 30)->default('unverified');
            $table->string('assignee_label')->nullable();
            $table->text('notes')->nullable();
            $table->longText('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'task_key', 'period_key'], 'acct_tasks_period_unique');
            $table->index(['tenant_id', 'status', 'due_at'], 'acct_tasks_due_idx');
            $table->foreign('tenant_id', 'acct_tasks_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('accounting_profile_id', 'acct_tasks_profile_fk')->references('id')->on('accounting_profiles')->nullOnDelete();
            $table->foreign('completed_by_user_id', 'acct_tasks_completer_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('accounting_close_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 30)->default('open')->index();
            $table->unsignedSmallInteger('completed_items')->default(0);
            $table->unsignedSmallInteger('total_items')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'period_start'], 'acct_close_period_unique');
            $table->foreign('tenant_id', 'acct_close_period_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('closed_by_user_id', 'acct_close_period_closer_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('accounting_close_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('accounting_close_period_id');
            $table->string('definition_key', 120);
            $table->string('title');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 30)->default('open')->index();
            $table->string('deep_link', 1024)->nullable();
            $table->text('owner_notes')->nullable();
            $table->longText('evidence')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['accounting_close_period_id', 'definition_key'], 'acct_close_items_definition_unique');
            $table->index(['tenant_id', 'status'], 'acct_close_items_status_idx');
            $table->foreign('tenant_id', 'acct_close_items_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('accounting_close_period_id', 'acct_close_items_period_fk')->references('id')->on('accounting_close_periods')->cascadeOnDelete();
            $table->foreign('completed_by_user_id', 'acct_close_items_user_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('accounting_debt_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->string('source_account_id', 180);
            $table->string('account_name');
            $table->string('account_type', 60);
            $table->date('observed_on');
            $table->decimal('balance', 14, 2);
            $table->decimal('credit_limit', 14, 2)->nullable();
            $table->decimal('available_credit', 14, 2)->nullable();
            $table->decimal('interest_rate', 8, 5)->nullable();
            $table->longText('source_metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'source_account_id', 'observed_on'], 'acct_debt_snapshot_unique');
            $table->index(['tenant_id', 'observed_on'], 'acct_debt_snapshot_date_idx');
            $table->foreign('tenant_id', 'acct_debt_snapshot_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('accounting_event_source_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->string('source_type', 40)->default('spreadsheet');
            $table->string('source_filename');
            $table->string('sheet_name')->nullable();
            $table->string('checksum', 64);
            $table->string('mapping_version', 40)->nullable();
            $table->string('status', 40)->default('mapping_required')->index();
            $table->longText('source_metadata')->nullable();
            $table->foreignId('imported_by_user_id')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'checksum', 'mapping_version'], 'acct_event_import_unique');
            $table->foreign('tenant_id', 'acct_event_import_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('imported_by_user_id', 'acct_event_import_user_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('accounting_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('actor_user_id')->nullable();
            $table->string('event_type', 100);
            $table->string('subject_type', 160)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->longText('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'occurred_at'], 'acct_audit_tenant_time_idx');
            $table->foreign('tenant_id', 'acct_audit_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'acct_audit_actor_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_audit_events');
        Schema::dropIfExists('accounting_event_source_imports');
        Schema::dropIfExists('accounting_debt_snapshots');
        Schema::dropIfExists('accounting_close_items');
        Schema::dropIfExists('accounting_close_periods');
        Schema::dropIfExists('accounting_compliance_tasks');
        Schema::dropIfExists('accounting_revenue_rules');
        Schema::dropIfExists('accounting_profiles');
    }
};
