<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_billing_refunds')) {
            return;
        }

        Schema::table('tenant_billing_refunds', function (Blueprint $table): void {
            if (! Schema::hasIndex('tenant_billing_refunds', ['provider_refund_id'], 'unique')) {
                $table->unique('provider_refund_id');
            }

            if (! Schema::hasIndex('tenant_billing_refunds', ['idempotency_key'], 'unique')) {
                $table->unique('idempotency_key');
            }
        });
    }

    public function down(): void
    {
        // Preserve pre-existing production indexes. The base-table migration
        // removes the table during a full rollback on a clean installation.
    }
};
