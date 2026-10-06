<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('draft')->index();
            $table->boolean('public_enabled')->default(false)->index();
            $table->string('subdomain', 120)->unique();
            $table->json('settings')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tenant_site_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_site_id')->constrained('tenant_sites')->cascadeOnDelete();
            $table->string('slug', 160);
            $table->string('page_type', 40)->default('landing');
            $table->string('title', 190);
            $table->boolean('is_navigation_visible')->default(true);
            $table->unsignedBigInteger('draft_version_id')->nullable();
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_site_id', 'slug'], 'site_pages_site_slug_uq');
            $table->index(['tenant_id', 'tenant_site_id'], 'site_pages_tenant_site_idx');
        });

        Schema::create('tenant_site_page_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_site_id')->constrained('tenant_sites')->cascadeOnDelete();
            $table->foreignId('tenant_site_page_id')->constrained('tenant_site_pages')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 24)->default('draft')->index();
            $table->string('title', 190);
            $table->json('blocks');
            $table->json('seo')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['tenant_site_page_id', 'version_number'], 'site_page_versions_page_version_uq');
            $table->index(['tenant_id', 'tenant_site_id'], 'site_versions_tenant_site_idx');
        });

        Schema::create('tenant_site_redirects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_site_id')->constrained('tenant_sites')->cascadeOnDelete();
            $table->string('from_path', 255);
            $table->string('to_path', 500);
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->timestamps();

            $table->unique(['tenant_site_id', 'from_path'], 'site_redirects_site_from_uq');
        });

        Schema::create('tenant_site_publish_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_site_id')->constrained('tenant_sites')->cascadeOnDelete();
            $table->foreignId('tenant_site_page_id')->nullable()->constrained('tenant_site_pages')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 80);
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'tenant_site_id', 'event_type'], 'site_events_tenant_site_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_site_publish_events');
        Schema::dropIfExists('tenant_site_redirects');
        Schema::dropIfExists('tenant_site_page_versions');
        Schema::dropIfExists('tenant_site_pages');
        Schema::dropIfExists('tenant_sites');
    }
};
