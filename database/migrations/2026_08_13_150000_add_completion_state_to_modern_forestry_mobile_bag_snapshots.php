<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modern_forestry_mobile_bag_snapshots')) {
            return;
        }

        Schema::table('modern_forestry_mobile_bag_snapshots', function (Blueprint $table): void {
            if (! Schema::hasColumn('modern_forestry_mobile_bag_snapshots', 'cart_started_at')) {
                $table->timestamp('cart_started_at')->nullable()->after('last_synced_at');
            }

            if (! Schema::hasColumn('modern_forestry_mobile_bag_snapshots', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('next_reminder_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('modern_forestry_mobile_bag_snapshots')) {
            return;
        }

        Schema::table('modern_forestry_mobile_bag_snapshots', function (Blueprint $table): void {
            if (Schema::hasColumn('modern_forestry_mobile_bag_snapshots', 'cart_started_at')) {
                $table->dropColumn('cart_started_at');
            }

            if (Schema::hasColumn('modern_forestry_mobile_bag_snapshots', 'completed_at')) {
                $table->dropColumn('completed_at');
            }
        });
    }
};
