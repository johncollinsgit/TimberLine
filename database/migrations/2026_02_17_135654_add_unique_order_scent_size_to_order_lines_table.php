<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->unique(['order_id', 'scent_id', 'size_id'], 'order_lines_unique_order_scent_size');
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropUnique('order_lines_unique_order_scent_size');
        });
    }
};
