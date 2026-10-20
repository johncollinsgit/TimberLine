<?php

namespace App\Console\Commands;

use App\Models\Marketplace\Audit;
use App\Models\Marketplace\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarketplacePrepareStore extends Command
{
    protected $signature = 'marketplace:prepare-store {--owner= : Existing active verified owner user ID} {--tenant= : Existing dedicated workspace ID, or create a new one}';

    protected $description = 'Prepare the dedicated Everbranch merchant workspace without connecting a provider or enabling checkout';

    public function handle(): int
    {
        $host = strtolower((string) config('marketplace.host'));
        $owner = User::find((int) $this->option('owner'));
        if (! $owner?->is_active || ! $owner->email_verified_at || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $this->error('Configure the verified marketplace host and supply an existing active verified owner ID.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($host, $owner): void {
            if ($existing = Store::where('host', $host)->first()) {
                abort_unless($owner->tenants()->whereKey($existing->tenant_id)->wherePivot('membership_active', true)->wherePivotIn('role', ['owner', 'tenant_owner', 'admin'])->exists(), 403);

                return;
            }
            if ($id = $this->option('tenant')) {
                $tenant = $owner->tenants()->whereKey((int) $id)->wherePivot('membership_active', true)->wherePivotIn('role', ['owner', 'tenant_owner', 'admin'])->firstOrFail();
            } else {
                abort_if(Tenant::where('slug', 'markets-and-makers')->exists(), 409, 'Use the existing dedicated workspace explicitly.');
                $tenant = Tenant::create(['name' => 'Markets and Makers', 'slug' => 'markets-and-makers']);
                \App\Models\TenantAccessProfile::firstOrCreate(['tenant_id' => $tenant->id], ['operating_mode' => 'direct', 'source' => 'operator', 'metadata' => ['dedicated_marketplace_merchant' => true, 'billing_impact_cents' => 0]]);
                $tenant->users()->attach($owner->id, ['role' => 'owner', 'membership_active' => true]);
            }
            $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Markets and Makers', 'host' => $host, 'verified_at' => now()]);
            \App\Models\TenantModuleEntitlement::updateOrCreate(['tenant_id' => $tenant->id, 'module_key' => 'markets_and_makers'], ['availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included', 'price_override_cents' => 0, 'currency' => 'USD', 'entitlement_source' => 'operator', 'price_source' => 'operator', 'created_by' => $owner->id, 'updated_by' => $owner->id, 'metadata' => ['merchant_store_id' => $store->id, 'billing_impact_cents' => 0]]);
            app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($tenant->id, $owner->id, 'marketplace.module_granted', targetType: 'tenant_module_entitlement', targetId: 'markets_and_makers', confirmation: ['dedicated_merchant_store' => true], beforeState: null, afterState: ['module' => 'markets_and_makers', 'enabled' => true, 'billing_status' => 'included'], result: ['billing_impact_cents' => 0, 'currency' => 'USD']);
            Audit::create(['actor_user_id' => $owner->id, 'action' => 'merchant.store_prepared', 'subject_id' => $store->id, 'details' => ['tenant_id' => $tenant->id, 'host' => $host, 'checkout_enabled' => false]]);
            app(\App\Services\Marketplace\MarketplaceAccess::class)->settings()->forceFill(['checkout_enabled' => false])->save();
        });
        $this->info('Dedicated Everbranch merchant store prepared. Payment onboarding and checkout remain off.');

        return self::SUCCESS;
    }
}
