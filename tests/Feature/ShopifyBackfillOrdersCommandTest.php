<?php

use App\Jobs\SyncMarketingProfileFromOrder;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Services\Shopify\ShopifyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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

function seedRetailShopifyStoreForBackfill(): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => 'Retail Rewards Tenant',
        'slug' => 'retail-rewards-tenant',
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'oauth-token',
        'scopes' => 'read_orders,read_all_orders,read_customers',
        'installed_at' => now(),
    ]);

    return $tenant;
}

/**
 * @return array<string,mixed>
 */
function fakeShopifyOrderPayload(int $id, string $customerId, string $email, string $createdAt): array
{
    return [
        'id' => $id,
        'name' => '#'.$id,
        'created_at' => $createdAt,
        'email' => $email,
        'phone' => null,
        'line_items' => [],
        'customer' => [
            'id' => $customerId,
            'email' => $email,
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'phone' => null,
            'default_address' => [
                'name' => 'Test Customer',
            ],
        ],
        'shipping_address' => [
            'name' => 'Test Customer',
            'email' => $email,
            'phone' => null,
        ],
        'billing_address' => [
            'name' => 'Test Customer',
            'email' => $email,
            'phone' => null,
        ],
        'subtotal_price' => '0.00',
        'total_discounts' => '0.00',
        'total_tax' => '0.00',
        'total_shipping_price_set' => [
            'shop_money' => ['amount' => '0.00', 'currency_code' => 'USD'],
        ],
        'current_total_price' => '0.00',
        'currency' => 'USD',
    ];
}

test('shopify historical backfill imports orders and canonical links without queuing the standard profile sync job', function () {
    $tenant = seedRetailShopifyStoreForBackfill();

    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/orders.json*' => Http::response([
            'orders' => [
                fakeShopifyOrderPayload(1001, 'cust-1001', 'alpha@example.com', '2026-01-05T10:00:00Z'),
                fakeShopifyOrderPayload(1002, 'cust-1002', 'beta@example.com', '2026-01-06T10:00:00Z'),
            ],
        ], 200),
    ]);

    Queue::fake();

    $exit = Artisan::call('shopify:backfill-orders', [
        'store' => 'retail',
        '--created-since' => '2026-01-01',
        '--created-until' => '2026-01-31',
        '--window-days' => 60,
    ]);

    expect($exit)->toBe(0);

    expect(Order::query()->count())->toBe(2)
        ->and(MarketingProfile::query()->count())->toBe(2)
        ->and(MarketingProfileLink::query()->where('source_type', 'order')->count())->toBe(2)
        ->and(MarketingProfileLink::query()->where('source_type', 'shopify_customer')->count())->toBe(2)
        ->and(MarketingImportRun::query()->where('type', 'shopify_orders_backfill')->where('tenant_id', $tenant->id)->where('status', 'completed')->count())->toBe(1);

    Queue::assertNotPushed(SyncMarketingProfileFromOrder::class);
});

test('shopify historical backfill resumes from a marketing import run checkpoint', function () {
    $tenant = seedRetailShopifyStoreForBackfill();

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, 'created_at_min=2026-01-01') && str_contains($url, 'created_at_max=2026-01-30')) {
            return Http::response([
                'orders' => [
                    fakeShopifyOrderPayload(2001, 'cust-2001', 'resume-alpha@example.com', '2026-01-05T10:00:00Z'),
                ],
            ], 200);
        }

        if (str_contains($url, 'created_at_min=2026-01-31') && str_contains($url, 'created_at_max=2026-02-05')) {
            return Http::response([
                'orders' => [
                    fakeShopifyOrderPayload(2002, 'cust-2002', 'resume-beta@example.com', '2026-01-06T10:00:00Z'),
                ],
            ], 200);
        }

        return Http::response(['orders' => []], 200);
    });

    $exit = Artisan::call('shopify:backfill-orders', [
        'store' => 'retail',
        '--created-since' => '2026-01-01',
        '--created-until' => '2026-02-05',
        '--window-days' => 30,
        '--limit' => 1,
    ]);

    expect($exit)->toBe(0);

    $run = MarketingImportRun::query()
        ->where('type', 'shopify_orders_backfill')
        ->where('tenant_id', $tenant->id)
        ->latest('id')
        ->firstOrFail();

    expect($run->status)->toBe('stopped')
        ->and((string) data_get($run->summary, 'checkpoint.window_start'))->toContain('2026-01-31T00:00:00')
        ->and(Order::query()->count())->toBe(1);

    $exit = Artisan::call('shopify:backfill-orders', [
        'store' => 'retail',
        '--resume-run-id' => $run->id,
    ]);

    expect($exit)->toBe(0);

    $run->refresh();

    expect($run->status)->toBe('completed')
        ->and(Order::query()->count())->toBe(2)
        ->and(MarketingProfileLink::query()->where('source_type', 'order')->count())->toBe(2)
        ->and((string) data_get($run->summary, 'checkpoint.next_page_url', ''))->toBe('');
});

test('shopify order history audit reports remote, local, and linked counts', function () {
    $tenant = seedRetailShopifyStoreForBackfill();

    $firstOrder = Order::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 3001,
        'order_number' => '#3001',
        'status' => 'complete',
    ]);
    $secondOrder = Order::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'shopify_retail',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 3002,
        'order_number' => '#3002',
        'status' => 'complete',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'audit@example.com',
        'normalized_email' => 'audit@example.com',
    ]);

    MarketingProfileLink::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'source_type' => 'order',
        'source_id' => (string) $firstOrder->id,
    ]);
    MarketingProfileLink::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'source_type' => 'order',
        'source_id' => (string) $secondOrder->id,
    ]);

    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/orders/count.json*' => Http::response([
            'count' => 5,
        ], 200),
    ]);

    $exit = Artisan::call('shopify:audit-order-history', [
        'store' => 'retail',
    ]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('store=retail')
        ->and(Order::query()->where('tenant_id', $tenant->id)->where('shopify_store_key', 'retail')->count())->toBe(2)
        ->and(MarketingProfileLink::query()->where('tenant_id', $tenant->id)->where('source_type', 'order')->count())->toBe(2);
});

test('shopify client preserves scalar count payloads for audit endpoints', function () {
    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/orders/count.json*' => Http::response([
            'count' => 5,
        ], 200),
    ]);

    $client = new ShopifyClient('retail-test.myshopify.com', 'oauth-token', '2026-01');

    expect($client->get('orders/count.json', ['status' => 'any']))->toBe([
        'count' => 5,
    ]);
});
