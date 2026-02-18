<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            // If you still have these legacy columns, make them nullable.
            if (Schema::hasColumn('order_lines', 'scent_name')) {
                $table->string('scent_name')->nullable()->change();
            }
            if (Schema::hasColumn('order_lines', 'size_code')) {
                $table->string('size_code')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            if (Schema::hasColumn('order_lines', 'scent_name')) {
                $table->string('scent_name')->nullable(false)->change();
            }
            if (Schema::hasColumn('order_lines', 'size_code')) {
                $table->string('size_code')->nullable(false)->change();
            }
        });
    }
};
