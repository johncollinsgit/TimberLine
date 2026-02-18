<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            // keep existing scent_name/size_code for legacy rows if you have them
            $table->foreignId('scent_id')->nullable()->constrained('scents')->nullOnDelete()->after('order_id');
            $table->foreignId('size_id')->nullable()->constrained('sizes')->nullOnDelete()->after('scent_id');

            $table->index(['order_id', 'scent_id', 'size_id'], 'order_lines_order_scent_size_idx');
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropIndex('order_lines_order_scent_size_idx');

            $table->dropConstrainedForeignId('size_id');
            $table->dropConstrainedForeignId('scent_id');
        });
    }
};
