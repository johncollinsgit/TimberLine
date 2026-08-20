<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\ShopifyProductOptionRuleset;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Services\Shopify\ShopifyProductOptionMetafieldSyncService;
use App\Services\Shopify\ShopifyProductOptionsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

test('shopify product options can unassign products and delete their option set', function () {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $service = app(ShopifyProductOptionsService::class);
    $created = $service->createRuleset((int) $tenant->id, [
        'name' => 'Temporary Bundle',
        'option_count' => 2,
        'allowed_values' => ['Lavender'],
        'product_handles' => ['first-bundle', 'second-bundle'],
        'require_distinct_values' => false,
        'enabled' => true,
    ]);

    $ruleset = ShopifyProductOptionRuleset::query()->findOrFail($created['id']);
    $updated = $service->updateRuleset($ruleset, (int) $tenant->id, [
        'name' => 'Temporary Bundle',
        'option_count' => 2,
        'allowed_values' => ['Lavender'],
        'product_handles' => ['second-bundle'],
        'require_distinct_values' => false,
        'enabled' => true,
    ]);

    expect($updated['assignments'])->toHaveCount(1)
        ->and($updated['assignments'][0]['product_handle'])->toBe('second-bundle');

    $service->deleteRuleset($ruleset->fresh(), (int) $tenant->id);

    $this->assertDatabaseMissing('shopify_product_option_rulesets', ['id' => $created['id']]);
    $this->assertDatabaseMissing('shopify_product_option_assignments', ['ruleset_id' => $created['id']]);
});

test('shopify product options moves a product from an old ruleset before assigning it', function () {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $service = app(ShopifyProductOptionsService::class);
    $oldRuleset = ShopifyProductOptionRuleset::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Old bundle options',
        'option_count' => 3,
        'allowed_values' => ['Lavender'],
        'enabled' => true,
        'source' => 'test',
    ]);
    $oldRuleset->assignments()->create(['tenant_id' => $tenant->id, 'product_handle' => 'bundle']);

    $created = $service->createRuleset((int) $tenant->id, [
        'name' => '16oz bundle options',
        'option_count' => 3,
        'allowed_values' => ['River Birch'],
        'product_handles' => ['bundle'],
        'require_distinct_values' => true,
        'enabled' => true,
    ]);

    $this->assertDatabaseMissing('shopify_product_option_assignments', [
        'ruleset_id' => $oldRuleset->id,
        'product_handle' => 'bundle',
    ]);
    $payload = $service->storefrontRuleset((int) $tenant->id, null, 'bundle');
    expect($payload)->not->toBeNull()
        ->and($payload['id'])->toBe($created['id'])
        ->and($payload['option_count'])->toBe(3);
});

test('shopify product options fails closed when legacy duplicate rulesets remain', function () {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    foreach (['First options', 'Second options'] as $name) {
        $ruleset = ShopifyProductOptionRuleset::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'option_count' => 3,
            'allowed_values' => ['Lavender'],
            'enabled' => true,
            'source' => 'test',
        ]);
        $ruleset->assignments()->create(['tenant_id' => $tenant->id, 'product_handle' => 'conflicted-bundle']);
    }

    expect(app(ShopifyProductOptionsService::class)
        ->storefrontRuleset((int) $tenant->id, null, 'conflicted-bundle'))
        ->toBeNull();
});

test('all Modern Forestry bundle handles resolve to their intended selector rules', function () {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $expected = [
        'Room Spray Bundle' => [3, true, ['three-room-sprays-for-30']],
        'Buy 2 Get 1 Free' => [3, true, ['buy-2-get-1-free-4oz-sale']],
        'Teacher Candles' => [2, false, ['teacher-candles']],
        'Build Your Own Flight' => [6, true, ['build-your-own-flight']],
        'Bulk Discount Bundles - 12 options' => [12, false, [
            'bulk-discount-4oz-soy-candles-case-of-12-modern-forestry-soy-candles-in-greenville-sc',
            'bulk-discount-8oz-soy-candles',
            'bulk-discount-16oz-soy-candles-case-of-12',
        ]],
        'Wax Melt Bundle - 5 options' => [5, true, ['5-wax-melts-bundle']],
        'Bundles with 3 options' => [3, true, [
            '4oz-3-soy-candle-bundle-save-on-three-soy-candle-by-modern-forestry',
            '8oz-3-soy-candle-bundle-save-on-three-soy-candle-by-modern-forestry',
            'wax-melt-bundle-soy-tarts-wax-tarts-by-modern-forestry',
            'bundle',
        ]],
    ];

    foreach ($expected as $name => [$count, $distinct, $handles]) {
        $ruleset = ShopifyProductOptionRuleset::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'option_count' => $count,
            'allowed_values' => ['Lavender', 'River Birch'],
            'require_distinct_values' => $distinct,
            'enabled' => true,
            'source' => 'test',
        ]);
        foreach ($handles as $handle) {
            $ruleset->assignments()->create(['tenant_id' => $tenant->id, 'product_handle' => $handle]);
            $payload = app(ShopifyProductOptionsService::class)->storefrontRuleset((int) $tenant->id, null, $handle);
            expect($payload)->not->toBeNull()
                ->and($payload['option_count'])->toBe($count)
                ->and($payload['require_distinct_values'])->toBe($distinct);
        }
    }

    expect(app(ShopifyProductOptionsService::class)
        ->storefrontRuleset((int) $tenant->id, null, 'apple-candle-bundle'))
        ->toBeNull();
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

    $this->get(route('shopify.embedded.product-options', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Everbranch Product Options');
});

test('product option rules sync required checkout validation into assigned shopify products', function () {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'store_role' => 'retail',
        'shop_domain' => 'modernforestry.myshopify.com',
        'access_token' => 'test-token',
    ]);
    $ruleset = ShopifyProductOptionRuleset::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Three Candle Bundle',
        'option_count' => 3,
        'allowed_values' => ['Lavender', 'River Birch', 'Lava Rock'],
        'require_distinct_values' => true,
        'enabled' => true,
        'source' => 'test',
    ]);
    $assignment = $ruleset->assignments()->create([
        'tenant_id' => $tenant->id,
        'product_handle' => 'three-candle-bundle',
    ]);

    Http::fake([
        'https://modernforestry.myshopify.com/admin/api/2026-01/graphql.json' => Http::sequence()
            ->push([
                'data' => [
                    'products' => [
                        'nodes' => [[
                            'id' => 'gid://shopify/Product/123',
                            'handle' => 'three-candle-bundle',
                            'onlineStoreUrl' => 'https://example.test/products/three-candle-bundle',
                        ]],
                    ],
                ],
            ])
            ->push([
                'data' => [
                    'metafieldsSet' => [
                        'metafields' => [[
                            'ownerType' => 'PRODUCT',
                            'namespace' => 'everbranch',
                            'key' => 'bundle_scent_rule',
                        ]],
                        'userErrors' => [],
                    ],
                ],
            ]),
    ]);

    $result = app(ShopifyProductOptionMetafieldSyncService::class)
        ->syncRuleset($ruleset);

    expect($result)->toMatchArray(['synced' => 1, 'cleared' => 0, 'errors' => []])
        ->and($assignment->fresh()->shopify_product_id)->toBe('123');

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();
        $metafield = data_get($payload, 'variables.metafields.0');
        $rule = json_decode((string) ($metafield['value'] ?? ''), true);

        return str_contains((string) ($payload['query'] ?? ''), 'metafieldsSet')
            && ($metafield['namespace'] ?? null) === 'everbranch'
            && ($metafield['key'] ?? null) === 'bundle_scent_rule'
            && ($rule['option_count'] ?? null) === 3
            && ($rule['require_distinct_values'] ?? null) === true
            && ($rule['allowed_values'] ?? null) === ['Lavender', 'River Birch', 'Lava Rock'];
    });
});
