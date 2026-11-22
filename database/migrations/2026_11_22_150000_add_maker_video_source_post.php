<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('marketplace_videos', 'source_post_url')) {
            Schema::table('marketplace_videos', fn (Blueprint $table) => $table->string('source_post_url', 1024)->nullable());
        }
    }

    public function down(): void
    {
        // Retain source attribution when rolling the application back.
    }
};
