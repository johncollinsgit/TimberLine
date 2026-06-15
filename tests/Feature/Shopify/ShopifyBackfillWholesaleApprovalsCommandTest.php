<?php

use App\Models\CustomerAccessRequest;
use App\Models\ShopifyStore;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.stores.wholesale.shop', 'wholesale-test.myshopify.com');
    config()->set('services.shopify.stores.wholesale.client_id', 'wholesale-client');
    config()->set('services.shopify.stores.wholesale.client_secret', 'wholesale-secret');
    config()->set('services.shopify.allow_env_token_fallback', false);
});

function seedWholesaleShopifyStoreForBackfillCommandTest(): void
{
    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'wholesale'],
        [
            'tenant_id' => null,
            'shop_domain' => 'wholesale-test.myshopify.com',
            'access_token' => 'wholesale-token',
            'scopes' => 'read_customers,write_customers',
            'installed_at' => now(),
        ]
    );
}

test('shopify backfill wholesale approvals tags approved applicants', function (): void {
    seedWholesaleShopifyStoreForBackfillCommandTest();

    CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'approved',
        'name' => 'Ops Existing',
        'email' => 'ops-existing@example.com',
        'company' => 'Existing Boutique',
        'requested_tenant_slug' => 'acme-existing',
    ]);

    CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'approved',
        'name' => 'Ops New',
        'email' => 'ops-new@example.com',
        'company' => 'New Boutique',
        'requested_tenant_slug' => 'acme-new',
    ]);

    CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'pending',
        'name' => 'Ops Pending',
        'email' => 'ops-pending@example.com',
        'company' => 'Pending Boutique',
        'requested_tenant_slug' => 'acme-pending',
    ]);

    $requests = [];
    $callIndex = 0;
    Http::fake(function (Request $request) use (&$requests, &$callIndex) {
        $payload = json_decode($request->body(), true);
        $query = (string) data_get($payload, 'query', '');
        $variables = (array) data_get($payload, 'variables', []);
        $requests[] = compact('query', 'variables');

        return match ($callIndex++) {
            0 => Http::response([
                'data' => [
                    'customers' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Customer/101',
                                    'legacyResourceId' => '101',
                                    'email' => 'ops-existing@example.com',
                                    'firstName' => 'Ops',
                                    'lastName' => 'Existing',
                                    'phone' => null,
                                    'tags' => ['vip', 'wholesale'],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            1 => Http::response([
                'data' => [
                    'customerSet' => [
                        'customer' => [
                            'id' => 'gid://shopify/Customer/101',
                            'legacyResourceId' => '101',
                            'email' => 'ops-existing@example.com',
                            'firstName' => 'Ops',
                            'lastName' => 'Existing',
                            'phone' => null,
                            'tags' => ['vip', 'wholesale'],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
            2 => Http::response([
                'data' => [
                    'customers' => [
                        'edges' => [],
                    ],
                ],
            ], 200),
            3 => Http::response([
                'data' => [
                    'customerSet' => [
                        'customer' => [
                            'id' => 'gid://shopify/Customer/202',
                            'legacyResourceId' => '202',
                            'email' => 'ops-new@example.com',
                            'firstName' => 'Ops',
                            'lastName' => 'New',
                            'phone' => null,
                            'tags' => [],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
            4 => Http::response([
                'data' => [
                    'tagsAdd' => [
                        'node' => [
                            'id' => 'gid://shopify/Customer/202',
                            'legacyResourceId' => '202',
                            'email' => 'ops-new@example.com',
                            'firstName' => 'Ops',
                            'lastName' => 'New',
                            'phone' => null,
                            'tags' => ['wholesale'],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
            default => throw new \RuntimeException('Unexpected Shopify request during backfill test.'),
        };
    });

    $exit = Artisan::call('shopify:backfill-wholesale-approvals');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('examined=2');
    expect($output)->toContain('processed=2');
    expect($output)->toContain('created_tagged=1');
    expect($output)->toContain('already_tagged=1');
    expect($output)->toContain('failed=0');

    expect($requests)->toHaveCount(5);
    expect(CustomerAccessRequest::query()->where('status', 'pending')->count())->toBe(1);
});
