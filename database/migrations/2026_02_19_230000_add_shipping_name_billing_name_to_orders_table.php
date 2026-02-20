<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'shipping_name')) {
                $table->string('shipping_name')->nullable()->after('order_label');
            }
            if (!Schema::hasColumn('orders', 'billing_name')) {
                $table->string('billing_name')->nullable()->after('shipping_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'shipping_name')) {
                $table->dropColumn('shipping_name');
            }
            if (Schema::hasColumn('orders', 'billing_name')) {
                $table->dropColumn('billing_name');
            }
        });
    }
};
