<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_wholesale_settings', function (Blueprint $table): void {
            $table->unsignedInteger('prospect_daily_research_limit')->default(25)->after('website_enrichment_enabled');
            $table->unsignedSmallInteger('prospect_daily_run_limit')->default(4)->after('prospect_daily_research_limit');
            $table->unsignedSmallInteger('prospect_outreach_cooldown_days')->default(30)->after('prospect_daily_run_limit');
        });

        Schema::table('wholesale_prospect_discovery_runs', function (Blueprint $table): void {
            $table->date('research_date')->nullable()->after('search_region');
            $table->timestamp('research_usage_reconciled_at')->nullable()->after('cancelled_at');
            $table->index(['tenant_id', 'research_date'], 'wholesale_prospect_runs_tenant_date_idx');
        });

        Schema::table('wholesale_prospects', function (Blueprint $table): void {
            $table->string('instagram_url', 500)->nullable()->after('instagram_handle');
            $table->json('observed_products')->nullable()->after('instagram_url');
            $table->json('observed_brands')->nullable()->after('observed_products');
            $table->json('merchandising_cues')->nullable()->after('observed_brands');
            $table->string('review_status', 40)->default('pending')->after('status');
            $table->foreignId('reviewed_by_user_id')->nullable()->after('assigned_owner_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('last_reviewed_at');
            $table->timestamp('outreach_cooldown_until')->nullable()->after('next_action_at');
            $table->index(['tenant_id', 'review_status', 'fit_score'], 'wholesale_prospects_tenant_review_status_idx');
            $table->index(['tenant_id', 'instagram_handle'], 'wholesale_prospects_tenant_instagram_idx');
        });

        DB::table('wholesale_prospect_discovery_runs')->whereNull('research_date')->update([
            'research_date' => DB::raw('DATE(created_at)'),
        ]);

        DB::table('wholesale_prospects')->whereIn('status', ['qualified', 'converted', 'contacted', 'outreach_attempted'])->update([
            'review_status' => 'approved',
            'reviewed_at' => DB::raw('COALESCE(last_reviewed_at, updated_at)'),
        ]);
        DB::table('wholesale_prospects')->whereIn('status', ['rejected', 'duplicate'])->update([
            'review_status' => 'rejected',
            'reviewed_at' => DB::raw('COALESCE(last_reviewed_at, updated_at)'),
        ]);

        Schema::create('wholesale_prospect_daily_usage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('research_date');
            $table->unsignedInteger('reserved_results')->default(0);
            $table->unsignedInteger('researched_results')->default(0);
            $table->unsignedSmallInteger('queued_runs')->default(0);
            $table->unsignedSmallInteger('completed_runs')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'research_date'], 'wholesale_prospect_usage_tenant_date_unique');
        });

        Schema::create('wholesale_prospect_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('wholesale_prospect_id')->constrained('wholesale_prospects')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('activity_type', 80);
            $table->string('channel', 40)->nullable();
            $table->text('summary');
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'wholesale_prospect_id', 'occurred_at'], 'wholesale_prospect_activity_tenant_idx');
        });

        Schema::create('wholesale_prospect_outreach_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('wholesale_prospect_id')->constrained('wholesale_prospects')->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('channel', 40)->default('instagram');
            $table->string('status', 40)->default('draft');
            $table->text('body');
            $table->json('evidence_snapshot');
            $table->foreignId('generated_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'wholesale_prospect_id', 'channel'], 'wholesale_prospect_draft_tenant_channel_unique');
            $table->index(['tenant_id', 'status'], 'wholesale_prospect_drafts_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_prospect_outreach_drafts');
        Schema::dropIfExists('wholesale_prospect_activities');
        Schema::dropIfExists('wholesale_prospect_daily_usage');

        Schema::table('wholesale_prospects', function (Blueprint $table): void {
            $table->dropIndex('wholesale_prospects_tenant_review_status_idx');
            $table->dropIndex('wholesale_prospects_tenant_instagram_idx');
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropColumn(['instagram_url', 'observed_products', 'observed_brands', 'merchandising_cues', 'review_status', 'reviewed_at', 'outreach_cooldown_until']);
        });

        Schema::table('wholesale_prospect_discovery_runs', function (Blueprint $table): void {
            $table->dropIndex('wholesale_prospect_runs_tenant_date_idx');
            $table->dropColumn(['research_date', 'research_usage_reconciled_at']);
        });

        Schema::table('tenant_wholesale_settings', function (Blueprint $table): void {
            $table->dropColumn(['prospect_daily_research_limit', 'prospect_daily_run_limit', 'prospect_outreach_cooldown_days']);
        });
    }
};
