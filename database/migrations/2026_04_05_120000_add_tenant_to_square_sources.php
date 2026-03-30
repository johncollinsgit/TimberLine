<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('square_customers', function (Blueprint $table): void {
            if (! Schema::hasColumn('square_customers', 'tenant_id')) {
                $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
                $table->index('tenant_id');
            }
            if (Schema::hasColumn('square_customers', 'square_customer_id')) {
                $table->dropUnique(['square_customer_id']);
                $table->unique(['tenant_id', 'square_customer_id'], 'square_customers_tenant_customer_unique');
            }
        });

        Schema::table('square_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('square_orders', 'tenant_id')) {
                $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
                $table->index('tenant_id');
            }
            if (Schema::hasColumn('square_orders', 'square_order_id')) {
                $table->dropUnique(['square_order_id']);
                $table->unique(['tenant_id', 'square_order_id'], 'square_orders_tenant_order_unique');
            }
        });

        Schema::table('square_payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('square_payments', 'tenant_id')) {
                $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
                $table->index('tenant_id');
            }
            if (Schema::hasColumn('square_payments', 'square_payment_id')) {
                $table->dropUnique(['square_payment_id']);
                $table->unique(['tenant_id', 'square_payment_id'], 'square_payments_tenant_payment_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('square_payments', function (Blueprint $table): void {
            if (Schema::hasColumn('square_payments', 'tenant_id')) {
                $table->dropForeign(['tenant_id']);
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
            if (Schema::hasColumn('square_payments', 'square_payment_id')) {
                $table->dropUnique(['tenant_id', 'square_payment_id']);
                $table->unique('square_payment_id');
            }
        });

        Schema::table('square_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('square_orders', 'tenant_id')) {
                $table->dropForeign(['tenant_id']);
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
            if (Schema::hasColumn('square_orders', 'square_order_id')) {
                $table->dropUnique(['tenant_id', 'square_order_id']);
                $table->unique('square_order_id');
            }
        });

        Schema::table('square_customers', function (Blueprint $table): void {
            if (Schema::hasColumn('square_customers', 'tenant_id')) {
                $table->dropForeign(['tenant_id']);
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
            if (Schema::hasColumn('square_customers', 'square_customer_id')) {
                $table->dropUnique(['tenant_id', 'square_customer_id']);
                $table->unique('square_customer_id');
            }
        });
    }
};
