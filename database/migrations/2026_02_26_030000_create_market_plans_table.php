<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_plans', function (Blueprint $table) {
            $table->id();
            $table->string('event_title');
            $table->date('event_date')->nullable();
            $table->string('normalized_title')->index();
            $table->string('box_type');
            $table->string('scent');
            $table->unsignedInteger('box_count')->default(1);
            $table->json('top_shelf_definition_json')->nullable();
            $table->string('status')->default('draft')->index();
            $table->timestamps();

            $table->index(['normalized_title', 'event_date'], 'market_plans_norm_title_event_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_plans');
    }
};
