<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'shopify_customer_id')) {
                $table->string('shopify_customer_id')->nullable()->after('shopify_order_id')->index();
            }

            foreach ([
                'first_name',
                'last_name',
                'email',
                'phone',
                'customer_email',
                'customer_phone',
                'shipping_email',
                'shipping_phone',
                'billing_email',
                'billing_phone',
            ] as $column) {
                if (! Schema::hasColumn('orders', $column)) {
                    $table->string($column)->nullable()->after('customer_name');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            foreach ([
                'shopify_customer_id',
                'first_name',
                'last_name',
                'email',
                'phone',
                'customer_email',
                'customer_phone',
                'shipping_email',
                'shipping_phone',
                'billing_email',
                'billing_phone',
            ] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
