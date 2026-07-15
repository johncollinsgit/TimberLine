<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MODERN_FORESTRY_WHOLESALE_SHOP = 's2vscq-rf.myshopify.com';

    public function up(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasTable('shopify_stores')) {
            return;
        }

        $tenantId = (int) DB::table('tenants')->where('slug', 'modern-forestry')->value('id');
        if ($tenantId <= 0) {
            return;
        }

        $store = DB::table('shopify_stores')
            ->whereRaw('LOWER(shop_domain) = ?', [self::MODERN_FORESTRY_WHOLESALE_SHOP])
            ->first();
        if (! $store) {
            return;
        }

        $now = now();
        $storeUpdate = ['tenant_id' => $tenantId, 'updated_at' => $now];
        if (Schema::hasColumn('shopify_stores', 'store_role')) {
            $storeUpdate['store_role'] = 'wholesale';
        }
        DB::table('shopify_stores')->where('id', $store->id)->update($storeUpdate);

        if (Schema::hasTable('tenant_wholesale_settings')) {
            DB::table('tenant_wholesale_settings')->updateOrInsert(
                ['tenant_id' => $tenantId],
                [
                    'shopify_store_id' => $store->id,
                    'qualification_mode' => 'dedicated_store',
                    'product_categories' => json_encode(['candles', 'home fragrance', 'gifts', 'nature-inspired home goods']),
                    'discovery_keywords' => json_encode(['forest-inspired', 'Appalachian', 'small batch']),
                    'website_enrichment_enabled' => false,
                    'confirmed_at' => $now,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        if (Schema::hasTable('tenant_discovery_profiles')) {
            $profile = DB::table('tenant_discovery_profiles')->where('tenant_id', $tenantId)->first();
            if ($profile) {
                $keywords = array_values(array_unique(array_merge(
                    $this->jsonList($profile->brand_keywords ?? null),
                    ['modern forestry', 'wholesale candles', 'forest-inspired candles', 'Appalachian']
                )));
                $signals = json_decode((string) ($profile->merchant_signals ?? '{}'), true);
                $signals = is_array($signals) ? $signals : [];
                $signals['product_categories'] = array_values(array_unique(array_merge(
                    (array) ($signals['product_categories'] ?? []),
                    ['candles', 'home fragrance', 'gifts', 'nature-inspired home goods']
                )));
                $signals['brand_descriptors'] = array_values(array_unique(array_merge(
                    (array) ($signals['brand_descriptors'] ?? []),
                    ['forest-inspired', 'Appalachian', 'small batch']
                )));
                DB::table('tenant_discovery_profiles')->where('id', $profile->id)->update([
                    'brand_keywords' => json_encode($keywords),
                    'merchant_signals' => json_encode($signals),
                    'updated_at' => $now,
                ]);
            }
        }

        if (Schema::hasTable('tenant_module_states')) {
            DB::table('tenant_module_states')->updateOrInsert(
                ['tenant_id' => $tenantId, 'module_key' => 'wholesale_operations'],
                [
                    'enabled_override' => true,
                    'setup_status' => 'configured',
                    'setup_completed_at' => $now,
                    'metadata' => json_encode(['shopify_store_id' => (int) $store->id, 'source' => 'modern_forestry_wholesale_store_backfill']),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        // Legacy application aliases are Modern Forestry-specific. No order,
        // import, prospect, or other tenant-owned wholesale records are reassigned.
        if (Schema::hasTable('customer_access_requests')
            && Schema::hasColumn('customer_access_requests', 'tenant_id')
            && Schema::hasColumn('customer_access_requests', 'requested_tenant_slug')) {
            DB::table('customer_access_requests')
                ->whereNull('tenant_id')
                ->whereIn('requested_tenant_slug', ['modern-forestry', 'modern-forestry-wholesale'])
                ->update(['tenant_id' => $tenantId, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        // This narrow ownership designation is intentionally irreversible.
    }

    /** @return array<int,string> */
    private function jsonList(mixed $value): array
    {
        $decoded = json_decode((string) ($value ?? '[]'), true);

        return array_values(array_filter(array_map('strval', is_array($decoded) ? $decoded : [])));
    }
};
