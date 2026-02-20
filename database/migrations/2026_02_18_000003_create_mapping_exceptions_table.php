<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mapping_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('store_key');
            $table->unsignedBigInteger('shopify_order_id')->nullable();
            $table->unsignedBigInteger('shopify_line_item_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_line_id')->nullable();
            $table->string('raw_title')->nullable();
            $table->string('raw_variant')->nullable();
            $table->string('sku')->nullable();
            $table->string('reason')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mapping_exceptions');
    }
};
