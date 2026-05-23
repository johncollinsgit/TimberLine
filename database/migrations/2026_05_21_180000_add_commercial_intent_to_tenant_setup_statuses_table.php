<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_setup_statuses')) {
            return;
        }

        Schema::table('tenant_setup_statuses', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_setup_statuses', 'plan_interest')) {
                $table->string('plan_interest', 40)->default('undecided')->after('mobile_interest');
            }

            if (! Schema::hasColumn('tenant_setup_statuses', 'billing_lane_interest')) {
                $table->string('billing_lane_interest', 60)->default('undecided')->after('plan_interest');
            }

            if (! Schema::hasColumn('tenant_setup_statuses', 'implementation_help_interest')) {
                $table->boolean('implementation_help_interest')->default(false)->after('billing_lane_interest');
            }

            if (! Schema::hasColumn('tenant_setup_statuses', 'commercial_notes')) {
                $table->text('commercial_notes')->nullable()->after('implementation_help_interest');
            }

            if (! Schema::hasColumn('tenant_setup_statuses', 'commercial_review_status')) {
                $table->string('commercial_review_status', 40)->default('pending_review')->after('commercial_notes');
            }

            if (! Schema::hasColumn('tenant_setup_statuses', 'commercial_next_action')) {
                $table->string('commercial_next_action', 500)->nullable()->after('commercial_review_status');
            }

            if (! Schema::hasColumn('tenant_setup_statuses', 'commercial_reviewed_by')) {
                $table->foreignId('commercial_reviewed_by')
                    ->nullable()
                    ->after('commercial_next_action')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('tenant_setup_statuses', 'commercial_reviewed_at')) {
                $table->timestamp('commercial_reviewed_at')->nullable()->after('commercial_reviewed_by');
            }
        });

        Schema::table('tenant_setup_statuses', function (Blueprint $table): void {
            $table->index(['plan_interest', 'billing_lane_interest'], 'tss_commercial_intent_idx');
            $table->index('commercial_review_status', 'tss_commercial_review_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenant_setup_statuses')) {
            return;
        }

        Schema::table('tenant_setup_statuses', function (Blueprint $table): void {
            $table->dropIndex('tss_commercial_intent_idx');
            $table->dropIndex('tss_commercial_review_idx');
        });

        Schema::table('tenant_setup_statuses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('commercial_reviewed_by');
            $table->dropColumn([
                'plan_interest',
                'billing_lane_interest',
                'implementation_help_interest',
                'commercial_notes',
                'commercial_review_status',
                'commercial_next_action',
                'commercial_reviewed_at',
            ]);
        });
    }
};
