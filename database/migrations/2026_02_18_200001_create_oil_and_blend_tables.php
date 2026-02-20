<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_oils', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('grams_on_hand', 10, 2)->default(0);
            $table->decimal('reorder_threshold', 10, 2)->default(0);
            $table->decimal('jug_size_grams', 10, 2)->default(2263);
            $table->string('supplier')->nullable();
            $table->decimal('cost_per_jug', 10, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('blends', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_blend')->default(true);
            $table->timestamps();
        });

        Schema::create('blend_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blend_id')->constrained('blends')->cascadeOnDelete();
            $table->foreignId('base_oil_id')->constrained('base_oils')->cascadeOnDelete();
            $table->unsignedInteger('ratio_weight');
            $table->timestamps();

            $table->unique(['blend_id', 'base_oil_id']);
        });

        Schema::create('oil_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('base_oil_id')->constrained('base_oils')->cascadeOnDelete();
            $table->decimal('grams', 10, 2);
            $table->string('reason');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oil_movements');
        Schema::dropIfExists('blend_components');
        Schema::dropIfExists('blends');
        Schema::dropIfExists('base_oils');
    }
};
