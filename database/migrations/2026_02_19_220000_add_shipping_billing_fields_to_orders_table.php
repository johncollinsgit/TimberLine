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
            if (!Schema::hasColumn('orders', 'shipping_company')) {
                $table->string('shipping_company')->nullable()->after('billing_name');
            }
            if (!Schema::hasColumn('orders', 'shipping_address1')) {
                $table->string('shipping_address1')->nullable()->after('shipping_company');
            }
            if (!Schema::hasColumn('orders', 'billing_company')) {
                $table->string('billing_company')->nullable()->after('shipping_address1');
            }
            if (!Schema::hasColumn('orders', 'billing_address1')) {
                $table->string('billing_address1')->nullable()->after('billing_company');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['shipping_name', 'billing_name', 'shipping_company', 'shipping_address1', 'billing_company', 'billing_address1'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
