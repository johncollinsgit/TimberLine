<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modern_forestry_mobile_bag_snapshots', function (Blueprint $table): void {
            $table->timestamp('cart_started_at')->nullable()->after('last_synced_at');
            $table->timestamp('completed_at')->nullable()->after('next_reminder_at');
        });
    }

    public function down(): void
    {
        Schema::table('modern_forestry_mobile_bag_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['cart_started_at', 'completed_at']);
        });
    }
};
