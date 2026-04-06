<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('entitlements.default_plan', 'growth');
});

function grantMessagingForStorefrontPixel(Tenant $tenant): void
{
    TenantModuleEntitlement::query()->updateOrCreate(
        [
            'tenant_id' => $tenant->id,
            'module_key' => 'messaging',
        ],
        [
            'availability_status' => 'available',
            'enabled_status' => 'enabled',
            'billing_status' => 'add_on_comped',
            'price_override_cents' => 0,
            'currency' => 'USD',
            'entitlement_source' => 'test',
            'price_source' => 'test',
        ]
    );
}

test('embedded messaging endpoint connects the shopify web pixel', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Pixel Connect Tenant',
        'slug' => 'pixel-connect-tenant',
    ]);
    grantMessagingForStorefrontPixel($tenant);
    configureEmbeddedRetailStore($tenant->id);

    ShopifyStore::query()
        ->where('store_key', 'retail')
        ->update([
            'scopes' => 'read_products',
        ]);

    $graphqlCalls = [];

    Http::fake(function (HttpRequest $request) use (&$graphqlCalls) {
        if ($request->url() !== 'https://modernforestry.myshopify.com/admin/api/2026-01/graphql.json') {
            return Http::response([], 404);
        }

        $graphqlCalls[] = $request->data();
        $query = (string) data_get($request->data(), 'query', '');

        if (str_contains($query, 'query BackstageGrantedScopes')) {
            return Http::response([
                'data' => [
                    'currentAppInstallation' => [
                        'accessScopes' => [
                            ['handle' => 'read_pixels'],
                            ['handle' => 'write_pixels'],
                            ['handle' => 'read_customer_events'],
                        ],
                    ],
                ],
            ]);
        }

        if (str_contains($query, 'query BackstageWebPixelStatus')) {
            return Http::response([
                'data' => [
                    'webPixel' => null,
                ],
            ]);
        }

        if (str_contains($query, 'mutation BackstageCreateWebPixel')) {
            expect(data_get($request->data(), 'variables.webPixel.settings.appProxyBase'))->toBe('/apps/forestry');

            return Http::response([
                'data' => [
                    'webPixelCreate' => [
                        'userErrors' => [],
                        'webPixel' => [
                            'id' => 'gid://shopify/WebPixel/1',
                            'settings' => json_encode([
                                'appProxyBase' => '/apps/forestry',
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ]);
        }

        return Http::response([
            'errors' => [['message' => 'Unexpected Shopify GraphQL operation.']],
        ], 200);
    });

    $response = $this
        ->withHeaders([
            'Authorization' => 'Bearer '.retailShopifySessionToken(),
            'Accept' => 'application/json',
        ])
        ->postJson(route('shopify.app.api.messaging.storefront-tracking.connect-pixel'));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('status', 'connected')
        ->assertJsonPath('pixel.connected', true)
        ->assertJsonPath('tracking.web_pixel.connected', true)
        ->assertJsonPath('tracking.web_pixel.status', 'connected')
        ->assertJsonPath('tracking.web_pixel.settings.appProxyBase', '/apps/forestry');

    expect($graphqlCalls)->toHaveCount(3);
});
