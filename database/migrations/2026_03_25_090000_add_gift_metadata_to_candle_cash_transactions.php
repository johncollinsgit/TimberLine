<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('candle_cash_transactions')) {
            return;
        }

        Schema::table('candle_cash_transactions', function (Blueprint $table): void {
            $table->string('gift_intent')->nullable()->after('description');
            $table->string('gift_origin')->nullable()->after('gift_intent');
            $table->string('notified_via')->nullable()->after('gift_origin');
            $table->string('notification_status')->nullable()->after('notified_via');
            $table->string('campaign_key')->nullable()->after('notification_status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('candle_cash_transactions')) {
            return;
        }

        Schema::table('candle_cash_transactions', function (Blueprint $table): void {
            $table->dropColumn([
                'gift_intent',
                'gift_origin',
                'notified_via',
                'notification_status',
                'campaign_key',
            ]);
        });
    }
};
