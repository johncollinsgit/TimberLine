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

        $tenantId = DB::table('tenants')->where('slug', 'collins-electric')->value('id');
        if (! $tenantId) {
            return;
        }

        $now = now();
        DB::table('tenant_module_entitlements')->updateOrInsert(
            ['tenant_id' => $tenantId, 'module_key' => 'messaging'],
            [
                'availability_status' => 'available',
                'enabled_status' => 'enabled',
                'billing_status' => 'custom_contract',
                'price_override_cents' => 0,
                'currency' => 'USD',
                'entitlement_source' => 'collins_electric_launch_partner',
                'price_source' => 'catalog_default',
                'notes' => 'Messaging Branch enabled for Collins customer communications. Individual channel availability remains consent and provider-readiness gated.',
                'metadata' => json_encode([
                    'launch_scope' => 'collins_electric',
                    'sms_requires_verified_readiness' => true,
                ], JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        if (! Schema::hasTable('tenant_module_states')) {
            return;
        }

        $state = DB::table('tenant_module_states')
            ->where('tenant_id', $tenantId)
            ->where('module_key', 'messaging')
            ->first(['id', 'enabled_override']);

        if ($state && is_numeric($state->enabled_override) && (int) $state->enabled_override === 0) {
            DB::table('tenant_module_states')->where('id', $state->id)->update([
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

        $tenantId = DB::table('tenants')->where('slug', 'collins-electric')->value('id');
        if ($tenantId) {
            DB::table('tenant_module_entitlements')
                ->where('tenant_id', $tenantId)
                ->where('module_key', 'messaging')
                ->where('entitlement_source', 'collins_electric_launch_partner')
                ->delete();
        }
    }
};
