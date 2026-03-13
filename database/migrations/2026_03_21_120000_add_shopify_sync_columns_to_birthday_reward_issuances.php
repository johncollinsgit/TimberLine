<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('birthday_reward_issuances', function (Blueprint $table): void {
            if (! Schema::hasColumn('birthday_reward_issuances', 'shopify_store_key')) {
                $table->string('shopify_store_key', 40)->nullable()->index()->after('shopify_discount_id');
            }

            if (! Schema::hasColumn('birthday_reward_issuances', 'shopify_discount_node_id')) {
                $table->string('shopify_discount_node_id', 191)->nullable()->index()->after('shopify_store_key');
            }

            if (! Schema::hasColumn('birthday_reward_issuances', 'discount_sync_status')) {
                $table->string('discount_sync_status', 40)->nullable()->index()->after('shopify_discount_node_id');
            }

            if (! Schema::hasColumn('birthday_reward_issuances', 'discount_sync_error')) {
                $table->text('discount_sync_error')->nullable()->after('discount_sync_status');
            }

            if (! Schema::hasColumn('birthday_reward_issuances', 'activated_at')) {
                $table->timestamp('activated_at')->nullable()->index()->after('claimed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('birthday_reward_issuances', function (Blueprint $table): void {
            foreach ([
                'activated_at',
                'discount_sync_error',
                'discount_sync_status',
                'shopify_discount_node_id',
                'shopify_store_key',
            ] as $column) {
                if (Schema::hasColumn('birthday_reward_issuances', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
