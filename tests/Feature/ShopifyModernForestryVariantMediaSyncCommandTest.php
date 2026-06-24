<?php

use App\Models\ShopifyStore;
use App\Models\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.stores.retail.shop', 'modernforestry-test.myshopify.com');
    config()->set('services.shopify.allow_env_token_fallback', false);

    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'modernforestry-test.myshopify.com',
        'access_token' => 'shpat_variant_media_test',
        'scopes' => 'read_products,write_products',
        'installed_at' => now(),
    ]);
});

test('modern forestry variant media sync dry run audits without uploading media', function (): void {
    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyVariantMediaProductsPayload(), 200),
    ]);

    $exitCode = Artisan::call('shopify:sync-modern-forestry-variant-media', [
        '--store' => 'retail',
        '--image-dir' => '/tmp/missing-modern-forestry-media-test',
    ]);

    expect($exitCode)->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('mode=dry-run')
        ->and($output)->toContain('matched_variants=2')
        ->and($output)->toContain('already_attached=1')
        ->and($output)->toContain('missing_media=1')
        ->and($output)->toContain('skipped_unmatched=1')
        ->and($output)->toContain('skipped_ambiguous=1')
        ->and($output)->toContain('products_needing_writes=1');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return str_contains((string) ($payload['query'] ?? ''), 'ModernForestryVariantMediaProducts');
    });
});

test('modern forestry variant media sync can target specific handles', function (): void {
    Http::fake([
        'https://modernforestry-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyVariantMediaProductsPayload(), 200),
    ]);

    $exitCode = Artisan::call('shopify:sync-modern-forestry-variant-media', [
        '--store' => 'retail',
        '--image-dir' => '/tmp/missing-modern-forestry-media-test',
        '--handle' => ['thru-hike', 'coffeehouse'],
    ]);

    expect($exitCode)->toBe(0);

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();
        $variables = $payload['variables'] ?? [];

        return ($variables['query'] ?? null) === 'status:active (handle:thru-hike OR handle:coffeehouse)';
    });
});

/**
 * @return array<string,mixed>
 */
function shopifyVariantMediaProductsPayload(): array
{
    return [
        'data' => [
            'products' => [
                'nodes' => [
                    [
                        'id' => 'gid://shopify/Product/100',
                        'title' => 'Variant Media Candle',
                        'handle' => 'variant-media-candle',
                        'media' => [
                            'nodes' => [
                                [
                                    'id' => 'gid://shopify/MediaImage/800',
                                    'alt' => 'mf-app-variant-size:8oz Modern Forestry 8 oz size reference',
                                    'mediaContentType' => 'IMAGE',
                                    'image' => [
                                        'url' => 'https://cdn.shopify.com/s/files/8oz.png',
                                        'altText' => 'mf-app-variant-size:8oz Modern Forestry 8 oz size reference',
                                    ],
                                ],
                            ],
                        ],
                        'variants' => [
                            'nodes' => [
                                [
                                    'id' => 'gid://shopify/ProductVariant/401',
                                    'title' => '4 oz',
                                    'selectedOptions' => [
                                        ['name' => 'Size', 'value' => '4 oz'],
                                    ],
                                    'media' => ['nodes' => []],
                                ],
                                [
                                    'id' => 'gid://shopify/ProductVariant/402',
                                    'title' => '8 oz',
                                    'selectedOptions' => [
                                        ['name' => 'Size', 'value' => '8 oz'],
                                    ],
                                    'media' => [
                                        'nodes' => [
                                            [
                                                'id' => 'gid://shopify/MediaImage/800',
                                                'alt' => 'mf-app-variant-size:8oz Modern Forestry 8 oz size reference',
                                                'mediaContentType' => 'IMAGE',
                                                'image' => [
                                                    'url' => 'https://cdn.shopify.com/s/files/8oz.png',
                                                    'altText' => 'mf-app-variant-size:8oz Modern Forestry 8 oz size reference',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                [
                                    'id' => 'gid://shopify/ProductVariant/403',
                                    'title' => 'Room Spray',
                                    'selectedOptions' => [
                                        ['name' => 'Size', 'value' => 'Room Spray'],
                                    ],
                                    'media' => ['nodes' => []],
                                ],
                                [
                                    'id' => 'gid://shopify/ProductVariant/404',
                                    'title' => '4 oz / 8 oz sampler',
                                    'selectedOptions' => [
                                        ['name' => 'Size', 'value' => '4 oz / 8 oz sampler'],
                                    ],
                                    'media' => ['nodes' => []],
                                ],
                            ],
                        ],
                    ],
                ],
                'pageInfo' => [
                    'hasNextPage' => false,
                    'endCursor' => null,
                ],
            ],
        ],
    ];
}
