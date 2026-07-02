<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasTable('tenant_module_entitlements')) {
            return;
        }

        $tenantId = $this->resolveModernForestryTenantId();
        if ($tenantId === null) {
            return;
        }

        $now = now();

        DB::table('tenant_module_entitlements')->updateOrInsert(
            [
                'tenant_id' => $tenantId,
                'module_key' => 'subscriptions',
            ],
            [
                'availability_status' => 'available',
                'enabled_status' => 'enabled',
                'billing_status' => 'add_on_comped',
                'price_override_cents' => 0,
                'currency' => 'USD',
                'entitlement_source' => 'modern_forestry_subscription_bootstrap',
                'price_source' => 'landlord_pending',
                'notes' => 'Enabled for Modern Forestry subscription migration readiness and Candle Club cutover planning.',
                'metadata' => json_encode([
                    'source' => 'modern_forestry_subscription_bootstrap',
                    'module' => 'subscriptions',
                    'tenant_hint' => 'tenant_1',
                    'requires_cutover_approval' => true,
                    'shopify_source_of_truth' => true,
                ], JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        if (! Schema::hasTable('tenant_module_states')) {
            return;
        }

        $stateRow = DB::table('tenant_module_states')
            ->where('tenant_id', $tenantId)
            ->where('module_key', 'subscriptions')
            ->first(['id', 'enabled_override']);

        if ($stateRow && is_numeric($stateRow->enabled_override) && (int) $stateRow->enabled_override === 0) {
            DB::table('tenant_module_states')
                ->where('id', (int) $stateRow->id)
                ->update([
                    'enabled_override' => null,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasTable('tenant_module_entitlements')) {
            return;
        }

        $tenantId = $this->resolveModernForestryTenantId();
        if ($tenantId === null) {
            return;
        }

        DB::table('tenant_module_entitlements')
            ->where('tenant_id', $tenantId)
            ->where('module_key', 'subscriptions')
            ->where('entitlement_source', 'modern_forestry_subscription_bootstrap')
            ->delete();
    }

    protected function resolveModernForestryTenantId(): ?int
    {
        $tenant = DB::table('tenants')
            ->where('id', 1)
            ->where('slug', 'modern-forestry')
            ->first(['id']);

        if ($tenant && is_numeric($tenant->id)) {
            return (int) $tenant->id;
        }

        return null;
    }
};
