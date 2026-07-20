<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_messaging_usage_periods', 'tenant_direct_invoice_id')) {
            Schema::table('tenant_messaging_usage_periods', function (Blueprint $table): void {
                $table->foreignId('tenant_direct_invoice_id')->nullable()->after('buyer_charge_micros');
                $table->foreign('tenant_direct_invoice_id', 'tm_usage_period_invoice_fk')
                    ->references('id')->on('tenant_direct_invoices')->nullOnDelete();
                $table->timestamp('invoiced_at')->nullable()->after('tenant_direct_invoice_id');
                $table->index(['tenant_id', 'period_end', 'invoiced_at'], 'tm_usage_period_invoice_due_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_messaging_usage_periods', 'tenant_direct_invoice_id')) {
            Schema::table('tenant_messaging_usage_periods', function (Blueprint $table): void {
                $table->dropIndex('tm_usage_period_invoice_due_idx');
                $table->dropForeign('tm_usage_period_invoice_fk');
                $table->dropColumn(['tenant_direct_invoice_id', 'invoiced_at']);
            });
        }
    }
};
