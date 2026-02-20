<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mapping_exceptions', function (Blueprint $table) {
            if (!Schema::hasColumn('mapping_exceptions', 'account_name')) {
                $table->string('account_name')->nullable()->after('shopify_order_id');
            }
            if (!Schema::hasColumn('mapping_exceptions', 'raw_scent_name')) {
                $table->string('raw_scent_name')->nullable()->after('account_name');
            }
            if (!Schema::hasColumn('mapping_exceptions', 'canonical_scent_id')) {
                $table->foreignId('canonical_scent_id')->nullable()->after('raw_scent_name')->constrained('scents')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('mapping_exceptions', function (Blueprint $table) {
            if (Schema::hasColumn('mapping_exceptions', 'canonical_scent_id')) {
                $table->dropConstrainedForeignId('canonical_scent_id');
            }
            if (Schema::hasColumn('mapping_exceptions', 'raw_scent_name')) {
                $table->dropColumn('raw_scent_name');
            }
            if (Schema::hasColumn('mapping_exceptions', 'account_name')) {
                $table->dropColumn('account_name');
            }
        });
    }
};
