<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_direct_invoices', function (Blueprint $table): void {
            $table->text('customer_phone')->nullable()->after('customer_email');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_direct_invoices', function (Blueprint $table): void {
            $table->dropColumn('customer_phone');
        });
    }
};
