<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Create new table with correct nullability
        Schema::create('order_lines_new', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('order_id');

            // legacy fields (now nullable)
            $table->string('scent_name')->nullable();
            $table->string('size_code')->nullable();

            $table->integer('quantity')->default(1);

            $table->string('raw_title')->nullable();
            $table->string('raw_variant')->nullable();

            $table->string('pour_status')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('brought_down_at')->nullable();

            $table->timestamps();

            $table->unsignedBigInteger('scent_id')->nullable();
            $table->unsignedBigInteger('size_id')->nullable();

            // (Optional) If you want FKs later, add after you have stable data.
        });

        // 2) Copy data
        DB::statement("
            INSERT INTO order_lines_new
            (id, order_id, scent_name, size_code, quantity, raw_title, raw_variant, pour_status, started_at, brought_down_at, created_at, updated_at, scent_id, size_id)
            SELECT
            id, order_id, scent_name, size_code, quantity, raw_title, raw_variant, pour_status, started_at, brought_down_at, created_at, updated_at, scent_id, size_id
            FROM order_lines
        ");

        // 3) Swap tables
        Schema::drop('order_lines');
        Schema::rename('order_lines_new', 'order_lines');
    }

    public function down(): void
    {
        // Intentionally left empty for rebuild migrations in dev.
        // If you need down(), we can rebuild the opposite direction.
    }
};
