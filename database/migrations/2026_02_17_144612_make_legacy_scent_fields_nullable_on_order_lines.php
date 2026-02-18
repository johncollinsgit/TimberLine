<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->string('scent_name')->nullable()->change();
            $table->string('size_code')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->string('scent_name')->nullable(false)->change();
            $table->string('size_code')->nullable(false)->change();
        });
    }
};
