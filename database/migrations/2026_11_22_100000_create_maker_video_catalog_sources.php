<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_video_catalog_sources')) {
            Schema::create('marketplace_video_catalog_sources', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('maker_id');
                $table->string('provider', 16);
                $table->string('reference', 180);
                $table->text('credentials')->nullable();
                $table->json('selections')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('marketplace_video_catalog_sources', 'mm_video_catalog_store_unique')) {
            Schema::table('marketplace_video_catalog_sources', fn (Blueprint $table) => $table->unique(['provider', 'reference'], 'mm_video_catalog_store_unique'));
        }
        if (! Schema::hasIndex('marketplace_video_catalog_sources', 'mm_video_catalog_maker')) {
            Schema::table('marketplace_video_catalog_sources', fn (Blueprint $table) => $table->index('maker_id', 'mm_video_catalog_maker'));
        }
    }

    public function down(): void
    {
        // Preserve merchant authorizations and catalog selections during application rollback.
    }
};
