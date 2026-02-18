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
    if (!Schema::hasColumn('orders', 'tenant_id')) {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable();
        });
    }
}


public function down(): void
{
    Schema::table('orders', function (Blueprint $table) {
        $table->dropColumn('meta');
    });
}

};
