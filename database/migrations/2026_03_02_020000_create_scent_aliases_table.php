<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scent_aliases', function (Blueprint $table): void {
            $table->id();
            $table->string('alias');
            $table->foreignId('scent_id')->constrained()->cascadeOnDelete();
            $table->string('scope')->default('markets');
            $table->timestamps();

            $table->unique(['alias', 'scope']);
            $table->index('alias');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scent_aliases');
    }
};
