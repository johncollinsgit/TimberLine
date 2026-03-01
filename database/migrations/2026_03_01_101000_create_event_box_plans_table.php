<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_box_plans')) {
            return;
        }

        Schema::create('event_box_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_instance_id')->constrained('event_instances')->cascadeOnDelete();
            $table->string('scent_raw');
            $table->decimal('box_count_sent', 6, 2)->nullable();
            $table->decimal('box_count_returned', 6, 2)->nullable();
            $table->text('line_notes')->nullable();
            $table->boolean('is_split_box')->default(false);
            $table->uuid('import_batch_id')->nullable();
            $table->timestamps();

            $table->index('event_instance_id');
            $table->index('scent_raw');
            $table->index('import_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_box_plans');
    }
};
