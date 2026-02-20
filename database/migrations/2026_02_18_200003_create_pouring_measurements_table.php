<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pouring_measurements', function (Blueprint $table) {
            $table->id();
            $table->string('size_code');
            $table->string('product_type')->default('candle');
            $table->decimal('wax_grams', 10, 2)->default(0);
            $table->decimal('oil_grams', 10, 2)->default(0);
            $table->decimal('total_grams', 10, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['size_code', 'product_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pouring_measurements');
    }
};
