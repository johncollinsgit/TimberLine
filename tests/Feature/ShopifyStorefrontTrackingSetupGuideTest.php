<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('entitlements.default_plan', 'growth');
});

function grantMessagingModule(Tenant $tenant): void
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

test('message analytics setup guide includes storefront tracking deployment guidance', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Storefront Tracking Tenant',
        'slug' => 'storefront-tracking-tenant',
    ]);
    grantMessagingModule($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $host = rtrim(strtr(base64_encode('admin.shopify.com/store/theforestrystudio'), '+/', '-_'), '=');
    $response = $this->get(route('shopify.app.messaging.analytics', retailEmbeddedSignedQuery([
        'host' => $host,
    ])));

    $response->assertOk()
        ->assertSeeText('Storefront app proxy runtime is ready')
        ->assertSeeText('Theme app embed bundle is present in this repo')
        ->assertSeeText('Web pixel bundle is present in this repo')
        ->assertSeeText('Enable Forestry storefront tracking embed in Theme Editor')
        ->assertSee('https://admin.shopify.com/store/theforestrystudio/themes/current/editor?context=apps', false)
        ->assertSee('https://admin.shopify.com/store/theforestrystudio/settings/customer_events', false)
        ->assertSeeText('npm run shopify:app:deploy');
});

test('message analytics setup guide still exposes pixel connection when scope snapshot is stale', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Storefront Tracking Pending Scope Tenant',
        'slug' => 'storefront-tracking-pending-scope-tenant',
    ]);
    grantMessagingModule($tenant);
    configureEmbeddedRetailStore($tenant->id);

    Http::fake(function (HttpRequest $request) {
        if ($request->url() !== 'https://modernforestry.myshopify.com/admin/api/2026-01/graphql.json') {
            return Http::response([], 404);
        }

        $query = (string) data_get($request->data(), 'query', '');

        if (str_contains($query, 'query BackstageGrantedScopes')) {
            return Http::response([
                'data' => [
                    'currentAppInstallation' => [
                        'accessScopes' => [
                            ['handle' => 'read_products'],
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

        return Http::response(['errors' => [['message' => 'Unexpected query']]], 200);
    });

    $host = rtrim(strtr(base64_encode('admin.shopify.com/store/theforestrystudio'), '+/', '-_'), '=');
    $response = $this->get(route('shopify.app.messaging.analytics', retailEmbeddedSignedQuery([
        'host' => $host,
    ])));

    $response->assertOk()
        ->assertSeeText('Connect Shopify Pixel')
        ->assertSeeText('Pixel status: Disconnected');
});

test('message analytics setup guide prompts for reconnect when shopify token is invalid', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Storefront Tracking Invalid Token Tenant',
        'slug' => 'storefront-tracking-invalid-token-tenant',
    ]);
    grantMessagingModule($tenant);
    configureEmbeddedRetailStore($tenant->id);

    Http::fake(function (HttpRequest $request) {
        if ($request->url() !== 'https://modernforestry.myshopify.com/admin/api/2026-01/graphql.json') {
            return Http::response([], 404);
        }

        return Http::response([
            'errors' => [
                ['message' => '[API] Invalid API key or access token (unrecognized login or wrong password)'],
            ],
        ], 401);
    });

    $host = rtrim(strtr(base64_encode('admin.shopify.com/store/theforestrystudio'), '+/', '-_'), '=');
    $response = $this->get(route('shopify.app.messaging.analytics', retailEmbeddedSignedQuery([
        'host' => $host,
    ])));

    $response->assertOk()
        ->assertSeeText('Reconnect Shopify required')
        ->assertSeeText('Reconnect Shopify')
        ->assertDontSeeText('Connect Shopify Pixel');
});

test('message analytics setup guide treats missing shopify web pixel as disconnected', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Storefront Tracking Missing Pixel Tenant',
        'slug' => 'storefront-tracking-missing-pixel-tenant',
    ]);
    grantMessagingModule($tenant);
    configureEmbeddedRetailStore($tenant->id);

    Http::fake(function (HttpRequest $request) {
        if ($request->url() !== 'https://modernforestry.myshopify.com/admin/api/2026-01/graphql.json') {
            return Http::response([], 404);
        }

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
                'errors' => [
                    ['message' => 'No web pixel was found for this app.', 'path' => ['webPixel']],
                ],
                'data' => [
                    'webPixel' => null,
                ],
            ], 200);
        }

        return Http::response(['errors' => [['message' => 'Unexpected query']]], 200);
    });

    $host = rtrim(strtr(base64_encode('admin.shopify.com/store/theforestrystudio'), '+/', '-_'), '=');
    $response = $this->get(route('shopify.app.messaging.analytics', retailEmbeddedSignedQuery([
        'host' => $host,
    ])));

    $response->assertOk()
        ->assertSeeText('Pixel status: Disconnected')
        ->assertSeeText('Connect Shopify Pixel')
        ->assertDontSeeText('No web pixel was found for this app');
});
