<?php

use App\Models\ShopifyImportRun;
use App\Models\ShopifyStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.stores.retail.shop', 'retail-test.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'retail-client');
    config()->set('services.shopify.stores.retail.client_secret', 'retail-secret');
    config()->set('services.shopify.stores.wholesale.shop', 'wholesale-test.myshopify.com');
    config()->set('services.shopify.stores.wholesale.client_id', 'wholesale-client');
    config()->set('services.shopify.stores.wholesale.client_secret', 'wholesale-secret');
    config()->set('services.shopify.allow_env_token_fallback', false);
});

test('shopify import fails with explicit not-installed message when oauth token is missing', function (): void {
    config()->set('services.shopify.stores.retail.access_token', 'legacy-token-should-not-be-used');

    $this->artisan('shopify:import-orders retail --days=1')
        ->expectsOutputToContain('retail store not installed (OAuth token missing). Run /shopify/auth/retail.')
        ->assertExitCode(1);
});

test('shopify customer metafield sync fails with explicit scope message when read_customers is missing', function (): void {
    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'oauth-token',
        'scopes' => 'read_orders,read_products',
        'installed_at' => now(),
    ]);

    $this->artisan('shopify:sync-customer-metafields retail --limit=10')
        ->expectsOutputToContain('retail store scopes insufficient for customer metafield sync (missing Admin read_customers/write_customers). Run /shopify/reinstall/retail.')
        ->assertExitCode(1);
});

test('shopify customer-account scopes do not satisfy admin customer metafield sync gate', function (): void {
    ShopifyStore::query()->create([
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'oauth-token',
        'scopes' => 'read_orders,read_products,customer_read_customers,customer_write_customers',
        'installed_at' => now(),
    ]);

    $this->artisan('shopify:sync-customer-metafields retail --limit=10')
        ->expectsOutputToContain('retail store scopes insufficient for customer metafield sync (missing Admin read_customers/write_customers). Run /shopify/reinstall/retail.')
        ->assertExitCode(1);
});

test('env token fallback remains optional for legacy operation when explicitly enabled', function (): void {
    config()->set('services.shopify.allow_env_token_fallback', true);
    config()->set('services.shopify.stores.retail.access_token', 'legacy-token');

    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/orders.json*' => Http::response([
            'orders' => [],
        ], 200),
    ]);

    $this->artisan('shopify:import-orders retail --days=1 --include-closed')
        ->assertExitCode(0);

    expect(ShopifyImportRun::query()->count())->toBe(1);
});
