<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_fleet_tracking_settings') || Schema::hasColumn('tenant_fleet_tracking_settings', 'approval_reference')) {
            return;
        }

        Schema::table('tenant_fleet_tracking_settings', function (Blueprint $table): void {
            $table->string('approval_basis', 24)->nullable()->after('policy_sha256');
            $table->string('approval_reference', 500)->nullable()->after('approval_basis');
            $table->timestamp('approved_at')->nullable()->after('approval_reference');
            $table->foreignId('approved_by_user_id')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
        });

        DB::table('tenant_fleet_tracking_settings')->whereNotNull('legal_reviewed_at')->update([
            'approval_basis' => 'counsel',
            'approval_reference' => DB::raw('counsel_review_reference'),
            'approved_at' => DB::raw('legal_reviewed_at'),
            'approved_by_user_id' => DB::raw('legal_reviewed_by_user_id'),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenant_fleet_tracking_settings') || ! Schema::hasColumn('tenant_fleet_tracking_settings', 'approval_reference')) {
            return;
        }

        Schema::table('tenant_fleet_tracking_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropColumn(['approval_basis', 'approval_reference', 'approved_at']);
        });
    }
};
