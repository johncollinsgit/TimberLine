<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_product_option_rulesets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 160);
            $table->unsignedSmallInteger('option_count')->default(1);
            $table->json('allowed_values');
            $table->boolean('require_distinct_values')->default(false);
            $table->boolean('enabled')->default(true);
            $table->string('source', 80)->default('everbranch');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'shopify_option_rulesets_tenant_name_unique');
            $table->index(['tenant_id', 'enabled'], 'shopify_option_rulesets_tenant_enabled_index');
            $table->foreign('tenant_id', 'shopify_option_rulesets_tenant_fk')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
        });

        Schema::create('shopify_product_option_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('ruleset_id');
            $table->string('shopify_product_id', 80)->nullable();
            $table->string('product_handle', 190)->nullable();
            $table->text('product_url')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'shopify_product_id'], 'shopify_option_assignments_product_id_index');
            $table->index(['tenant_id', 'product_handle'], 'shopify_option_assignments_handle_index');
            $table->foreign('tenant_id', 'shopify_option_assignments_tenant_fk')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table->foreign('ruleset_id', 'shopify_option_assignments_ruleset_fk')
                ->references('id')
                ->on('shopify_product_option_rulesets')
                ->cascadeOnDelete();
        });

        $tenantId = $this->modernForestryTenantId();
        if ($tenantId === null) {
            return;
        }

        $this->activateModule($tenantId);
        $this->seedInfiniteOptionsRulesets($tenantId);
    }

    public function down(): void
    {
        $tenantId = $this->modernForestryTenantId();
        if ($tenantId !== null && Schema::hasTable('tenant_module_entitlements')) {
            DB::table('tenant_module_entitlements')
                ->where('tenant_id', $tenantId)
                ->where('module_key', 'shopify_product_options')
                ->where('entitlement_source', 'modern_forestry_infinite_options_replacement')
                ->delete();
        }

        if ($tenantId !== null && Schema::hasTable('tenant_module_states')) {
            DB::table('tenant_module_states')
                ->where('tenant_id', $tenantId)
                ->where('module_key', 'shopify_product_options')
                ->where('metadata', 'like', '%infinite_options_replacement%')
                ->delete();
        }

        Schema::dropIfExists('shopify_product_option_assignments');
        Schema::dropIfExists('shopify_product_option_rulesets');
    }

    private function activateModule(int $tenantId): void
    {
        $now = now();

        if (Schema::hasTable('tenant_module_entitlements')) {
            DB::table('tenant_module_entitlements')->updateOrInsert(
                ['tenant_id' => $tenantId, 'module_key' => 'shopify_product_options'],
                [
                    'availability_status' => 'available',
                    'enabled_status' => 'enabled',
                    'billing_status' => 'included',
                    'price_override_cents' => 0,
                    'currency' => 'USD',
                    'entitlement_source' => 'modern_forestry_infinite_options_replacement',
                    'price_source' => 'internal_alpha',
                    'notes' => 'Shopify-only product scent options enabled for Modern Forestry.',
                    'metadata' => json_encode([
                        'source' => 'infinite_options_replacement',
                        'shopify_only' => true,
                    ], JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        if (Schema::hasTable('tenant_module_states')) {
            DB::table('tenant_module_states')->updateOrInsert(
                ['tenant_id' => $tenantId, 'module_key' => 'shopify_product_options'],
                [
                    'enabled_override' => true,
                    'setup_status' => 'configured',
                    'setup_completed_at' => $now,
                    'coming_soon_override' => false,
                    'upgrade_prompt_override' => false,
                    'metadata' => json_encode([
                        'source' => 'infinite_options_replacement',
                        'shopify_only' => true,
                    ], JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function seedInfiniteOptionsRulesets(int $tenantId): void
    {
        $values = [
            'Appalachian Maple Bourbon',
            'Beard',
            'Cinna Bakery',
            'Enchanted Forest',
            'Eucalyptus Mint',
            'Fireside Flannel',
            'Lava Rock',
            'Lavender',
            'Nightfall',
            'Orange Pomander',
            'Orange Sandalwood',
            "Papa's Pipe",
            'Patchouli Teakwood',
            'Peach Orchard',
            'Pomegranate Cider',
            'Pumpkin Chai',
            'Pumpkin Streusel',
            'River Birch',
            'Rosemary',
            'Room Refresh',
            "Sippin' Sunshine",
            'Strawberry Jam',
            'Summer Linen',
            'Sunwashed',
            'Thru Hike',
            'Thundershowers',
            'Vanilla',
            'Vanilla Latte',
            'Violet Spice',
            'Watermelon',
            'White Tea',
        ];

        $rulesets = [
            ['name' => 'Room Spray Bundle', 'count' => 3, 'handle' => 'three-room-sprays-for-30', 'distinct' => true],
            ['name' => 'Buy 2 Get 1 Free', 'count' => 3, 'handle' => null, 'distinct' => true],
            ['name' => 'Teacher Candles', 'count' => 2, 'handle' => null, 'distinct' => false],
            ['name' => 'Build Your Own Flight', 'count' => 6, 'handle' => null, 'distinct' => true],
            ['name' => 'Bulk Discount Bundles - 12 options', 'count' => 12, 'handle' => null, 'distinct' => false],
            ['name' => 'Wax Melt Bundle - 5 options', 'count' => 5, 'handle' => null, 'distinct' => true],
            ['name' => 'Bundles with 3 options', 'count' => 3, 'handle' => '4oz-3-soy-candle-bundle', 'distinct' => true],
        ];

        if (Schema::hasTable('scents')) {
            foreach ($values as $index => $value) {
                DB::table('scents')->updateOrInsert(
                    ['name' => $value],
                    [
                        'display_name' => $value,
                        'is_active' => true,
                        'sort_order' => $index + 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        foreach ($rulesets as $ruleset) {
            $now = now();
            DB::table('shopify_product_option_rulesets')->updateOrInsert(
                ['tenant_id' => $tenantId, 'name' => $ruleset['name']],
                [
                    'option_count' => $ruleset['count'],
                    'allowed_values' => json_encode($values, JSON_UNESCAPED_SLASHES),
                    'require_distinct_values' => $ruleset['distinct'],
                    'enabled' => true,
                    'source' => 'infinite_options_screenshot',
                    'metadata' => json_encode([
                        'import_status' => $ruleset['handle'] ? 'product_matched_from_screenshot' : 'needs_product_assignment',
                    ], JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            if (! $ruleset['handle']) {
                continue;
            }

            $rulesetId = DB::table('shopify_product_option_rulesets')
                ->where('tenant_id', $tenantId)
                ->where('name', $ruleset['name'])
                ->value('id');

            DB::table('shopify_product_option_assignments')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                    'ruleset_id' => $rulesetId,
                    'product_handle' => $ruleset['handle'],
                ],
                [
                    'product_url' => 'https://theforestrystudio.com/products/'.$ruleset['handle'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function modernForestryTenantId(): ?int
    {
        if (! Schema::hasTable('tenants')) {
            return null;
        }

        $id = DB::table('tenants')
            ->where('slug', 'modern-forestry')
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }
};
