<?php

use App\Models\Order;
use App\Models\ShopifyStore;
use App\Models\Tenant;

test('ownership repair binds only the exact modern forestry store and never moves another tenants wholesale records', function (): void {
    $modernForestry = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    $collinsElectric = Tenant::query()->create([
        'name' => 'Collins Electric',
        'slug' => 'collins-electric',
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $collinsElectric->id,
        'store_key' => 'wholesale',
        'shop_domain' => 's2vscq-rf.myshopify.com',
        'access_token' => 'shpat_test',
        'installed_at' => now(),
    ]);

    $wholesaleOrder = Order::factory()->create([
        'tenant_id' => $collinsElectric->id,
        'shopify_store_key' => 'wholesale',
        'shopify_store' => 'wholesale',
        'order_type' => 'wholesale',
    ]);
    $collinsRetailOrder = Order::factory()->create([
        'tenant_id' => $collinsElectric->id,
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'order_type' => 'retail',
    ]);

    $migration = require database_path('migrations/2026_07_15_091000_bind_wholesale_shopify_store_to_modern_forestry.php');
    $migration->up();

    expect((int) ShopifyStore::query()->where('store_key', 'wholesale')->value('tenant_id'))
        ->toBe((int) $modernForestry->id)
        ->and(ShopifyStore::query()->where('store_key', 'wholesale')->value('store_role'))->toBe('wholesale')
        ->and((int) $wholesaleOrder->fresh()->tenant_id)->toBe((int) $collinsElectric->id)
        ->and((int) $collinsRetailOrder->fresh()->tenant_id)->toBe((int) $collinsElectric->id);
});
