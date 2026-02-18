<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void
{
    Schema::table('order_lines', function (\Illuminate\Database\Schema\Blueprint $table) {
        // If you're truly moving to the new world, make these NOT NULL first (ideal),
        // otherwise this index won't protect you from NULL combinations.
        // $table->unsignedBigInteger('scent_id')->nullable(false)->change();
        // $table->unsignedBigInteger('size_id')->nullable(false)->change();

        $table->unique(['order_id', 'scent_id', 'size_id'], 'order_lines_order_scent_size_unique');
    });
}

public function down(): void
{
    Schema::table('order_lines', function (\Illuminate\Database\Schema\Blueprint $table) {
        $table->dropUnique('order_lines_order_scent_size_unique');
    });
}

};
