<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scent_templates')) {
            Schema::create('scent_templates', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->default('');
                $table->string('type');
                $table->boolean('is_default')->default(false);
                $table->json('configuration')->nullable();
                $table->timestamps();

                $table->index(['type', 'is_default']);
                $table->index('type');
                $table->index('is_default');
            });
        }

        if (! Schema::hasTable('scent_template_items')) {
            Schema::create('scent_template_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('template_id')->constrained('scent_templates')->cascadeOnDelete();
                $table->foreignId('scent_id')->nullable()->constrained('scents')->nullOnDelete();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['template_id', 'sort_order']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scent_template_items');
        Schema::dropIfExists('scent_templates');
    }
};
