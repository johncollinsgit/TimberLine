<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_mappings')) {
            return;
        }

        Schema::create('event_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('upcoming_event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('past_event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('upcoming_event_id');
            $table->index('past_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_mappings');
    }
};
