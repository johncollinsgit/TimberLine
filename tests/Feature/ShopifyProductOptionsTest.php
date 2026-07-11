<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\ShopifyProductOptionRuleset;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Services\Shopify\ShopifyProductOptionsService;

function grantProductOptionsEntitlement(Tenant $tenant): void
{
    TenantModuleEntitlement::query()->updateOrCreate(
        ['tenant_id' => $tenant->id, 'module_key' => ShopifyProductOptionsService::MODULE_KEY],
        [
            'availability_status' => 'available',
            'enabled_status' => 'enabled',
            'billing_status' => 'included',
            'currency' => 'USD',
            'entitlement_source' => 'test',
            'price_source' => 'test',
        ]
    );
}

test('shopify product options resolves a matching bundle into required scent fields', function () {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $ruleset = ShopifyProductOptionRuleset::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Three Candle Bundle',
        'option_count' => 3,
        'allowed_values' => ['River Birch', 'Lavender', 'Lava Rock'],
        'require_distinct_values' => true,
        'enabled' => true,
        'source' => 'test',
    ]);
    $ruleset->assignments()->create([
        'tenant_id' => $tenant->id,
        'product_handle' => 'three-candle-bundle',
        'product_url' => 'https://example.test/products/three-candle-bundle',
    ]);

    $payload = app(ShopifyProductOptionsService::class)
        ->storefrontRuleset((int) $tenant->id, null, 'three-candle-bundle');

    expect($payload)
        ->not->toBeNull()
        ->and($payload['option_count'])->toBe(3)
        ->and($payload['require_distinct_values'])->toBeTrue()
        ->and($payload['line_item_property_prefix'])->toBe('Scent')
        ->and($payload['allowed_values'])->toBe(['Lava Rock', 'Lavender', 'River Birch']);
});

test('shopify product options updates product urls and scent values into normalized rules', function () {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $ruleset = ShopifyProductOptionRuleset::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Room Spray Bundle',
        'option_count' => 3,
        'allowed_values' => ['Lavender'],
        'enabled' => true,
        'source' => 'test',
    ]);

    $payload = app(ShopifyProductOptionsService::class)->updateRuleset($ruleset, (int) $tenant->id, [
        'name' => 'Room Spray Bundle',
        'option_count' => 3,
        'allowed_values' => ['Lavender', 'River Birch', 'lavender', ''],
        'product_handles' => [
            'https://theforestrystudio.com/products/three-room-sprays-for-30',
            'three-room-sprays-for-30',
        ],
        'require_distinct_values' => true,
        'enabled' => true,
    ]);

    expect($payload['allowed_values'])->toBe(['Lavender', 'River Birch'])
        ->and($payload['assignments'])->toHaveCount(1)
        ->and($payload['assignments'][0]['product_handle'])->toBe('three-room-sprays-for-30');
});

test('product options is visible as a shopify only embedded module when enabled', function () {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    grantProductOptionsEntitlement($tenant);
    configureEmbeddedRetailStore((int) $tenant->id);

    $response = $this->get(route('shopify.app.product-options', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Everbranch Product Options')
        ->assertSeeText('Shopify only')
        ->assertSeeText('Active · Modern Forestry')
        ->assertViewHas('appNavigation', fn (array $navigation): bool => ($navigation['activeSection'] ?? null) === 'product_options');
});
