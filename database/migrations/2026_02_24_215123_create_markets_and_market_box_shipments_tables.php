<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('markets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('default_location_city')->nullable();
            $table->string('default_location_state', 10)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('market_box_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('item_type')->nullable();
            $table->string('product_key')->nullable();
            $table->string('sku')->nullable();
            $table->string('scent')->nullable();
            $table->string('size')->nullable();
            $table->unsignedInteger('qty')->default(0);
            $table->text('notes')->nullable();
            $table->json('raw_row')->nullable();
            $table->string('source_row_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['event_id', 'source_row_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_box_shipments');
        Schema::dropIfExists('markets');
    }
};

