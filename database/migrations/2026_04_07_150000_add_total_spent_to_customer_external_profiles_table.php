<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_external_profiles') || Schema::hasColumn('customer_external_profiles', 'total_spent')) {
            return;
        }

        Schema::table('customer_external_profiles', function (Blueprint $table): void {
            $table->decimal('total_spent', 12, 2)->nullable()->after('order_count');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customer_external_profiles') || ! Schema::hasColumn('customer_external_profiles', 'total_spent')) {
            return;
        }

        Schema::table('customer_external_profiles', function (Blueprint $table): void {
            $table->dropColumn('total_spent');
        });
    }
};
