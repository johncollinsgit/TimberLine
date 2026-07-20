<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_brand_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('display_name', 120);
            $table->string('tagline', 180)->nullable();
            $table->string('light_logo_path', 500)->nullable();
            $table->string('dark_logo_path', 500)->nullable();
            $table->string('icon_path', 500)->nullable();
            $table->json('asset_sources')->nullable();
            $table->string('primary_color', 7)->default('#123C43');
            $table->string('accent_color', 7)->default('#1E5A63');
            $table->string('surface_color', 7)->default('#FFFFFF');
            $table->string('text_color', 7)->default('#0F1C1F');
            $table->string('display_style', 24)->default('classic');
            $table->string('corner_style', 24)->default('soft');
            $table->string('decor_preset', 24)->default('none');
            $table->string('theme_key', 80)->default('custom');
            $table->json('metadata')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tenant_brand_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_brand_profile_id')->constrained('tenant_brand_profiles')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 80);
            $table->string('label', 160);
            $table->string('source', 20)->default('bundled');
            $table->string('storage_disk', 40)->nullable();
            $table->string('path', 500);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'kind'], 'brand_assets_tenant_kind_idx');
            $table->unique(['tenant_brand_profile_id', 'kind', 'path'], 'brand_assets_profile_kind_path_uq');
        });

        $now = now();
        DB::table('tenants')->orderBy('id')->each(function (object $tenant) use ($now): void {
            $isCollins = strtolower(trim((string) ($tenant->slug ?? ''))) === 'collins-electric';
            $profile = [
                'tenant_id' => (int) $tenant->id,
                'display_name' => $isCollins ? 'Collins Upstate Electric' : (string) $tenant->name,
                'tagline' => $isCollins ? 'Residential · Commercial · Reliable Power' : null,
                'light_logo_path' => $isCollins ? 'brand/kits/collins-upstate-electric/collins-lockup-navy.svg' : null,
                'dark_logo_path' => $isCollins ? 'brand/kits/collins-upstate-electric/collins-lockup-white.svg' : null,
                'icon_path' => $isCollins ? 'brand/kits/collins-upstate-electric/collins-icon.svg' : null,
                'asset_sources' => $isCollins ? json_encode(['light_logo' => 'bundled', 'dark_logo' => 'bundled', 'icon' => 'bundled']) : null,
                'primary_color' => $isCollins ? '#061D42' : '#123C43',
                'accent_color' => $isCollins ? '#1464E8' : '#1E5A63',
                'surface_color' => '#FFFFFF',
                'text_color' => $isCollins ? '#0B1B36' : '#0F1C1F',
                'display_style' => $isCollins ? 'technical' : 'classic',
                'corner_style' => $isCollins ? 'standard' : 'soft',
                'decor_preset' => $isCollins ? 'signal' : 'none',
                'theme_key' => $isCollins ? 'collins-upstate-electric' : 'custom',
                'metadata' => $isCollins ? json_encode(['package' => 'collins-upstate-electric-starter-kit', 'contact_tokens' => ['{{PHONE}}', '{{WEBSITE}}', '{{EMAIL}}']]) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            DB::table('tenant_brand_profiles')->insert($profile);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_brand_assets');
        Schema::dropIfExists('tenant_brand_profiles');
    }
};
