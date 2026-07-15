<?php

use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleState;
use App\Models\TenantWholesaleSetting;
use App\Models\User;
use App\Services\Wholesale\WholesaleModuleSetupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

test('tenant admin can activate wholesale operations for a confirmed tenant-owned store', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Sample Merchant', 'slug' => 'sample-merchant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
    $store = ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'sample-wholesale',
        'store_role' => 'retail',
        'shop_domain' => 'sample-wholesale.myshopify.com',
        'access_token' => 'token',
        'installed_at' => now(),
    ]);

    $setting = app(WholesaleModuleSetupService::class)->configure(
        (int) $tenant->id,
        (int) $store->id,
        true,
        (int) $admin->id
    );

    expect($setting->tenant_id)->toBe((int) $tenant->id)
        ->and($setting->shopify_store_id)->toBe((int) $store->id)
        ->and($store->fresh()->store_role)->toBe('wholesale')
        ->and(TenantModuleState::query()->where('tenant_id', $tenant->id)->where('module_key', 'wholesale_operations')->value('setup_status'))->toBe('configured');
    $this->assertDatabaseHas('landlord_operator_actions', [
        'tenant_id' => $tenant->id,
        'actor_user_id' => $admin->id,
        'action_type' => 'wholesale_module_setup',
        'target_type' => 'tenant_wholesale_setting',
    ]);
    expect(Artisan::call('wholesale:suggestions:refresh', ['--tenant' => $tenant->slug]))
        ->toBe(Command::SUCCESS);
});

test('wholesale setup rejects cross-tenant stores and non-admin actors', function (): void {
    expect(Artisan::call('wholesale:suggestions:refresh'))->toBe(Command::FAILURE);
    $tenant = Tenant::query()->create(['name' => 'Eligible Merchant', 'slug' => 'eligible-merchant']);
    $other = Tenant::query()->create(['name' => 'Other Merchant', 'slug' => 'other-merchant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);
    $member = User::factory()->create(['is_active' => true]);
    $member->tenants()->attach($tenant->id, ['role' => 'member']);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
    $store = ShopifyStore::query()->create([
        'tenant_id' => $other->id,
        'store_key' => 'other-wholesale',
        'store_role' => 'retail',
        'shop_domain' => 'other-wholesale.myshopify.com',
        'access_token' => 'token',
        'installed_at' => now(),
    ]);

    expect(fn () => app(WholesaleModuleSetupService::class)->configure(
        (int) $tenant->id,
        (int) $store->id,
        true,
        (int) $admin->id
    ))->toThrow(ValidationException::class);

    $ownedStore = ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'member-wholesale',
        'store_role' => 'retail',
        'shop_domain' => 'member-wholesale.myshopify.com',
        'access_token' => 'token',
        'installed_at' => now(),
    ]);
    expect(fn () => app(WholesaleModuleSetupService::class)->configure(
        (int) $tenant->id,
        (int) $ownedStore->id,
        true,
        (int) $member->id
    ))->toThrow(ValidationException::class);

    expect(TenantWholesaleSetting::query()->count())->toBe(0)
        ->and($store->fresh()->store_role)->toBe('retail');
});
