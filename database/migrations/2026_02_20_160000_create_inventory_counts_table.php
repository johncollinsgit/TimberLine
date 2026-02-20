<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scent_id');
            $table->unsignedBigInteger('size_id')->nullable();
            $table->unsignedInteger('on_hand_qty')->default(0);
            $table->timestamps();

            $table->unique(['scent_id', 'size_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_counts');
    }
};
