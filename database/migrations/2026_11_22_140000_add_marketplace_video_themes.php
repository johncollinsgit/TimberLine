<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('marketplace_videos', 'theme')) {
            Schema::table('marketplace_videos', fn (Blueprint $table) => $table->string('theme', 32)->default('maker-showcases'));
        }
        if (! Schema::hasIndex('marketplace_videos', 'mm_video_theme_publication')) {
            Schema::table('marketplace_videos', fn (Blueprint $table) => $table->index(['theme', 'status', 'publish_at'], 'mm_video_theme_publication'));
        }
    }

    public function down(): void
    {
        // Keep each maker's editorial theme on application rollback.
    }
};
