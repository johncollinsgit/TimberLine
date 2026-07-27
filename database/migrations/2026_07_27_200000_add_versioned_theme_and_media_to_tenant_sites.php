<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_site_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('tenant_site_id');
            $table->unsignedInteger('version_number');
            $table->string('status', 24)->default('draft');
            $table->json('settings')->nullable();
            $table->json('navigation')->nullable();
            $table->json('seo')->nullable();
            $table->string('thumbnail_path', 500)->nullable();
            $table->json('source_manifest')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_site_id', 'version_number'], 'tsv_site_version_uq');
            $table->index(['tenant_id', 'tenant_site_id'], 'tsv_tenant_site_idx');
            $table->index(['tenant_site_id', 'status'], 'tsv_site_status_idx');
            $table->foreign('tenant_id', 'tsv_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tenant_site_id', 'tsv_site_fk')->references('id')->on('tenant_sites')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'tsv_creator_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('tenant_site_media', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('tenant_site_id');
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->string('storage_disk', 80)->default('local');
            $table->string('storage_path', 500);
            $table->string('file_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('checksum', 64);
            $table->string('kind', 40)->default('image');
            $table->string('source', 40)->default('upload');
            $table->string('source_url', 1000)->nullable();
            $table->string('alt_text', 500)->nullable();
            $table->boolean('is_starter')->default(false);
            $table->timestamps();

            $table->unique(['tenant_site_id', 'storage_path'], 'tsm_site_path_uq');
            $table->index(['tenant_id', 'tenant_site_id'], 'tsm_tenant_site_idx');
            $table->foreign('tenant_id', 'tsm_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tenant_site_id', 'tsm_site_fk')->references('id')->on('tenant_sites')->cascadeOnDelete();
            $table->foreign('uploaded_by_user_id', 'tsm_uploader_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('tenant_sites', function (Blueprint $table): void {
            $table->unsignedBigInteger('draft_site_version_id')->nullable()->after('settings');
            $table->unsignedBigInteger('published_site_version_id')->nullable()->after('draft_site_version_id');
            $table->index('draft_site_version_id', 'ts_draft_version_idx');
            $table->index('published_site_version_id', 'ts_published_version_idx');
        });

        DB::table('tenant_sites')->orderBy('id')->each(function (object $site): void {
            $navigation = DB::table('tenant_site_pages')
                ->where('tenant_site_id', $site->id)
                ->where('is_navigation_visible', true)
                ->orderBy('id')
                ->get(['title', 'slug'])
                ->map(fn (object $page): array => [
                    'label' => $page->title,
                    'url' => $page->slug === '/' ? '/' : '/'.ltrim($page->slug, '/'),
                    'type' => 'page',
                ])->all();
            $settings = json_decode((string) ($site->settings ?? '{}'), true) ?: [];
            $now = now();
            $draftId = DB::table('tenant_site_versions')->insertGetId([
                'tenant_id' => $site->tenant_id,
                'tenant_site_id' => $site->id,
                'version_number' => 1,
                'status' => 'draft',
                'settings' => json_encode($settings),
                'navigation' => json_encode($navigation),
                'seo' => json_encode([]),
                'created_by_user_id' => $site->updated_by_user_id ?? $site->created_by_user_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $publishedId = null;
            if ($site->status === 'published') {
                $publishedId = DB::table('tenant_site_versions')->insertGetId([
                    'tenant_id' => $site->tenant_id,
                    'tenant_site_id' => $site->id,
                    'version_number' => 2,
                    'status' => 'published',
                    'settings' => json_encode($settings),
                    'navigation' => json_encode($navigation),
                    'seo' => json_encode([]),
                    'created_by_user_id' => $site->updated_by_user_id ?? $site->created_by_user_id,
                    'published_at' => $site->published_at ?? $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('tenant_sites')->where('id', $site->id)->update([
                'draft_site_version_id' => $draftId,
                'published_site_version_id' => $publishedId,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sites', function (Blueprint $table): void {
            $table->dropIndex('ts_draft_version_idx');
            $table->dropIndex('ts_published_version_idx');
            $table->dropColumn(['draft_site_version_id', 'published_site_version_id']);
        });

        Schema::dropIfExists('tenant_site_media');
        Schema::dropIfExists('tenant_site_versions');
    }
};
