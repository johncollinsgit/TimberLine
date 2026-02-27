<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('retail_plan_items', 'upcoming_event_id')) {
            return;
        }

        Schema::table('retail_plan_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('upcoming_event_id')->nullable();
            $table->index('upcoming_event_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('retail_plan_items', 'upcoming_event_id')) {
            return;
        }

        Schema::table('retail_plan_items', function (Blueprint $table): void {
            $table->dropIndex(['upcoming_event_id']);
            $table->dropColumn('upcoming_event_id');
        });
    }
};
