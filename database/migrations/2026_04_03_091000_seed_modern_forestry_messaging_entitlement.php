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

        $tenant = DB::table('tenants')
            ->where('slug', 'modern-forestry')
            ->first(['id']);

        if (! $tenant) {
            return;
        }

        $tenantId = (int) $tenant->id;
        $now = now();

        DB::table('tenant_module_entitlements')->updateOrInsert(
            [
                'tenant_id' => $tenantId,
                'module_key' => 'messaging',
            ],
            [
                'availability_status' => 'available',
                'enabled_status' => 'enabled',
                'billing_status' => 'add_on_comped',
                'price_override_cents' => 0,
                'currency' => 'USD',
                'entitlement_source' => 'modern_forestry_default',
                'price_source' => 'catalog_default',
                'notes' => 'Enabled by default for Modern Forestry embedded messaging workspace.',
                'metadata' => json_encode([
                    'source' => 'modern_forestry_default',
                    'module' => 'messaging',
                ], JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        if (Schema::hasTable('tenant_module_states')) {
            $stateRow = DB::table('tenant_module_states')
                ->where('tenant_id', $tenantId)
                ->where('module_key', 'messaging')
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
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasTable('tenant_module_entitlements')) {
            return;
        }

        $tenant = DB::table('tenants')
            ->where('slug', 'modern-forestry')
            ->first(['id']);

        if (! $tenant) {
            return;
        }

        DB::table('tenant_module_entitlements')
            ->where('tenant_id', (int) $tenant->id)
            ->where('module_key', 'messaging')
            ->where('entitlement_source', 'modern_forestry_default')
            ->delete();
    }
};
