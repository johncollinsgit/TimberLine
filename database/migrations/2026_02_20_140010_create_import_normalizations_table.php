<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_normalizations', function (Blueprint $table) {
            $table->id();
            $table->string('store_key')->nullable();
            $table->unsignedBigInteger('shopify_order_id')->nullable();
            $table->unsignedBigInteger('shopify_line_item_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('field')->nullable(); // scent | size | wick | title
            $table->string('raw_value')->nullable();
            $table->string('normalized_value')->nullable();
            $table->json('context_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_normalizations');
    }
};
