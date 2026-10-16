<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_social_publications')) {
            Schema::create('marketing_social_publications', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index('md_4_tenant_idx');
                $table->unsignedBigInteger('post_id');
                $table->unsignedInteger('revision');
                $table->unsignedBigInteger('connection_id');
                $table->string('platform', 24);
                $table->string('status', 24)->default('pending');
                $table->string('external_id', 150)->nullable();
                $table->string('container_id', 150)->nullable();
                $table->string('attempt_uuid', 36)->nullable();
                $table->string('failure_code', 80)->nullable();
                $table->json('insights')->nullable();
                $table->timestamp('observed_at')->nullable();
                $table->unique(['post_id', 'revision', 'platform'], 'md_publication_revision_unique');
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('marketing_social_publications', 'md_4_tenant_idx')) {
            Schema::table('marketing_social_publications', fn (Blueprint $table) => $table->index('tenant_id', 'md_4_tenant_idx'));
        }
        if (! Schema::hasIndex('marketing_social_publications', 'md_publication_revision_unique')) {
            Schema::table('marketing_social_publications', fn (Blueprint $table) => $table->unique(['post_id', 'revision', 'platform'], 'md_publication_revision_unique'));
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_social_publications');
    }
};
