<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retail_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('retail_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retail_plan_id')->constrained('retail_plans')->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_line_id')->nullable();
            $table->unsignedBigInteger('scent_id')->nullable();
            $table->unsignedBigInteger('size_id')->nullable();
            $table->string('sku')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->string('source')->default('order');
            $table->string('status')->default('draft');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retail_plan_items');
        Schema::dropIfExists('retail_plans');
    }
};
