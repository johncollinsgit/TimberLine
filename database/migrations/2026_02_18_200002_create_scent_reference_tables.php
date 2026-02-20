<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wholesale_custom_scents', function (Blueprint $table) {
            $table->id();
            $table->string('account_name');
            $table->foreignId('scent_id')->constrained('scents')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('candle_club_scents', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('month');
            $table->unsignedSmallInteger('year');
            $table->foreignId('scent_id')->constrained('scents')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['month', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candle_club_scents');
        Schema::dropIfExists('wholesale_custom_scents');
    }
};
