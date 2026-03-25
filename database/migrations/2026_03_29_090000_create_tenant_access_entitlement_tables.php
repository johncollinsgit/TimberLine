<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_access_profiles')) {
            Schema::create('tenant_access_profiles', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->string('plan_key', 120)->default('shopify_proof_of_concept');
                $table->string('operating_mode', 80)->default('shopify');
                $table->string('source', 80)->default('manual');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index('plan_key', 'tenant_access_profiles_plan_key_index');
                $table->index('operating_mode', 'tenant_access_profiles_mode_index');
                $table->foreign('tenant_id', 'tenant_access_profiles_tenant_fk')
                    ->references('id')
                    ->on('tenants')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('tenant_access_addons')) {
            Schema::create('tenant_access_addons', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('addon_key', 120);
                $table->boolean('enabled')->default(true);
                $table->string('source', 80)->default('manual');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'addon_key'], 'tenant_access_addons_unique');
                $table->index(['addon_key', 'enabled'], 'tenant_access_addons_key_enabled_index');
                $table->foreign('tenant_id', 'tenant_access_addons_tenant_fk')
                    ->references('id')
                    ->on('tenants')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('tenant_module_states')) {
            Schema::create('tenant_module_states', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('module_key', 120);
                $table->boolean('enabled_override')->nullable();
                $table->string('setup_status', 80)->default('not_started');
                $table->timestamp('setup_completed_at')->nullable();
                $table->boolean('coming_soon_override')->nullable();
                $table->boolean('upgrade_prompt_override')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'module_key'], 'tenant_module_states_unique');
                $table->index(['module_key', 'setup_status'], 'tenant_module_states_key_setup_index');
                $table->foreign('tenant_id', 'tenant_module_states_tenant_fk')
                    ->references('id')
                    ->on('tenants')
                    ->cascadeOnDelete();
            });
        }

        $this->bootstrapExistingTenants();
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_module_states');
        Schema::dropIfExists('tenant_access_addons');
        Schema::dropIfExists('tenant_access_profiles');
    }

    protected function bootstrapExistingTenants(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasTable('tenant_access_profiles')) {
            return;
        }

        $tenantIds = DB::table('tenants')->pluck('id');
        if ($tenantIds->isEmpty()) {
            return;
        }

        $hasShopifyStores = Schema::hasTable('shopify_stores');
        $shopifyTenantIds = $hasShopifyStores
            ? DB::table('shopify_stores')
                ->whereNotNull('tenant_id')
                ->distinct()
                ->pluck('tenant_id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        $shopifyTenantLookup = array_flip($shopifyTenantIds);
        $now = now();
        $rows = [];

        foreach ($tenantIds as $tenantId) {
            $tenantId = (int) $tenantId;
            $rows[] = [
                'tenant_id' => $tenantId,
                'plan_key' => 'shopify_proof_of_concept',
                'operating_mode' => array_key_exists($tenantId, $shopifyTenantLookup) ? 'shopify' : 'direct',
                'source' => 'migration_bootstrap',
                'metadata' => json_encode(['bootstrap' => true]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('tenant_access_profiles')->upsert(
                $chunk,
                ['tenant_id'],
                ['plan_key', 'operating_mode', 'source', 'metadata', 'updated_at']
            );
        }
    }
};

