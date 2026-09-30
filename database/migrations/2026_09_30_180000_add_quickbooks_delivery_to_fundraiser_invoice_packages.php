<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modern_forestry_fundraiser_invoice_packages')) {
            return;
        }
        $columns = [
            'quickbooks_invoice_id' => fn (Blueprint $table) => $table->string('quickbooks_invoice_id', 190)->nullable()->after('tracking_status'),
            'quickbooks_doc_number' => fn (Blueprint $table) => $table->string('quickbooks_doc_number', 80)->nullable()->after('quickbooks_invoice_id'),
            'quickbooks_created_at' => fn (Blueprint $table) => $table->timestamp('quickbooks_created_at')->nullable()->after('quickbooks_doc_number'),
            'quickbooks_sent_at' => fn (Blueprint $table) => $table->timestamp('quickbooks_sent_at')->nullable()->after('quickbooks_created_at'),
            'quickbooks_last_error' => fn (Blueprint $table) => $table->text('quickbooks_last_error')->nullable()->after('quickbooks_sent_at'),
        ];
        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('modern_forestry_fundraiser_invoice_packages', $name)) {
                Schema::table('modern_forestry_fundraiser_invoice_packages', $add);
            }
        }
        if (! Schema::hasIndex('modern_forestry_fundraiser_invoice_packages', 'mffip_tenant_qb_invoice_uq')) {
            Schema::table('modern_forestry_fundraiser_invoice_packages', fn (Blueprint $table) => $table->unique(['tenant_id', 'quickbooks_invoice_id'], 'mffip_tenant_qb_invoice_uq'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('modern_forestry_fundraiser_invoice_packages') || ! Schema::hasColumn('modern_forestry_fundraiser_invoice_packages', 'quickbooks_invoice_id')) {
            return;
        }
        Schema::table('modern_forestry_fundraiser_invoice_packages', function (Blueprint $table): void {
            $table->dropUnique('mffip_tenant_qb_invoice_uq');
            $table->dropColumn(['quickbooks_invoice_id', 'quickbooks_doc_number', 'quickbooks_created_at', 'quickbooks_sent_at', 'quickbooks_last_error']);
        });
    }
};
