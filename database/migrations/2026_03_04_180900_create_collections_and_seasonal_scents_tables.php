<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('seasonal_scents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scent_id')->constrained('scents')->cascadeOnDelete();
            $table->string('season');
            $table->timestamps();

            $table->unique(['scent_id', 'season']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasonal_scents');
        Schema::dropIfExists('collections');
    }
};
