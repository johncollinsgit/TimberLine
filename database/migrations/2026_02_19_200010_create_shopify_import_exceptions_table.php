<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_import_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('shop')->nullable();
            $table->unsignedBigInteger('shopify_order_id')->nullable();
            $table->unsignedBigInteger('shopify_line_item_id')->nullable();
            $table->string('title')->nullable();
            $table->string('reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_import_exceptions');
    }
};
