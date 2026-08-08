<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_bud_settings')) {
            return;
        }

        Schema::table('tenant_bud_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_bud_settings', 'ai_status')) {
                $table->string('ai_status', 30)->default('disabled')->after('status');
                $table->unsignedInteger('ai_monthly_budget_cents')->default(0)->after('ai_status');
                $table->unsignedInteger('ai_used_cents')->default(0)->after('ai_monthly_budget_cents');
                $table->timestamp('ai_period_started_at')->nullable()->after('ai_used_cents');
                $table->foreignId('ai_requested_by_user_id')->nullable()->constrained('users')->nullOnDelete()->after('requested_by_user_id');
                $table->foreignId('ai_reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete()->after('reviewed_by_user_id');
                $table->timestamp('ai_requested_at')->nullable()->after('requested_at');
                $table->timestamp('ai_reviewed_at')->nullable()->after('reviewed_at');
                $table->text('ai_review_notes')->nullable()->after('review_notes');
                $table->index(['ai_status'], 'bud_ai_status_idx');
            }
        });
    }

    public function down(): void
    {
        // Never remove paid-AI controls from a released workspace automatically.
    }
};
