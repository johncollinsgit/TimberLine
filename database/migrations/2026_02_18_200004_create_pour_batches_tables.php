<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pour_batches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('status')->default('draft');
            $table->string('selection_mode')->nullable();
            $table->string('order_type')->nullable();
            $table->decimal('wax_total_grams', 10, 2)->default(0);
            $table->decimal('oil_total_grams', 10, 2)->default(0);
            $table->decimal('total_grams', 10, 2)->default(0);
            $table->unsignedInteger('pitcher_count')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('pour_batch_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pour_batch_id')->constrained('pour_batches')->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_line_id')->nullable();
            $table->unsignedBigInteger('scent_id')->nullable();
            $table->unsignedBigInteger('size_id')->nullable();
            $table->string('sku')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('wax_grams', 10, 2)->default(0);
            $table->decimal('oil_grams', 10, 2)->default(0);
            $table->decimal('total_grams', 10, 2)->default(0);
            $table->string('status')->default('queued');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'order_line_id']);
        });

        Schema::create('pour_batch_pitchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pour_batch_id')->constrained('pour_batches')->cascadeOnDelete();
            $table->unsignedInteger('pitcher_index');
            $table->decimal('wax_grams', 10, 2)->default(0);
            $table->decimal('oil_grams', 10, 2)->default(0);
            $table->decimal('total_grams', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['pour_batch_id', 'pitcher_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pour_batch_pitchers');
        Schema::dropIfExists('pour_batch_lines');
        Schema::dropIfExists('pour_batches');
    }
};
