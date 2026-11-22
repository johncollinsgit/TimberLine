<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('studio_offers')) {
            Schema::create('studio_offers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_site_id');
                $table->uuid('episode_id');
                $table->string('title');
                $table->string('kind', 24);
                $table->unsignedInteger('price_cents');
                $table->unsignedInteger('clip_cents')->nullable();
                $table->timestamp('closes_at');
                $table->boolean('reserved')->default(false);
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('studio_offers', 'studio_site_episode')) {
            Schema::table('studio_offers', fn (Blueprint $table) => $table->index(['tenant_site_id', 'episode_id'], 'studio_site_episode'));
        }
    }

    public function down(): void
    {
        // Keep published terms and booking records through application rollback.
    }
};
