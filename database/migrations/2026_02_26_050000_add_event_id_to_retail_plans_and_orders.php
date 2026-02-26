<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retail_plans', function (Blueprint $table) {
            if (!Schema::hasColumn('retail_plans', 'event_id')) {
                $table->foreignId('event_id')->nullable()->after('queue_type')->constrained('events')->nullOnDelete();
                $table->index(['queue_type', 'event_id'], 'retail_plans_queue_type_event_id_idx');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'event_id')) {
                $table->foreignId('event_id')->nullable()->after('order_type')->constrained('events')->nullOnDelete();
                $table->index(['order_type', 'event_id'], 'orders_order_type_event_id_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'event_id')) {
                $table->dropIndex('orders_order_type_event_id_idx');
                $table->dropConstrainedForeignId('event_id');
            }
        });

        Schema::table('retail_plans', function (Blueprint $table) {
            if (Schema::hasColumn('retail_plans', 'event_id')) {
                $table->dropIndex('retail_plans_queue_type_event_id_idx');
                $table->dropConstrainedForeignId('event_id');
            }
        });
    }
};
