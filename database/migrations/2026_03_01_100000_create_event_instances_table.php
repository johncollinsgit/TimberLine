<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_instances')) {
            return;
        }

        Schema::create('event_instances', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('venue')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->string('status')->default('unknown');
            $table->longText('notes')->nullable();
            $table->string('primary_runner')->nullable();
            $table->unsignedInteger('days_attended')->nullable();
            $table->decimal('selling_hours', 6, 2)->nullable();
            $table->decimal('total_sales', 10, 2)->nullable();
            $table->decimal('boxes_sold', 6, 2)->nullable();
            $table->string('source_file')->nullable();
            $table->string('source_sheet')->nullable();
            $table->uuid('import_batch_id')->nullable();
            $table->timestamps();

            $table->index(['title', 'starts_at']);
            $table->index('starts_at');
            $table->index(['state', 'starts_at']);
            $table->index('import_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_instances');
    }
};
