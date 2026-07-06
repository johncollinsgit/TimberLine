<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator vision board: candidate next steps for the platform, sourced from the
 * most recent agentic analysis (system inventory + project vision). Landlord-global,
 * not tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vision_ideas', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('pitch');
            $table->string('impact')->default('medium');
            $table->string('effort')->default('medium');
            $table->string('category')->default('platform');
            $table->string('source')->nullable();
            $table->string('status')->default('proposed');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('sort_order');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vision_ideas');
    }
};
