<?php

use Database\Seeders\ModernForestryAppFeedbackSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if (
            ! Schema::hasTable('tenants') ||
            ! Schema::hasTable('client_projects') ||
            ! Schema::hasTable('client_project_tickets')
        ) {
            return;
        }

        app(ModernForestryAppFeedbackSeeder::class)->run();
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_projects') || ! Schema::hasTable('client_project_tickets')) {
            return;
        }

        $project = DB::table('client_projects')
            ->where('title', 'Modern Forestry App Request Board')
            ->where('metadata->source', 'modern_forestry_app_feedback_seed')
            ->first(['id']);

        if (! $project || ! is_numeric($project->id)) {
            return;
        }

        DB::table('client_project_tickets')
            ->where('client_project_id', (int) $project->id)
            ->where('metadata->source', 'modern_forestry_app_feedback_seed')
            ->delete();

        DB::table('client_projects')
            ->where('id', (int) $project->id)
            ->delete();
    }
};
