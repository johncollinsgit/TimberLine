<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_spray_measurements', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('quantity');
            $table->decimal('alcohol_grams', 10, 2)->default(0);
            $table->decimal('oil_grams', 10, 2)->default(0);
            $table->decimal('water_grams', 10, 2)->default(0);
            $table->decimal('total_grams', 10, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_spray_measurements');
    }
};
